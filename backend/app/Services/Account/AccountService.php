<?php

namespace App\Services\Account;

use App\Models\ProfileChangeRequest;
use App\Models\User;
use App\Services\NotificationService;
use App\Support\Nationalities;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/** "My account": the read-only profile, change requests from the user, and their review by an administrator. */
class AccountService
{
    public function __construct(private readonly NotificationService $notifications) {}

    /** @return array<string, mixed> */
    public function profile(User $user): array
    {
        $user->loadMissing(['roles', 'employee.school', 'employee.jobTitle', 'employee.department', 'employee.supervisor.user', 'employee.skills']);
        $employee = $user->employee;
        $locale = app()->getLocale() === 'en' ? 'en' : 'ar';
        $pending = ProfileChangeRequest::where('user_id', $user->id)->where('status', 'pending')->get()->keyBy('field');

        $sections = [];
        $missing = 0;
        foreach (AccountFields::GROUPS as $group => $title) {
            $fields = [];
            foreach (AccountFields::all() as $key => $_) {
                $f = AccountFields::field($key);
                if ($f['group'] !== $group || ($f['employee_only'] && ! $employee)) {
                    continue;
                }
                $v = AccountFields::read($key, $user, $employee, $locale);
                $isMissing = $v['value'] === null;
                $missing += $isMissing ? 1 : 0;
                $fields[] = [
                    'key' => $key, 'label' => $f['label'][$locale], 'type' => $f['type'], 'value' => $v['value'], 'display' => $v['display'], 'missing' => $isMissing,
                    'options' => $f['options'] ? collect($f['options'])->map(fn ($o, $k) => ['value' => $k, 'label' => $o[$locale]])->values() : null,
                    'pending' => ($p = $pending->get($key)) ? ['id' => $p->id, 'requested_value' => $p->requested_value, 'created_at' => $p->created_at->toIso8601String()] : null,
                ];
            }
            if ($fields) {
                $sections[] = ['key' => $group, 'title' => $title[$locale], 'fields' => $fields];
            }
        }

        return [
            'identity' => Nationalities::identity($user) + [
                'name' => $user->displayName(), 'email' => $user->email, 'employee_no' => $employee?->employee_no, 'status' => $employee?->status ?? $user->status,
                'subtitle' => $employee ? trim(($employee->jobTitle?->translate('name') ?? '').' · '.($employee->school?->translate('name') ?? ''), ' ·') : null,
                'supervisor' => $employee?->supervisor?->user?->displayName(),
            ],
            'sections' => $sections,
            'skills' => $employee ? $employee->skills->map(fn ($s) => ['name' => $s->translate('name'), 'level' => (int) $s->pivot->level])->values() : [],
            'account' => [
                'roles' => $user->roles->map(fn ($r) => $locale === 'en' ? $r->name_en : $r->name_ar)->values(), 'locale' => $user->locale,
                'member_since' => $user->created_at?->toDateString(), 'last_login_at' => $user->last_login_at?->toIso8601String(),
            ],
            'stats' => ['missing' => $missing, 'pending' => $pending->count()],
        ];
    }

    /** @param  array{field: string, kind?: ?string, requested_value: string, note?: ?string}  $data */
    public function request(User $user, array $data): ProfileChangeRequest
    {
        $f = AccountFields::field($data['field']);
        $user->loadMissing(['employee.school', 'employee.jobTitle', 'employee.department']);
        if (! $f || ($f['employee_only'] && ! $user->employee)) {
            throw ValidationException::withMessages(['field' => __('messages.account.unknown_field')]);
        }
        if (ProfileChangeRequest::where('user_id', $user->id)->where('field', $f['key'])->where('status', 'pending')->exists()) {
            throw ValidationException::withMessages(['field' => __('messages.account.already_pending')]);
        }

        $value = trim($data['requested_value']);
        $this->validateValue($f, $value);
        $current = AccountFields::read($f['key'], $user, $user->employee, app()->getLocale() === 'en' ? 'en' : 'ar');

        return ProfileChangeRequest::create([
            'user_id' => $user->id, 'status' => 'pending', 'applied' => false, 'field' => $f['key'], 'kind' => $data['kind'] ?? ($current['value'] === null ? 'missing' : 'wrong'),
            'current_value' => $current['display'], 'requested_value' => $value, 'note' => filled($data['note'] ?? null) ? mb_substr(trim($data['note']), 0, 500) : null,
        ]);
    }

