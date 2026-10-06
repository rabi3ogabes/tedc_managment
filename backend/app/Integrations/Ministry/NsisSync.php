<?php

namespace App\Integrations\Ministry;

use App\Integrations\IntegrationManager;
use App\Models\Employee;

/** NSIS: which grades and subjects each teacher teaches, for targeting. Only those two facts are stored — no student data is read. */
class NsisSync
{
    public function __construct(private readonly IntegrationManager $hub) {}

    /** @return array{updated: int, unmatched: int} */
    public function run(): array
    {
        $rows = $this->hub->call('nsis', 'fetch_teacher_assignments', function (array $s, $i) {
            if ($i->driver === 'fake') {
                return (array) (is_string($s['fake_teachers'] ?? null) ? json_decode($s['fake_teachers'], true) : ($s['fake_teachers'] ?? []));
            }

            return (array) ((new MinistryHttp($s))->get('/teachers')['data'] ?? []);
        });
        $out = ['updated' => 0, 'unmatched' => 0];
        foreach ($rows as $r) {
            $e = Employee::where('employee_no', (string) ($r['employee_no'] ?? ''))->first();
            if (! $e) {
                $out['unmatched']++;

                continue;
            }
            $e->forceFill(['grades_taught' => array_values(array_map('strval', (array) ($r['grades'] ?? []))), 'subjects' => array_values(array_map('strval', (array) ($r['subjects'] ?? [])))])->save();
            $out['updated']++;
        }
        $this->hub->markSynced('nsis');

        return $out;
    }
}
