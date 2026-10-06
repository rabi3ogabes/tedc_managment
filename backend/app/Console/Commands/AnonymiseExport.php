<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\User;
use App\Ops\Anonymiser;
use App\Security\SecurityEvents;
use Illuminate\Console\Command;

class AnonymiseExport extends Command
{
    protected $signature = 'tedc:anonymise-export {--approved-by= : e-mail of the person approving (needs the security.policy permission)} {--reason= : why the copy is needed} {--tables= : comma-separated tables (default all)} {--out= : directory (default storage/app/anonymised/<time>)} {--confirm : really run}';

    protected $description = 'Write a masked copy of the data for an approved investigation; production data never reaches other environments unmasked (Phase 17)';

    public function handle(Anonymiser $anon): int
    {
        $reason = trim((string) $this->option('reason'));
        $approver = User::whereRaw('lower(email) = ?', [strtolower((string) $this->option('approved-by'))])->first();
        if (mb_strlen($reason) < 10 || ! $approver || ! $approver->hasPermission('security.policy')) {
            $this->error('A written reason (10+ characters) and an approver with the security.policy permission are required.');

            return self::FAILURE;
        }
        if (! $this->option('confirm')) {
            $this->warn('Dry run only. Add --confirm to write the masked files.');

            return self::SUCCESS;
        }
        $dir = $this->option('out') ?: storage_path('app/anonymised/'.now()->format('Ymd-His'));
        $manifest = $anon->export($dir, $this->option('tables') ? array_map('trim', explode(',', (string) $this->option('tables'))) : null);
        file_put_contents($dir.'/MANIFEST.json', json_encode(['created_at' => now()->toIso8601String(), 'reason' => $reason, 'approved_by' => $approver->email, 'tables' => $manifest, 'masking' => 'restricted dropped; confidential pseudonymised (per-export key); other kept'], JSON_PRETTY_PRINT));
        AuditLog::create(['user_id' => $approver->id, 'action' => 'anonymised_export', 'auditable_type' => User::class, 'auditable_id' => $approver->id, 'new_values' => ['reason' => $reason, 'tables' => array_keys($manifest), 'rows' => array_sum(array_column($manifest, 'rows')), 'dir' => basename($dir)]]);
        SecurityEvents::record('admin_action', $approver, 'ok', ['action' => 'anonymised_export', 'tables' => count($manifest)]);
        $this->info('Written '.count($manifest).' tables to '.$dir);

        return self::SUCCESS;
    }
}