    /** Approves a request; with `$apply` the new value is written to the profile (only for fields that allow it). */
    public function approve(ProfileChangeRequest $request, User $by, bool $apply, ?string $note = null): ProfileChangeRequest
    {
        abort_unless($request->status === 'pending', 422, __('messages.account.already_reviewed'));
        $f = AccountFields::field($request->field);
        $applied = false;
        if ($apply && $f && $f['auto'] && $f['column']) {
            $target = $f['source'] === 'user' ? $request->user : $request->user->employee;
            if ($target) {
                $value = $f['type'] === 'number' ? (float) $request->requested_value : $request->requested_value;
                $target->forceFill([$f['column'] => $value])->save();
                $applied = true;
            }
        }
        $request->update(['status' => 'approved', 'applied' => $applied, 'reviewed_by' => $by->id, 'reviewed_at' => now(), 'review_note' => $note]);
        $this->tell($request, 'profile.request_approved');

        return $request;
    }

    public function reject(ProfileChangeRequest $request, User $by, ?string $note = null): ProfileChangeRequest
    {
        abort_unless($request->status === 'pending', 422, __('messages.account.already_reviewed'));
        $request->update(['status' => 'rejected', 'reviewed_by' => $by->id, 'reviewed_at' => now(), 'review_note' => $note]);
        $this->tell($request, 'profile.request_rejected');

        return $request;
    }

    private function tell(ProfileChangeRequest $r, string $event): void
    {
        $f = AccountFields::field($r->field);
        $label = $f['label'] ?? ['ar' => $r->field, 'en' => $r->field];
        $ok = $event === 'profile.request_approved';
        $suffix = filled($r->review_note) ? ['ar' => ' — '.$r->review_note, 'en' => ' — '.$r->review_note] : ['ar' => '', 'en' => ''];
        $this->notifications->send(
            $r->user_id, $event,
            $ok ? ['ar' => 'تمت الموافقة على طلب تعديل بياناتك', 'en' => 'Your change request was approved'] : ['ar' => 'لم تتم الموافقة على طلب التعديل', 'en' => 'Your change request was not approved'],
            ['ar' => "طلب تعديل «{$label['ar']}»{$suffix['ar']}", 'en' => "Request to change \"{$label['en']}\"{$suffix['en']}"],
            ['route' => '/account'],
        );
    }

    private function validateValue(array $f, string $value): void
    {
        $fail = fn (string $msg) => throw ValidationException::withMessages(['requested_value' => $msg]);
        if ($value === '' || mb_strlen($value) > 255) {
            $fail(__('messages.account.invalid_value'));
        }
        match ($f['type']) {
            'select' => isset($f['options'][$value]) || $fail(__('messages.account.invalid_value')),
            'date' => (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) && Carbon::hasFormat($value, 'Y-m-d') && Carbon::parse($value)->lte(today())) || $fail(__('messages.account.invalid_value')),
            'number' => (is_numeric($value) && $value >= 0 && $value <= 60) || $fail(__('messages.account.invalid_value')),
            'email' => filter_var($value, FILTER_VALIDATE_EMAIL) || $fail(__('messages.account.invalid_value')),
            'phone' => preg_match('/^\+?[0-9 ]{6,20}$/', $value) || $fail(__('messages.account.invalid_value')),
            default => null,
        };
    }
}
