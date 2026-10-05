<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\Employee;
use App\Models\RegistrationForm;
use App\Models\RegistrationRequest;
use App\Models\Role;
use App\Models\Trainer;
use App\Models\User;
use App\Services\Channels\EmailSender;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Mpdf\Mpdf;

/** People outside the Ministry: a shareable public form, e-mail verification, review, account creation and an activation link. */
class ExternalRegistrationService
{
    public function __construct(private readonly EmailSender $mail, private readonly NotificationService $notifications, private readonly FileStorage $files) {}

    public function assertAvailable(RegistrationForm $form): void
    {
        if (! $form->is_active || ($form->opens_at && $form->opens_at->isFuture()) || ($form->closes_at && $form->closes_at->isPast())) {
            throw new BusinessRuleException(__('messages.external.closed'), 'form_closed');
        }
    }

    public function sendCode(RegistrationForm $form, string $email): void
    {
        $this->assertAvailable($form);
        $this->assertDomain($form, $email);
        $code = (string) random_int(100000, 999999);
        Cache::put($this->codeKey($form, $email), hash('sha256', $code), now()->addMinutes(15));
        $this->mail->send($email, 'رمز التحقق · Verification code', "رمز التحقق الخاص بك: {$code}\nYour verification code: {$code}\n(15 min / ١٥ دقيقة)");
    }

    public function submit(RegistrationForm $form, string $email, string $code, array $data): RegistrationRequest
    {
        $this->assertAvailable($form);
        $email = strtolower(trim($email));
        $this->assertDomain($form, $email);
        $stored = Cache::get($this->codeKey($form, $email));
        if (! $stored || ! hash_equals($stored, hash('sha256', trim($code)))) {
            throw new BusinessRuleException(__('messages.external.code_invalid'), 'code_invalid');
        }
        $clean = $this->validateFields($form, $data);
        $nid = collect($form->fields)->firstWhere('type', 'national_id');
        $nationalHash = $nid && ! empty($clean[$nid['key']]) ? hash('sha256', config('app.key').'|'.preg_replace('/\D+/', '', (string) $clean[$nid['key']])) : null;
        $phone = collect($form->fields)->firstWhere('type', 'phone');
        $phoneValue = $phone ? ($clean[$phone['key']] ?? null) : null;

        if (User::where('email', $email)->exists()) {
            throw new BusinessRuleException(__('messages.external.account_exists'), 'account_exists');
        }
        $open = RegistrationRequest::whereIn('status', ['submitted', 'needs_info', 'approved'])->where(fn ($q) => $q->where('email', $email)->when($nationalHash, fn ($w) => $w->orWhere('national_id_hash', $nationalHash)))->first();
        if ($open && $open->status !== 'needs_info') {
            throw new BusinessRuleException(__('messages.external.duplicate'), 'duplicate_request');
        }

        $request = DB::transaction(function () use ($form, $email, $clean, $nationalHash, $phoneValue, $open) {
            $attrs = ['data' => $clean, 'email' => $email, 'phone' => $phoneValue, 'national_id_hash' => $nationalHash, 'email_verified_at' => now(), 'status' => 'submitted'];
            if ($open) {
                $open->update($attrs);

                return $open;
            }

            return RegistrationRequest::create($attrs + ['form_id' => $form->id, 'number' => $this->nextNumber()]);
        });
        Cache::forget($this->codeKey($form, $email));

        $request->update(['snapshot_path' => $this->snapshot($form, $request)]);
        $reviewers = User::whereHas('roles.permissions', fn ($q) => $q->where('slug', 'external_requests.review'))->pluck('id')->all();
        $reviewers && $this->notifications->broadcast($reviewers, 'external_request.submitted', ['ar' => 'طلب تسجيل خارجي جديد', 'en' => 'New external registration request'],
            ['ar' => "طلب {$request->number} من {$email} على نموذج «{$form->title_ar}».", 'en' => "Request {$request->number} from {$email} on \"{$form->title_en}\"."], ['detail_ar' => "طلب {$request->number} من {$email}", 'detail_en' => "Request {$request->number} from {$email}", 'route' => '/admin/approvals']);
        $this->mail->send($email, 'استلمنا طلبك · We received your request', "رقم طلبك: {$request->number}\nسنراجعه ونراسلك بالنتيجة.\n\nYour request number: {$request->number}\nWe will review it and email you the result.");

        return $request;
    }

