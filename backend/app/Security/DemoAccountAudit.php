<?php

namespace App\Security;

use App\Models\AuditLog;
use App\Models\User;
use App\Support\DemoCredentials;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Throwable;

/**
 * Finds the accounts that may still sign in with the shared demo password, and lets a security administrator suspend them
 * or give them a random password (their owner then uses "forgot password"). Each distinct stored hash is checked once —
 * the demo seeders reuse one hash for many accounts, so a scan costs a handful of bcrypt checks — and the result is cached.
 */
class DemoAccountAudit
{
    private const CACHE = 'security.demo_accounts';

    public function __construct(private readonly PasswordService $passwords, private readonly AuthSessions $sessions) {}

    /** @return list<string> ids of the accounts whose stored password is the demo password and was never changed */
    public function ids(bool $fresh = false): array
    {
        if ($fresh) {
            Cache::forget(self::CACHE);
        }

        return Cache::remember(self::CACHE, 600, function (): array {
            $ids = [];
            $verdict = [];
            User::query()->whereNotNull('password')->whereNull('password_changed_at')->select(['id', 'password'])->orderBy('id')
                ->chunk(500, function ($users) use (&$ids, &$verdict) {
                    foreach ($users as $u) {
                        $hash = (string) $u->getRawOriginal('password');
                        $verdict[$hash] ??= $this->matches($hash);
                        if ($verdict[$hash]) {
                            $ids[] = $u->id;
                        }
                    }
                });

            return $ids;
        });
    }

    /** @return list<array<string, mixed>> */
    public function list(User $viewer): array
    {
        $ids = $this->ids();

        return User::with('roles:id,slug,name_ar,name_en')->whereIn('id', $ids)->orderBy('email')->get()
            ->map(fn (User $u) => [
                'id' => $u->id, 'name' => $u->name, 'name_ar' => $u->name_ar, 'email' => $u->email, 'status' => $u->status ?? 'active',
                'roles' => $u->roles->map(fn ($r) => ['slug' => $r->slug, 'name_ar' => $r->name_ar, 'name_en' => $r->name_en])->values()->all(),
                'last_login_at' => $u->last_login_at?->toIso8601String(), 'is_you' => $u->id === $viewer->id,
            ])->values()->all();
    }

    /**
     * Suspends the accounts (and ends their sessions) or replaces their password with a random one. The person running it is
     * never included, and only accounts the scan found can be touched.
     *
     * @param  list<string>  $userIds
     * @return array{done: int, skipped_self: bool}
     */
    public function neutralise(?User $actor, array $userIds, string $mode, ?string $ip = null): array
    {
        $allowed = array_flip($this->ids());
        $skippedSelf = $actor && in_array($actor->id, $userIds, true);
        $targets = User::whereIn('id', array_values(array_filter($userIds, fn ($id) => isset($allowed[$id]) && $id !== $actor?->id)))->get();

        foreach ($targets as $u) {
            if ($mode === 'suspend') {
                $u->forceFill(['status' => 'suspended'])->save();
                $this->sessions->revokeAll($u, 'demo_password', $actor);
            } else {
                $this->passwords->set($u, Str::password(24));
            }
        }
        AuditLog::create(['user_id' => $actor?->id, 'action' => 'demo_accounts_neutralised', 'auditable_type' => User::class, 'auditable_id' => $actor?->id,
            'new_values' => ['mode' => $mode, 'count' => $targets->count(), 'user_ids' => $targets->pluck('id')->all()], 'ip_address' => $ip]);
        Cache::forget(self::CACHE);

        return ['done' => $targets->count(), 'skipped_self' => $skippedSelf];
    }

    private function matches(string $hash): bool
    {
        if ($hash === '') {
            return false;
        }
        try {
            return Hash::check(DemoCredentials::PASSWORD, $hash);
        } catch (Throwable) {
            return false;   // not a hash this application can read (an imported or placeholder value)
        }
    }
}
