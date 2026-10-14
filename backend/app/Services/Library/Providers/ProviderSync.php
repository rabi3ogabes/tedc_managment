<?php

namespace App\Services\Library\Providers;

use App\Integrations\Contracts\ContractGateway;
use App\Integrations\IntegrationManager;
use App\Models\Employee;
use App\Models\ExternalCompletionIssue;
use App\Models\ExternalCourse;
use App\Models\ExternalSyncRun;
use App\Models\Registration;
use App\Models\User;
use App\Services\Library\ExternalLearningService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Throwable;

/**
 * One reading of the learning providers that are switched on: the catalogue (everything the first time and once a week, the changes after
 * that), then the completions (a few days back, or as far back as the last good reading was). Each reading is recorded; a completion that
 * cannot be used is kept with its reason; one provider failing never stops another.
 */
final class ProviderSync
{
    /** After this many days the whole catalogue is read again, whatever the provider says it changed. */
    private const FULL_EVERY_DAYS = 7;

    /** The most people asked about one by one in a reading (a provider that cannot report for everyone). */
    private const MAX_PEOPLE = 1000;

    public function __construct(private readonly ExternalLearningService $learning, private readonly IntegrationManager $hub, private readonly ContractGateway $contracts) {}

    /**
     * @return array{courses: int, completed: int, errors: list<string>, providers: array<string, array<string, mixed>>}
     */
    public function run(?string $only = null, bool $full = false, string $trigger = 'schedule', ?User $by = null, bool $catalogue = true): array
    {
        $out = ['courses' => 0, 'completed' => 0, 'errors' => [], 'providers' => []];
        foreach (ExternalLearningService::PROVIDERS as $provider) {
            if ($only !== null && $only !== $provider) {
                continue;
            }
            $client = new ProviderClient($provider, $this->hub, $this->contracts);
            if (! $client->enabled()) {
                continue;
            }
            $run = $this->provider($client, $full, $trigger, $by, $catalogue);
            $out['courses'] += $run->courses_seen;
            $out['completed'] += $run->completions_applied;
            if ($run->error) {
                $out['errors'][] = "{$provider}: {$run->error}";
            }
            $out['providers'][$provider] = ['status' => $run->status, 'courses' => $run->courses_seen, 'added' => $run->courses_added, 'completions' => $run->completions_seen, 'completed' => $run->completions_applied, 'issues' => $run->issues_found, 'error' => $run->error];
        }

        return $out;
    }

    private function provider(ProviderClient $client, bool $full, string $trigger, ?User $by, bool $withCatalogue): ExternalSyncRun
    {
        $provider = $client->provider;
        $run = ExternalSyncRun::create(['provider' => $provider, 'status' => 'running', 'trigger' => $trigger, 'triggered_by' => $by?->id, 'started_at' => now()]);
        $missing = $client->training() ? [] : $this->learning->missing($provider);
        if ($missing !== []) {
            $run->update(['status' => 'failed', 'finished_at' => now(), 'error' => 'Not set up yet: '.implode(', ', $missing)]);

            return $run;
        }

        $errors = [];
        $parts = $withCatalogue ? 2 : 1;
        if ($withCatalogue) {
            try {
                $this->catalogue($client, $run, $full);
            } catch (Throwable $e) {
                $errors[] = $this->say($e);
            }
        }
        try {
            $this->completions($client, $run);
        } catch (Throwable $e) {
            $errors[] = $this->say($e);
        }
        $run->update(['status' => $errors === [] ? 'ok' : (count($errors) >= $parts ? 'failed' : 'partial'), 'finished_at' => now(), 'error' => $errors === [] ? null : mb_substr(implode(' · ', array_unique($errors)), 0, 400)]);
        if ($errors === []) {
            $this->hub->markSynced($provider);
        }

        return $run;
    }

    // ---- the catalogue --------------------------------------------------------------------------------

    private function catalogue(ProviderClient $client, ExternalSyncRun $run, bool $full): void
    {
        $provider = $client->provider;
        $started = now();
        $lastRead = ExternalSyncRun::where('provider', $provider)->whereNotNull('catalogue_at')->max('catalogue_at');
        $lastFull = ExternalSyncRun::where('provider', $provider)->where('catalogue_full', true)->max('catalogue_at');
        $full = $full || $lastRead === null || $lastFull === null || Carbon::parse($lastFull)->lt($started->copy()->subDays(self::FULL_EVERY_DAYS));
        $since = $full ? null : Carbon::parse($lastRead)->subDay();   // a day of margin: a provider's "changed since" is not exact to the second

        $settings = $client->settings();
        $rows = [];
        foreach ($client->courses($since) as $row) {
            if ($course = ProviderNormaliser::course($row, $settings)) {
                $rows[$course['external_id']] = $course;
            }
        }
        $added = 0;
        foreach (array_chunk($rows, 200, true) as $chunk) {
            $known = ExternalCourse::where('provider', $provider)->whereIn('external_id', array_keys($chunk))->pluck('external_id')->all();
            $added += count($chunk) - count($known);
            ExternalCourse::upsert(array_map(fn (array $c) => [
                'id' => (string) Str::uuid(), 'provider' => $provider, 'external_id' => $c['external_id'], 'title' => $c['title'], 'url' => $c['url'], 'hours' => $c['hours'], 'meta' => json_encode($c['meta'], JSON_UNESCAPED_UNICODE),
                'description' => $c['description'], 'level' => $c['level'], 'language' => $c['language'], 'image_url' => $c['image_url'], 'status' => $c['status'], 'synced_at' => $started, 'last_seen_at' => $started, 'created_at' => $started, 'updated_at' => $started,
            ], array_values($chunk)), ['provider', 'external_id'], ['title', 'url', 'hours', 'meta', 'description', 'level', 'language', 'image_url', 'status', 'synced_at', 'last_seen_at', 'updated_at']);
        }
        if ($full) {   // a whole reading that did not list a course says the provider no longer offers it
            ExternalCourse::where('provider', $provider)->where('status', '!=', 'removed')->where(fn ($q) => $q->whereNull('last_seen_at')->orWhere('last_seen_at', '<', $started))->update(['status' => 'removed']);
        }
        $run->update(['catalogue_at' => $started, 'catalogue_full' => $full, 'courses_seen' => count($rows), 'courses_added' => $added]);
    }

