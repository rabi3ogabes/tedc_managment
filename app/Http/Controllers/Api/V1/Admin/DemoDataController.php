<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use App\Security\PasswordService;
use App\Services\Admin\DemoPurge;
use App\Services\FileStorage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Settings → Data: removing the demo data once the real people are in. First a preview (what goes, what stays); then, after the administrator
 * types the confirmation, a backup of every deleted row to private storage, the clean-up, and the one administrator who stays — with the
 * password typed here, so they can sign in at once. Super administrators only.
 */
class DemoDataController extends Controller
{
    public const CONFIRMATION = 'حذف البيانات التجريبية';

    public function __construct(private readonly DemoPurge $purge, private readonly FileStorage $storage) {}

    public function show(): JsonResponse
    {
        $this->guard();
        $plan = $this->purge->forAdmin($this->user()->email)->plan();

        return response()->json(['data' => [
            'purged' => $this->purge->done(),
            'imported_people' => $this->purge->importedPeople(),
            'total' => array_sum($plan),
            'tables' => collect($plan)->map(fn ($n, $t) => ['table' => $t, 'rows' => $n])->values()->take(40),
            'table_count' => count($plan),
            'kept' => $this->purge->kept(),
            'confirmation' => self::CONFIRMATION,
        ]]);
    }

    public function purge(Request $request, PasswordService $passwords): JsonResponse
    {
        $this->guard();
        @set_time_limit(300);
        $data = $request->validate([
            'admin_name' => ['required', 'string', 'max:120'],
            'admin_email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'confirmed'],
            'confirmation' => ['required', 'string'],
        ]);
        if (trim($data['confirmation']) !== self::CONFIRMATION) {
            throw ValidationException::withMessages(['confirmation' => __('messages.demo_purge.confirmation', ['phrase' => self::CONFIRMATION])]);
        }
        abort_if($this->purge->importedPeople() === 0, 422, __('messages.demo_purge.nothing_imported'));
        $email = strtolower(trim($data['admin_email']));
        $passwords->assert($data['password'], User::where('email', $email)->first());

        $this->purge->forAdmin($email);
        $tables = array_keys($plan = $this->purge->plan());

        // Every row that goes is kept first, in private storage.
        $local = tempnam(sys_get_temp_dir(), 'demo-purge').'.jsonl.gz';
        $rows = $this->purge->backup($local, $tables);
        $backupPath = 'backups/demo-purge-'.now()->format('Ymd-His').'.jsonl.gz';
        $this->storage->put('documents', $backupPath, (string) file_get_contents($local), 'application/gzip');
        @unlink($local);

        $actor = $this->user();
        $admin = $this->purge->ensureAdmin(trim($data['admin_name']), $data['password']);
        $result = $this->purge->purge($tables);
        if ($result['left'] !== []) {
            return response()->json(['message' => __('messages.demo_purge.incomplete', ['tables' => implode(', ', $result['left'])]), 'details' => $result['messages']], 500);
        }
        // The person who ran it may have been a demo account, now gone: whatever is recorded from here on is the administrator who stays.
        Auth::setUser($admin);
        $this->purge->switchOffDemoFeatures($admin);
        try {
            $supabase = $this->purge->removeFromSupabase($result['removed_emails']);
        } catch (Throwable $e) {
            report($e);
            $supabase = null;
        }
        AuditLog::create(['user_id' => $admin->id, 'action' => 'demo_data_purged', 'auditable_type' => User::class, 'auditable_id' => $admin->id,
            'new_values' => ['rows' => array_sum($plan), 'tables' => count($plan), 'backup' => $backupPath, 'backup_rows' => $rows, 'supabase_removed' => $supabase]]);

        return response()->json(['data' => [
            'removed_rows' => array_sum($plan), 'tables' => count($plan), 'backup' => ['path' => $backupPath, 'rows' => $rows],
            'admin' => ['email' => $admin->email, 'name' => $admin->name], 'signed_out' => $actor->id !== $admin->id, 'kept' => $this->purge->kept(),
        ]]);
    }

    private function guard(): void
    {
        abort_unless($this->user()->hasRole(Role::SUPER_ADMIN), 403, __('messages.imports.super_admin_only'));
    }
}
