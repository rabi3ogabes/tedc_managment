<?php

namespace App\Services;

use App\Models\ErrorLog;
use App\Models\GroupTrainer;
use App\Models\ProfileChangeRequest;
use App\Models\ProgramSession;
use App\Models\Registration;
use App\Models\User;

/**
 * The action tiles of the app's home screen: what is waiting for this person, with the number. Each tile belongs to a
 * permission, so a person only ever sees counts of things they can act on, and a tile with nothing waiting is left out.
 */
class AppActions
{
    /** @return array<string, array{permission: string, label: array{ar: string, en: string}, icon: string, tone: string, route: ?string, web: ?string}> */
    public static function catalog(): array
    {
        return [
            'approvals_manager' => ['permission' => 'registrations.approve_manager|registrations.manage', 'label' => ['ar' => 'طلبات تنتظر اعتمادك', 'en' => 'Requests waiting for your approval'], 'icon' => 'approval', 'tone' => 'gold', 'route' => '/approvals', 'web' => null],
            'approvals_center' => ['permission' => 'registrations.approve_center|registrations.manage', 'label' => ['ar' => 'تسجيلات بانتظار المركز', 'en' => 'Registrations waiting for the centre'], 'icon' => 'how_to_reg', 'tone' => 'primary', 'route' => null, 'web' => '/admin/approvals'],
            'profile_requests' => ['permission' => 'employees.manage', 'label' => ['ar' => 'طلبات تعديل بيانات', 'en' => 'Data change requests'], 'icon' => 'edit_note', 'tone' => 'primary', 'route' => null, 'web' => '/admin/profile-requests'],
            'assignments' => ['permission' => 'trainers.respond', 'label' => ['ar' => 'تكليفات تنتظر ردّك', 'en' => 'Assignments waiting for your reply'], 'icon' => 'assignment_ind', 'tone' => 'gold', 'route' => '/assignments', 'web' => null],
            'errors' => ['permission' => 'logs.manage', 'label' => ['ar' => 'أخطاء مفتوحة في النظام', 'en' => 'Open system errors'], 'icon' => 'bug_report', 'tone' => 'danger', 'route' => null, 'web' => '/admin/error-log'],
        ];
    }

    private function can(User $user, string $permissions): bool
    {
        foreach (explode('|', $permissions) as $p) {
            if ($user->hasPermission($p)) {
                return true;
            }
        }

        return false;
    }

    private function count(string $key, User $user): int
    {
        return match ($key) {
            'approvals_manager' => Registration::where('status', Registration::STATUS_PENDING_MANAGER)->where('manager_id', $user->id)->count(),
            'approvals_center' => Registration::where('status', Registration::STATUS_PENDING)->count(),
            'profile_requests' => ProfileChangeRequest::where('status', 'pending')->count(),
            'assignments' => $user->trainer ? GroupTrainer::where('trainer_id', $user->trainer->id)->where('status', GroupTrainer::PROPOSED)->count() : 0,
            'errors' => ErrorLog::where('status', 'open')->count(),
            default => 0,
        };
    }

    /**
     * The tiles switched on for the role that have something waiting, in the administrator's order.
     *
     * @param  list<string>  $enabled
     * @return list<array<string, mixed>>
     */
    public function tiles(User $user, array $enabled): array
    {
        $catalog = self::catalog();
        $out = [];
        foreach ($enabled as $key) {
            $c = $catalog[$key] ?? null;
            if (! $c || ! $this->can($user, $c['permission'])) {
                continue;
            }
            $n = $this->count($key, $user);
            if ($n > 0) {
                $out[] = ['key' => $key, 'count' => $n, 'label' => $c['label'], 'icon' => $c['icon'], 'tone' => $c['tone'], 'route' => $c['route'], 'web' => $c['web']];
            }
        }

        return $out;
    }

    /**
     * The sessions of today: a trainer's own, or the centre's for people who manage programs. @return list<array<string, mixed>>
     */
    public function today(User $user): array
    {
        $q = ProgramSession::with(['program:id,title_ar,title_en', 'room'])->whereBetween('starts_at', [today(), today()->endOfDay()])->where('status', '!=', 'cancelled')->orderBy('starts_at')->limit(8);
        if ($user->trainer && ! $user->hasPermission('programs.manage')) {
            $q->where('trainer_id', $user->trainer->id);
        } elseif (! $user->hasPermission('programs.view')) {
            return [];
        }

        return $q->get()->map(fn (ProgramSession $s) => [
            'id' => $s->id, 'title' => $s->translate('title'), 'program' => $s->program?->translate('title'), 'starts_at' => $s->starts_at->toIso8601String(), 'ends_at' => $s->ends_at->toIso8601String(),
            'location' => $s->location_text ?? $s->room?->translate('name'), 'mode' => $s->mode,
        ])->all();
    }
}