    // ---- the completions ------------------------------------------------------------------------------

    private function completions(ProviderClient $client, ExternalSyncRun $run): void
    {
        $provider = $client->provider;
        $started = now();
        $settings = $client->settings();
        $lookback = max(1, min(120, is_numeric($settings['completions_lookback_days'] ?? null) ? (int) $settings['completions_lookback_days'] : 7));
        $lastRead = ExternalSyncRun::where('provider', $provider)->whereNotNull('completions_at')->max('completions_at');
        $since = $started->copy()->subDays($lookback);
        if ($lastRead !== null && Carbon::parse($lastRead)->subDay()->lt($since)) {
            $since = Carbon::parse($lastRead)->subDay()->max($started->copy()->subDays(120));   // the last good reading was longer ago than the look-back: catch up
        }

        $seen = $applied = $issues = 0;
        $rows = [];
        if ($client->training() || $client->reportsForEveryone()) {
            $rows = $client->completions($since, $started);
        } elseif ($client->reportsPerPerson()) {
            $rows = $this->askPerPerson($client, $settings);
        }
        foreach ($rows as $row) {
            $done = ProviderNormaliser::completion($row);
            if (! $done['completed']) {
                continue;
            }
            $seen++;
            $outcome = $this->learning->applyCompletion($provider, $row);
            $applied += $outcome === ExternalLearningService::COMPLETED ? 1 : 0;
            $issues += $this->track($provider, $done, $outcome);
        }
        $run->update(['completions_at' => $started, 'completions_seen' => $seen, 'completions_applied' => $applied, 'issues_found' => $issues]);
    }

    /**
     * A provider that cannot report for everyone is asked about the people who are registered in one of its programmes and have not finished.
     *
     * @param  array<string, mixed>  $settings
     * @return list<array<string, mixed>>
     */
    private function askPerPerson(ProviderClient $client, array $settings): array
    {
        $source = (string) ($settings['username_source'] ?? 'employee_no');
        $rows = [];
        $registrations = Registration::where('status', Registration::STATUS_APPROVED)->whereDoesntHave('certificates')
            ->whereHas('program', fn ($q) => $q->where('external_platform->provider', $client->provider))->with('employee.user')->limit(self::MAX_PEOPLE)->get();
        $asked = [];
        foreach ($registrations as $registration) {
            $employee = $registration->employee;
            $username = $this->username($employee, $source);
            if ($username === '' || isset($asked[$username])) {
                continue;
            }
            $asked[$username] = true;
            foreach ($client->learnerCompletions($username) as $row) {
                $rows[] = $row + ['employee_no' => $employee->employee_no, 'username' => $username];
            }
        }

        return $rows;
    }

    private function username(Employee $employee, string $source): string
    {
        $email = (string) $employee->user?->email;

        return match ($source) {
            'email' => $email,
            'email_local' => Str::before($email, '@'),
            default => (string) $employee->employee_no,
        };
    }

    // ---- what could not be used -----------------------------------------------------------------------

    /**
     * @param  array{course_id: string, employee_no: ?string, email: ?string}  $done
     * @return int 1 when a problem was found or seen again, else 0
     */
    private function track(string $provider, array $done, string $outcome): int
    {
        $refs = array_values(array_filter([$done['employee_no'], $done['email']]));
        if (in_array($outcome, [ExternalLearningService::COMPLETED, ExternalLearningService::ALREADY_COMPLETED], true)) {
            ExternalCompletionIssue::where('provider', $provider)->where('external_course_id', $done['course_id'])->whereIn('learner_ref', $refs)->whereNull('resolved_at')
                ->update(['resolved_at' => now(), 'resolution' => 'fixed']);

            return 0;
        }
        if (! in_array($outcome, [ExternalCompletionIssue::UNKNOWN_EMPLOYEE, ExternalCompletionIssue::NOT_REGISTERED], true) || $refs === []) {
            return 0;
        }
        $issue = ExternalCompletionIssue::firstOrNew(['provider' => $provider, 'external_course_id' => $done['course_id'], 'learner_ref' => $refs[0], 'reason' => $outcome]);
        $issue->fill([
            'course_title' => ExternalCourse::where('provider', $provider)->where('external_id', $done['course_id'])->value('title'),
            'occurrences' => $issue->exists ? $issue->occurrences + 1 : 1, 'first_seen_at' => $issue->first_seen_at ?? now(), 'last_seen_at' => now(),
        ])->save();

        return 1;
    }

    private function say(Throwable $e): string
    {
        return Str::limit(trim(preg_replace('/\s+/', ' ', $e->getMessage()) ?? ''), 160, '');
    }
}
