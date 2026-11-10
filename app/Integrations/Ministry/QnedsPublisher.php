<?php

namespace App\Integrations\Ministry;

use App\Integrations\Contracts\ContractGateway;
use App\Integrations\IntegrationManager;
use App\Models\Certificate;
use App\Models\Registration;
use App\Models\School;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** QNEDS: publishes aggregated training indicators per school (no names, no personal data). */
class QnedsPublisher
{
    public function __construct(private readonly IntegrationManager $hub, private readonly ContractGateway $contracts) {}

    /** @return array{schools: int} */
    public function publish(?int $year = null): array
    {
        $year ??= (int) now()->year;
        $from = "{$year}-01-01";
        $to = "{$year}-12-31 23:59:59";
        $participants = DB::table('registrations as r')->join('employees as e', 'e.id', '=', 'r.employee_id')->whereIn('r.status', [Registration::STATUS_APPROVED, Registration::STATUS_COMPLETED])->whereBetween('r.created_at', [$from, $to])->groupBy('e.school_id')->selectRaw('e.school_id, count(distinct r.employee_id) as n')->pluck('n', 'school_id');
        $completed = DB::table('registrations as r')->join('employees as e', 'e.id', '=', 'r.employee_id')->where('r.status', Registration::STATUS_COMPLETED)->whereBetween('r.created_at', [$from, $to])->groupBy('e.school_id')->selectRaw('e.school_id, count(*) as n')->pluck('n', 'school_id');
        $hours = Certificate::join('employees as e', 'e.id', '=', 'certificates.employee_id')->where('certificates.status', 'valid')->whereBetween('certificates.issued_at', [$from, $to])->groupBy('e.school_id')->selectRaw('e.school_id, sum(certificates.hours) as h')->pluck('h', 'school_id');
        $schools = School::whereIn('id', collect($participants->keys())->merge($hours->keys())->unique())->get(['id', 'code', 'moe_no']);
        $items = $schools->map(fn ($s) => ['school_code' => $s->moe_no ?: $s->code, 'year' => $year, 'trained_staff' => (int) ($participants[$s->id] ?? 0), 'completed_programs' => (int) ($completed[$s->id] ?? 0), 'training_hours' => round((float) ($hours[$s->id] ?? 0), 1)])->values()->all();

        $publication = (string) Str::uuid();   // one publication: its retries carry the same key, a later publication a new one
        $this->hub->call('qneds', 'publish_indicators', fn (array $s, $i) => $i->driver === 'fake'
            ? ['accepted' => count($items)]
            : ($this->contracts->call('qneds', 'publish_indicators', ['year' => $year, 'items' => $items], $s, ['idempotency_key' => $publication])->json ?? []), ['year' => $year, 'schools' => count($items)]);
        $this->hub->markSynced('qneds');

        return ['schools' => count($items)];
    }
}