    public function approve(RegistrationRequest $request, User $by): RegistrationRequest
    {
        $this->expectOpen($request);
        $form = $request->form;
        $data = $request->data;
        $nameEn = $data['name_en'] ?? $data['name'] ?? $request->email;
        $nameAr = $data['name_ar'] ?? $nameEn;

        $user = DB::transaction(function () use ($request, $form, $data, $nameEn, $nameAr) {
            $user = User::create(['name' => $nameEn, 'name_ar' => $nameAr, 'email' => $request->email, 'phone' => $request->phone, 'password' => Str::random(40), 'status' => 'active']);
            $user->roles()->attach(Role::where('slug', $form->audience === 'trainer' ? Role::TRAINER : Role::EMPLOYEE)->value('id'));
            if ($form->audience === 'trainer') {
                Trainer::create(['user_id' => $user->id, 'name_ar' => $nameAr, 'name_en' => $nameEn, 'email' => $request->email, 'phone' => $request->phone, 'status' => 'active', 'source' => 'external', 'is_external' => true, 'organization' => $data['organization'] ?? null]);
            } else {
                Employee::create(['user_id' => $user->id, 'employee_no' => 'EXT-'.strtoupper(Str::random(8)), 'status' => 'active']);
            }

            return $user;
        });
        $request->update(['status' => 'approved', 'reviewer_id' => $by->id, 'user_id' => $user->id, 'decision_note' => null]);

        $token = Password::broker()->createToken($user);
        $link = rtrim((string) config('tedc.web_url'), '/').'/activate?email='.urlencode($user->email).'&token='.$token;
        $this->mail->send($user->email, 'تم قبول طلبك · Your request was approved', "تم قبول طلبك ({$request->number}). فعّل حسابك وعيّن كلمة المرور من الرابط:\n{$link}\n\nYour request ({$request->number}) was approved. Activate your account and set a password:\n{$link}");

        return $request;
    }

    public function reject(RegistrationRequest $request, User $by, ?string $note): RegistrationRequest
    {
        $this->expectOpen($request);
        if (! filled($note)) {
            throw new BusinessRuleException(__('messages.assignment.reason_required'), 'reason_required');
        }
        $request->update(['status' => 'rejected', 'reviewer_id' => $by->id, 'decision_note' => $note]);
        $this->mail->send($request->email, 'نتيجة طلبك · Your request result', "نأسف، لم يُقبل طلبك ({$request->number}). السبب: {$note}\n\nSorry, your request ({$request->number}) was not accepted. Reason: {$note}");

        return $request;
    }

    public function requestInfo(RegistrationRequest $request, User $by, ?string $note): RegistrationRequest
    {
        $this->expectOpen($request);
        if (! filled($note)) {
            throw new BusinessRuleException(__('messages.assignment.reason_required'), 'reason_required');
        }
        $request->update(['status' => 'needs_info', 'reviewer_id' => $by->id, 'decision_note' => $note]);
        $link = rtrim((string) config('tedc.web_url'), '/').'/join/'.$request->form->slug;
        $this->mail->send($request->email, 'نحتاج معلومات إضافية · More information needed', "طلبك ({$request->number}) يحتاج: {$note}\nأعد التقديم من: {$link}\n\nYour request ({$request->number}) needs: {$note}\nResubmit at: {$link}");

        return $request;
    }

    private function expectOpen(RegistrationRequest $r): void
    {
        if (! in_array($r->status, ['submitted', 'needs_info'], true)) {
            throw new BusinessRuleException(__('messages.external.decided'), 'request_decided');
        }
    }

