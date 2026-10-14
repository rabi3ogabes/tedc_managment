<?php

namespace Tests\Feature;

use App\Integrations\Contracts\ContractFixtures;
use App\Integrations\IntegrationManager;
use App\Models\ExternalCompletionIssue;
use App\Models\ExternalSyncRun;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** The nightly reading of the learning providers, from the terminal: all of them, one, or only the completions; and the tidying of what it keeps. */
class LearningProvidersSyncCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    private function connectCoursera(): void
    {
        app(IntegrationManager::class)->update('coursera', ['enabled' => true, 'driver' => 'http', 'settings' => ['org_id' => 'ORG123', 'client_id' => 'k', 'client_secret' => 's']]);
        ContractFixtures::fake('coursera', 'https://api.coursera.com/ent', settings: ['org_id' => 'ORG123']);
    }

    public function test_it_reads_every_provider_that_is_on_and_says_what_it_found(): void
    {
        $this->connectCoursera();
        app(IntegrationManager::class)->update('udemy', ['enabled' => true, 'driver' => 'fake']);

        $this->artisan('tedc:learning-providers-sync')->expectsOutputToContain('coursera: ok')->expectsOutputToContain('udemy: ok')->assertSuccessful();
        $this->assertSame(['coursera', 'udemy'], ExternalSyncRun::orderBy('provider')->pluck('provider')->all());
        $this->assertSame('schedule', ExternalSyncRun::first()->trigger);
    }

    public function test_one_provider_can_be_read_alone_and_the_whole_catalogue_asked_for(): void
    {
        $this->connectCoursera();
        app(IntegrationManager::class)->update('udemy', ['enabled' => true, 'driver' => 'fake']);
        $this->artisan('tedc:learning-providers-sync', ['provider' => 'coursera', '--full' => true])->assertSuccessful();
        $this->assertSame(['coursera'], ExternalSyncRun::pluck('provider')->all());
        $this->assertTrue(ExternalSyncRun::first()->catalogue_full);
        $this->artisan('tedc:learning-providers-sync', ['provider' => 'nobody'])->expectsOutputToContain('no provider')->assertFailed();
    }

    public function test_only_the_completions_can_be_read_without_touching_the_catalogue(): void
    {
        $this->connectCoursera();
        $this->artisan('tedc:learning-providers-sync', ['--completions' => true])->assertSuccessful();
        $contents = collect(Http::recorded())->filter(fn ($p) => str_contains((string) $p[0]->url(), '/contents'));
        $this->assertCount(0, $contents, 'the catalogue was not asked for');
        $this->assertCount(1, collect(Http::recorded())->filter(fn ($p) => str_contains((string) $p[0]->url(), '/enrollmentReports')));
        $run = ExternalSyncRun::first();
        $this->assertNull($run->catalogue_at);
        $this->assertNotNull($run->completions_at);
        $this->assertSame('ok', $run->status);
    }

    public function test_what_it_keeps_is_tidied_after_its_time(): void
    {
        ExternalSyncRun::create(['provider' => 'coursera', 'status' => 'ok', 'trigger' => 'schedule', 'started_at' => now()->subDays(200)]);
        ExternalSyncRun::create(['provider' => 'coursera', 'status' => 'ok', 'trigger' => 'schedule', 'started_at' => now()->subDays(10)]);
        $issue = fn (string $ref, array $times) => ExternalCompletionIssue::create(['provider' => 'coursera', 'external_course_id' => 'C1', 'learner_ref' => $ref, 'reason' => 'unknown_employee', 'first_seen_at' => now()->subDays(300), 'last_seen_at' => $times['seen']] + ($times['resolved'] ?? []));
        $issue('old-resolved', ['seen' => now()->subDays(100), 'resolved' => ['resolved_at' => now()->subDays(100), 'resolution' => 'fixed']]);
        $issue('recent-resolved', ['seen' => now()->subDays(5), 'resolved' => ['resolved_at' => now()->subDays(5), 'resolution' => 'fixed']]);
        $issue('long-gone-open', ['seen' => now()->subDays(100)]);
        $issue('still-open', ['seen' => now()->subDay()]);

        $this->artisan('tedc:learning-providers-sync', ['--prune' => true])->assertSuccessful();
        $this->assertSame(1, ExternalSyncRun::count(), 'readings older than 180 days go');
        $this->assertSame(['recent-resolved', 'still-open'], ExternalCompletionIssue::orderBy('learner_ref')->pluck('learner_ref')->all(), 'a problem solved 90 days ago, or not reported for 90 days, goes');
    }

    public function test_it_is_scheduled_every_night_and_the_completions_every_six_hours(): void
    {
        $events = collect(app(Schedule::class)->events())->map(fn ($e) => $e->command.' | '.$e->expression);
        $this->assertTrue($events->contains(fn ($e) => str_contains($e, 'tedc:learning-providers-sync') && ! str_contains($e, '--completions') && str_ends_with($e, '45 1 * * *')), 'nightly');
        $this->assertTrue($events->contains(fn ($e) => str_contains($e, 'tedc:learning-providers-sync') && str_contains($e, '--completions') && str_ends_with($e, '15 */6 * * *')), 'every six hours');
        $this->assertTrue($events->contains(fn ($e) => str_contains($e, 'tedc:learning-providers-sync') && str_contains($e, '--prune')), 'tidied');
    }
}
