<?php

namespace App\Security;

use App\Models\PasswordHistory;
use App\Models\User;
use App\Support\Supabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Throwable;

/** Checks a new password against the policy (length, character classes, history, optionally a breach list) and records it. */
class PasswordService
{
    public function __construct(private readonly SecurityPolicy $policy) {}

    /** @return list<string> the rules the password breaks, as translation keys */
    public function violations(string $password, ?User $for = null): array
    {
        $p = $this->policy->all()['password'];
        $out = [];
        if (mb_strlen($password) < $p['min_length']) {
            $out[] = 'min_length';
        }
        if ($p['upper'] && ! preg_match('/\p{Lu}/u', $password)) {
            $out[] = 'upper';
        }
        if ($p['lower'] && ! preg_match('/\p{Ll}/u', $password)) {
            $out[] = 'lower';
        }
        if ($p['digit'] && ! preg_match('/\d/', $password)) {
            $out[] = 'digit';
        }
        if ($p['symbol'] && ! preg_match('/[^\p{L}\d]/u', $password)) {
            $out[] = 'symbol';
        }
        if ($for) {
            foreach (PasswordHistory::where('user_id', $for->id)->latest('created_at')->limit(max(0, (int) $p['history']))->pluck('hash') as $hash) {
                if (Hash::check($password, $hash)) {
                    $out[] = 'reused';
                    break;
                }
            }
            if ($for->password && Hash::check($password, $for->password) && ! in_array('reused', $out, true)) {
                $out[] = 'reused';
            }
        }
        if ($p['breached_check'] && $this->breached($password)) {
            $out[] = 'breached';
        }

        return $out;
    }

    /** @throws ValidationException */
    public function assert(string $password, ?User $for = null, string $field = 'password'): void
    {
        $v = $this->violations($password, $for);
        if ($v) {
            throw ValidationException::withMessages([$field => array_map(fn ($k) => __('auth.pw.'.$k, ['min' => $this->policy->all()['password']['min_length']]), $v)]);
        }
    }

    /** Stores the new password for a local account (and in Supabase when that is the identity store) and remembers it. */
    public function set(User $user, string $password): void
    {
        $user->forceFill(['password' => $password, 'password_changed_at' => now(), 'failed_attempts' => 0, 'locked_until' => null])->save();
        PasswordHistory::create(['user_id' => $user->id, 'hash' => $user->password, 'created_at' => now()]);
        $keep = max(1, (int) $this->policy->all()['password']['history']);
        $old = PasswordHistory::where('user_id', $user->id)->latest('created_at')->skip($keep)->take(100)->pluck('id');
        PasswordHistory::whereIn('id', $old)->delete();
        if (config('tedc.auth.driver') === 'supabase' && $user->auth_id) {
            Supabase::admin()->put("/admin/users/{$user->auth_id}", ['password' => $password])->throw();
        }
    }

    /** Whether the password has expired under the policy. */
    public function expired(User $user): bool
    {
        $days = (int) $this->policy->all()['password']['expiry_days'];

        return $days > 0 && $user->password && $user->password_changed_at !== null && $user->password_changed_at->lt(now()->subDays($days));
    }

    /** The k-anonymity range query of a public breach list: only the first five characters of the SHA-1 leave the server. */
    private function breached(string $password): bool
    {
        try {
            $sha = strtoupper(sha1($password));
            $res = Http::timeout(3)->withHeaders(['Add-Padding' => 'true'])->get('https://api.pwnedpasswords.com/range/'.substr($sha, 0, 5));
            if (! $res->successful()) {
                return false;   // the check is a courtesy; an unreachable list never blocks a password
            }
            foreach (preg_split('/\R/', $res->body()) ?: [] as $line) {
                [$suffix, $count] = array_pad(explode(':', trim($line)), 2, '0');
                if (strcasecmp($suffix, substr($sha, 5)) === 0 && (int) $count > 0) {
                    return true;
                }
            }
        } catch (Throwable) {
            return false;
        }

        return false;
    }
}
