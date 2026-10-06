<?php

namespace App\Security;

use App\Models\MfaChallenge;
use App\Models\MfaDevice;
use App\Models\User;
use App\Services\Channels\EmailSender;
use App\Services\Channels\SmsGateway;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/** Second factor: authenticator app (TOTP), a code by e-mail or SMS (Hudhud), one-time recovery codes and "remember this device". */
class MfaService
{
    public function __construct(private readonly TotpService $totp, private readonly SecurityPolicy $policy, private readonly EmailSender $email, private readonly SmsGateway $sms) {}

    /** The methods this person can use right now. @return list<string> */
    public function methods(User $user): array
    {
        $allowed = $this->policy->all()['mfa']['methods'];
        $out = [];
        if ($user->mfa_enabled && in_array('totp', $allowed, true)) {
            $out[] = 'totp';
        }
        if (in_array('email', $allowed, true) && filter_var($user->email, FILTER_VALIDATE_EMAIL)) {
            $out[] = 'email';
        }
        if (in_array('sms', $allowed, true) && $this->sms->normalise($user->phone)) {
            $out[] = 'sms';
        }

        return $out;
    }

    // ---- enrolment ---------------------------------------------------------------------------------

    /** Starts enrolment: a secret to scan, not yet in force. @return array{secret: string, otpauth_url: string} */
    public function setup(User $user): array
    {
        $secret = $this->totp->newSecret();
        $user->forceFill(['mfa_secret' => Crypt::encryptString($secret), 'mfa_enabled' => false])->saveQuietly();

        return ['secret' => $secret, 'otpauth_url' => $this->totp->otpauthUrl($secret, $user->email, (string) config('app.name', 'TEDC'))];
    }

    /** Confirms enrolment with the first code and returns the recovery codes (shown once). @return list<string> */
    public function confirm(User $user, string $code): array
    {
        $secret = $this->secret($user);
        if (! $secret || ! $this->totp->verify($secret, $code)) {
            throw ValidationException::withMessages(['code' => __('auth.mfa_invalid')]);
        }
        $codes = $this->recoveryCodes();
        $user->forceFill(['mfa_enabled' => true, 'mfa_confirmed_at' => now(), 'mfa_recovery' => array_map(fn ($c) => Hash::make($c), $codes)])->saveQuietly();

        return $codes;
    }

    /** Turns the second factor off (never for a role that requires it). */
    public function disable(User $user, string $code): void
    {
        if (array_intersect($user->roles->pluck('slug')->all(), $this->policy->all()['mfa']['enforce_roles']) !== []) {
            throw ValidationException::withMessages(['code' => __('auth.mfa_required_role')]);
        }
        $this->verifyAny($user, $code, 'totp');
        $user->forceFill(['mfa_enabled' => false, 'mfa_secret' => null, 'mfa_recovery' => null, 'mfa_confirmed_at' => null])->saveQuietly();
        MfaDevice::where('user_id', $user->id)->delete();
    }

    /** @return list<string> */
    public function regenerateRecovery(User $user): array
    {
        $codes = $this->recoveryCodes();
        $user->forceFill(['mfa_recovery' => array_map(fn ($c) => Hash::make($c), $codes)])->saveQuietly();

        return $codes;
    }

    // ---- verification ------------------------------------------------------------------------------

    /** Sends a code by e-mail or SMS. @return array{method: string, expires_in: int} */
    public function sendOtp(User $user, string $method): array
    {
        if (! in_array($method, ['email', 'sms'], true) || ! in_array($method, $this->methods($user), true)) {
            throw ValidationException::withMessages(['method' => __('auth.mfa_method')]);
        }
        $recent = MfaChallenge::where('user_id', $user->id)->where('created_at', '>', now()->subSeconds(60))->exists();
        if ($recent) {
            throw ValidationException::withMessages(['method' => __('auth.throttle', ['seconds' => 60])])->status(429);
        }
        $code = (string) random_int(100000, 999999);
        MfaChallenge::where('user_id', $user->id)->delete();
        MfaChallenge::create(['user_id' => $user->id, 'method' => $method, 'code_hash' => hash('sha256', $code), 'expires_at' => now()->addMinutes(10)]);
        $text = $user->locale === 'en' ? "Your verification code is {$code}. It expires in 10 minutes." : "رمز التحقق الخاص بك {$code}. تنتهي صلاحيته خلال ١٠ دقائق.";
        if ($method === 'email') {
            $this->email->send($user->email, $user->locale === 'en' ? 'Verification code' : 'رمز التحقق', $text, $user->locale === 'en' ? 'en' : 'ar');
        } else {
            $this->sms->send((string) $this->sms->normalise($user->phone), $text);
        }

        return ['method' => $method, 'expires_in' => 600];
    }

    /** Checks a code of the given method; a recovery code works for any. @throws ValidationException */
    public function verifyAny(User $user, string $code, string $method): void
    {
        $code = trim($code);
        if ($method === 'recovery') {
            if (! $this->useRecovery($user, $code)) {
                throw ValidationException::withMessages(['code' => __('auth.mfa_invalid')]);
            }

            return;
        }
        $ok = match ($method) {
            'totp' => ($s = $this->secret($user)) && $this->totp->verify($s, $code),
            'email', 'sms' => $this->checkChallenge($user, $method, $code),
            default => false,
        };
        if (! $ok) {
            throw ValidationException::withMessages(['code' => __('auth.mfa_invalid')]);
        }
    }

    // ---- remember this device ---------------------------------------------------------------------

    public function rememberDevice(User $user, ?string $label): ?string
    {
        $days = (int) $this->policy->all()['mfa']['remember_days'];
        if ($days <= 0) {
            return null;
        }
        $token = Str::random(48);
        MfaDevice::create(['user_id' => $user->id, 'token_hash' => hash('sha256', $token), 'label' => mb_substr((string) $label, 0, 120), 'expires_at' => now()->addDays($days)]);

        return $token;
    }

    public function trustedDevice(User $user, ?string $token): bool
    {
        if (! $token) {
            return false;
        }
        $d = MfaDevice::where('user_id', $user->id)->where('token_hash', hash('sha256', $token))->where('expires_at', '>', now())->first();
        $d?->forceFill(['last_used_at' => now()])->save();

        return (bool) $d;
    }

    // ---- internals ---------------------------------------------------------------------------------

    private function secret(User $user): ?string
    {
        if (! $user->mfa_secret) {
            return null;
        }
        try {
            return Crypt::decryptString($user->mfa_secret);
        } catch (Throwable) {
            return null;
        }
    }

    private function checkChallenge(User $user, string $method, string $code): bool
    {
        $c = MfaChallenge::where('user_id', $user->id)->where('method', $method)->latest()->first();
        if (! $c || $c->expires_at->isPast()) {
            throw ValidationException::withMessages(['code' => __('auth.mfa_expired')]);
        }
        if ($c->attempts >= 5) {
            throw ValidationException::withMessages(['code' => __('auth.mfa_locked')])->status(429);
        }
        $c->increment('attempts');
        if (! hash_equals($c->code_hash, hash('sha256', $code))) {
            return false;
        }
        $c->delete();

        return true;
    }

    private function useRecovery(User $user, string $code): bool
    {
        $codes = $user->mfa_recovery ?? [];
        foreach ($codes as $i => $hash) {
            if (Hash::check($code, $hash)) {
                unset($codes[$i]);
                $user->forceFill(['mfa_recovery' => array_values($codes)])->saveQuietly();

                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    private function recoveryCodes(): array
    {
        return array_map(fn () => strtolower(Str::random(5).'-'.Str::random(5)), range(1, 10));
    }
}
