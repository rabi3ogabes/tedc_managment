<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Program;
use App\Models\ProgramGrant;
use App\Models\User;
use Illuminate\Http\Request;

/** Rights on one program given to a person by the head of training: marking attendance, notifying trainees, reviewing tasks, assigning kits. */
class ProgramGrantService
{
    public function allows(User $user, Program|string $program, string $ability): bool
    {
        $programId = $program instanceof Program ? $program->id : $program;

        return ProgramGrant::where('user_id', $user->id)->where('program_id', $programId)->where('ability', $ability)
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))->exists();
    }

    /** True when the user holds this ability on at least one program (enough to reach the screens that then check the program). */
    public function holdsAny(User $user, string $ability): bool
    {
        return ProgramGrant::where('user_id', $user->id)->where('ability', $ability)
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))->exists();
    }

    /** @return array{0: ProgramGrant, 1: bool} the grant and whether it was newly created */
    public function grant(Program $program, User $user, string $ability, User $by, ?string $expiresAt, ?Request $request = null): array
    {
        $grant = ProgramGrant::where(['program_id' => $program->id, 'user_id' => $user->id, 'ability' => $ability])->first();
        $created = ! $grant;
        $grant ??= new ProgramGrant(['program_id' => $program->id, 'user_id' => $user->id, 'ability' => $ability]);
        $grant->fill(['granted_by' => $by->id, 'expires_at' => $expiresAt])->save();
        $this->audit('program_grant_added', $by, $program, ['user' => $user->email, 'ability' => $ability, 'expires_at' => $expiresAt], $request);

        return [$grant, $created];
    }

    public function revoke(ProgramGrant $grant, User $by, ?Request $request = null): void
    {
        $this->audit('program_grant_revoked', $by, $grant->program, ['user_id' => $grant->user_id, 'ability' => $grant->ability], $request);
        $grant->delete();
    }

    /** @param  array<string, mixed>  $values */
    private function audit(string $action, User $by, Program $program, array $values, ?Request $request): void
    {
        AuditLog::create(['user_id' => $by->id, 'action' => $action, 'auditable_type' => Program::class, 'auditable_id' => $program->id, 'new_values' => $values, 'ip_address' => $request?->ip(), 'user_agent' => $request ? substr((string) $request->userAgent(), 0, 255) : null, 'url' => $request?->fullUrl()]);
    }
}