    private function assertDomain(RegistrationForm $form, string $email): void
    {
        $allowed = array_map('strtolower', $form->conditions['allowed_email_domains'] ?? []);
        if ($allowed && ! in_array(strtolower(substr(strrchr($email, '@') ?: '', 1)), $allowed, true)) {
            throw new BusinessRuleException(__('messages.external.domain', ['domains' => implode(', ', $allowed)]), 'email_domain_not_allowed');
        }
    }

    private function validateFields(RegistrationForm $form, array $data): array
    {
        $errors = [];
        $clean = [];
        foreach ($form->fields as $f) {
            $key = $f['key'];
            $v = $data[$key] ?? null;
            $empty = $v === null || $v === '' || $v === [];
            if ($empty) {
                if ($f['required'] ?? false) {
                    $errors["data.{$key}"] = __('validation.required', ['attribute' => $f['label_'.app()->getLocale()] ?? $key]);
                }

                continue;
            }
            $ok = match ($f['type']) {
                'email' => filter_var($v, FILTER_VALIDATE_EMAIL) !== false,
                'phone' => (bool) preg_match('/^\+?[0-9 ()-]{7,20}$/', (string) $v),
                'number' => is_numeric($v),
                'date' => strtotime((string) $v) !== false,
                'national_id' => (bool) preg_match('/^[0-9]{6,20}$/', (string) $v),
                'select' => in_array($v, $f['options'] ?? [], true),
                'multiselect' => is_array($v) && ! array_diff($v, $f['options'] ?? []),
                default => is_string($v) && mb_strlen($v) <= 5000,
            };
            if (! $ok) {
                $errors["data.{$key}"] = __('validation.invalid', ['attribute' => $f['label_'.app()->getLocale()] ?? $key]);

                continue;
            }
            $clean[$key] = is_string($v) ? trim($v) : $v;
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        return $clean;
    }

    private function nextNumber(): string
    {
        $prefix = 'REQ-'.now()->format('y').'-';
        $n = RegistrationRequest::where('number', 'like', $prefix.'%')->count() + 1;
        while (RegistrationRequest::where('number', $prefix.str_pad((string) $n, 4, '0', STR_PAD_LEFT))->exists()) {
            $n++;
        }

        return $prefix.str_pad((string) $n, 4, '0', STR_PAD_LEFT);
    }

    /** The immutable official record of what the applicant submitted. */
    private function snapshot(RegistrationForm $form, RegistrationRequest $r): string
    {
        $rows = '';
        foreach ($form->fields as $f) {
            $v = $r->data[$f['key']] ?? '';
            $rows .= '<tr><td style="border:1px solid #bbb;padding:6px;width:35%">'.e($f['label_ar']).' / '.e($f['label_en']).'</td><td style="border:1px solid #bbb;padding:6px">'.e(is_array($v) ? implode(', ', $v) : (string) $v).'</td></tr>';
        }
        $html = '<div dir="rtl" style="font-family:dejavusans"><h2>'.e($form->title_ar).' — '.e($form->title_en).'</h2><p>'.e($r->number).' · '.e($r->email).' · '.now()->toDateTimeString().'</p><table style="width:100%;border-collapse:collapse">'.$rows.'</table></div>';
        $mpdf = new Mpdf(['mode' => 'utf-8', 'format' => 'A4', 'default_font' => 'dejavusans', 'tempDir' => storage_path('app/mpdf'), 'autoScriptToLang' => true, 'autoLangToFont' => true]);
        $mpdf->WriteHTML($html);

        return $this->files->put('documents', 'external-requests/'.$r->number.'.pdf', $mpdf->Output('', 'S'), 'application/pdf');
    }

    private function codeKey(RegistrationForm $form, string $email): string
    {
        return 'ext-code:'.$form->id.':'.hash('sha256', strtolower(trim($email)));
    }
}
