<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Security\DemoAccountAudit;
use Illuminate\Console\Command;

/** Lists the accounts that may still use the shared demo password; --suspend or --reset neutralises them (audited). */
class DemoAccounts extends Command
{
    protected $signature = 'tedc:demo-accounts {--suspend : Suspend them and end their sessions} {--reset : Give each a random password (owners use "forgot password")} {--except=* : E-mail addresses to leave untouched}';

    protected $description = 'Find accounts that may still sign in with the shared demo password, and optionally suspend or reset them';

    public function handle(DemoAccountAudit $audit): int
    {
        $ids = $audit->ids(true);
        $users = User::whereIn('id', $ids)->orderBy('email')->get(['id', 'email', 'status']);
        $this->table(['E-mail', 'Status'], $users->map(fn ($u) => [$u->email, $u->status ?? 'active'])->all());
        $this->line(count($ids).' account(s) may still use the demo password.');

        $mode = $this->option('suspend') ? 'suspend' : ($this->option('reset') ? 'reset' : null);
        if (! $mode || ! $ids) {
            return self::SUCCESS;
        }
        $except = array_map('strtolower', (array) $this->option('except'));
        $targets = $users->reject(fn ($u) => in_array(strtolower($u->email), $except, true))->pluck('id')->all();
        $result = $audit->neutralise(null, $targets, $mode);
        $this->info("{$result['done']} account(s) ".($mode === 'suspend' ? 'suspended' : 'given a random password').'.');

        return self::SUCCESS;
    }
}
