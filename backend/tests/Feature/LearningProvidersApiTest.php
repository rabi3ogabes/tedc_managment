<?php

namespace Tests\Feature;

use App\Integrations\Contracts\ContractFixtures;
use App\Integrations\IntegrationManager;
use App\Models\AuditLog;
use App\Models\ExternalCompletionIssue;
use App\Models\ExternalCourse;
use App\Models\ExternalSyncRun;
use App\Models\Role;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** The learning providers marketplace: connection status and last sync per provider, the catalogue to search, and the completions that could not be used. */
class LearningProvidersApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    private function manager()
    {
        return $this->asUser($this->makeUser(Role::TRAINING_HEAD));
    }

    public function test_only_those_who_manage_providers_see_them(): void
    {
        $this->asUser($this->makeUser(Role::EMPLOYEE))->getJson('/api/v1/admin/learning-providers')->assertForbidden();
        $this->asUser($this->makeUser(Role::EMPLOYEE))->getJson('/api/v1/admin/learning-providers-issues')->assertForbidden();
        $this->manager()->getJson('/api/v1/admin/learning-providers')->assertOk()->assertJsonCount(4, 'data');
    }

    public function test_each_provider_says_where_its_connection_stands(): void
    {
        $head = $this->manager();
        $card = fn (string $key) => collect($head->getJson('/api/v1/admin/learning-providers')->json('data'))->firstWhere('key', $key);

        $this->assertSame('off', $card('coursera')['connection']);
        $this->assertSame(['coursera', 'edx', 'udemy', 'linkedin_learning'], collect($head->getJson('/api/v1/admin/learning-providers')->json('data'))->pluck('key')->all());
        $head->putJson('/api/v1/admin/learning-providers/coursera', ['enabled' => true, 'driver' => 'http'])->assertOk()->assertJsonPath('data.connection', 'not_set_up')->assertJsonPath('data.missing', ['org_id', 'client_id', 'client_secret']);
        $head->putJson('/api/v1/admin/learning-providers/coursera', ['settings' => ['org_id' => 'ORG123', 'client_id' => 'key', 'client_secret' => 'shh']])->assertOk()->assertJsonPath('data.connection', 'connected')->assertJsonPath('data.missing', []);
        $head->putJson('/api/v1/admin/learning-providers/coursera', ['driver' => 'fake'])->assertOk()->assertJsonPath('data.connection', 'training');
        $head->putJson('/api/v1/admin/learning-providers/coursera', ['enabled' => false])->assertOk()->assertJsonPath('data.connection', 'off');
        $this->assertSame('http', collect(['http'])->first());
    }

    public function test_changing_a_provider_asks_for_the_second_factor_again_like_the_integration_card(): void
    {
        $middleware = fn (string $uri) => collect(app('router')->getRoutes()->getRoutes())->first(fn ($r) => in_array('PUT', $r->methods(), true) && $r->uri() === $uri)->gatherMiddleware();
        $this->assertContains('step_up', $middleware('api/v1/admin/integrations/{key}'));
        $this->assertContains('step_up', $middleware('api/v1/admin/learning-providers/{provider}'), 'credentials are changed through this route too');
    }

    public function test_a_secret_is_never_shown_and_a_blank_one_keeps_the_stored_value(): void
    {
        $head = $this->manager();
        $head->putJson('/api/v1/admin/learning-providers/udemy', ['enabled' => true, 'driver' => 'http', 'settings' => ['subdomain' => 'acme', 'organization_id' => '8888', 'client_id' => 'id-1', 'client_secret' => 'very-secret']])->assertOk();
        $card = $head->getJson('/api/v1/admin/learning-providers')->json('data.2');
        $this->assertSame('udemy', $card['key']);
        $this->assertArrayNotHasKey('client_secret', $card['settings']);
        $this->assertTrue($card['secrets_set']['client_secret']);
        $this->assertStringNotContainsString('very-secret', json_encode($card));
        $head->putJson('/api/v1/admin/learning-providers/udemy', ['settings' => ['client_secret' => '', 'subdomain' => 'acme2']])->assertOk();
        $this->assertSame('very-secret', app(IntegrationManager::class)->settings('udemy')['client_secret'], 'a blank secret keeps the one stored');
        $this->assertSame('acme2', app(IntegrationManager::class)->settings('udemy')['subdomain']);
        $head->putJson('/api/v1/admin/learning-providers/udemy', ['clear' => ['client_secret']])->assertOk()->assertJsonPath('data.secrets_set.client_secret', false);
        $head->putJson('/api/v1/admin/learning-providers/nobody', ['enabled' => true])->assertNotFound();
        $head->putJson('/api/v1/admin/learning-providers/udemy', ['driver' => 'smtp'])->assertUnprocessable();
    }

    public function test_a_change_is_audited_by_what_it_changed_and_never_with_a_secret(): void
    {
        $this->manager()->putJson('/api/v1/admin/learning-providers/linkedin_learning', ['enabled' => true, 'driver' => 'http', 'settings' => ['client_id' => 'id-9', 'client_secret' => 'top-secret-value']])->assertOk();
        $entry = AuditLog::where('action', 'integration.settings_changed')->latest('created_at')->firstOrFail();
        $this->assertSame('linkedin_learning', $entry->new_values['integration']);
        $this->assertEqualsCanonicalizing(['client_id', 'client_secret'], $entry->new_values['settings_changed']);
        $this->assertStringNotContainsString('top-secret-value', json_encode($entry->toArray()));
        $this->assertStringNotContainsString('id-9', json_encode($entry->toArray()), 'the audit trail says what changed, not the values');
    }

    public function test_a_provider_is_read_on_request_and_the_card_shows_the_last_reading(): void
    {
        $head = $this->manager();
        $head->postJson('/api/v1/admin/learning-providers/coursera/sync')->assertUnprocessable();   // switched off

        $head->putJson('/api/v1/admin/learning-providers/coursera', ['enabled' => true, 'driver' => 'http', 'settings' => ['org_id' => 'ORG123', 'client_id' => 'k', 'client_secret' => 's']])->assertOk();
        ContractFixtures::fake('coursera', 'https://api.coursera.com/ent', settings: ['org_id' => 'ORG123']);
        $head->postJson('/api/v1/admin/learning-providers/coursera/sync')->assertOk()
            ->assertJsonPath('data.courses', 3)->assertJsonPath('data.status', 'ok')
            ->assertJsonPath('provider.last_run.status', 'ok')->assertJsonPath('provider.last_run.trigger', 'manual')->assertJsonPath('provider.catalogue.total', 3)->assertJsonPath('provider.connection', 'connected');
        $this->assertSame(1, ExternalSyncRun::where('provider', 'coursera')->count());
        $head->postJson('/api/v1/admin/learning-providers/coursera/sync', ['full' => true])->assertOk();
        $this->assertTrue(ExternalSyncRun::where('provider', 'coursera')->latest('started_at')->first()->catalogue_full);
        $this->assertCount(2, $head->getJson('/api/v1/admin/learning-providers-runs?provider=coursera')->json('data'));
        $head->getJson('/api/v1/admin/learning-providers-runs?provider=nope')->assertUnprocessable();
    }

    public function test_a_failing_provider_shows_attention_with_its_error_and_the_connection_check_says_what_it_found(): void
    {
        $head = $this->manager();
        $head->putJson('/api/v1/admin/learning-providers/udemy', ['enabled' => true, 'driver' => 'http', 'settings' => ['subdomain' => 'acme', 'organization_id' => '8888', 'client_id' => 'k', 'client_secret' => 's']])->assertOk();
        ContractFixtures::fake('udemy', 'https://acme.udemy.com', scenario: 'server_error', settings: ['organization_id' => '8888']);
        $sync = $head->postJson('/api/v1/admin/learning-providers/udemy/sync')->assertOk()->assertJsonPath('data.status', 'failed')->json();
        $this->assertNotEmpty($sync['data']['error']);
        $this->assertSame('attention', $sync['provider']['connection']);
        $this->assertStringNotContainsString('"s"', json_encode($sync));

        Http::swap(new Factory);
        ContractFixtures::fake('udemy', 'https://acme.udemy.com', settings: ['organization_id' => '8888']);
        $head->postJson('/api/v1/admin/learning-providers/udemy/check')->assertOk()->assertJsonPath('data.health', 'ok');
    }

    public function test_the_catalogue_is_searched_by_words_in_either_language_and_filtered(): void
    {
        $head = $this->manager();
        foreach ([
            ['coursera', 'C1', 'Project Management Principles', 'Plan and run a project', 8, 'available', null],
            ['coursera', 'C2', 'Interaction Design', 'Bring people joy', 4, 'available', null],
            ['udemy', 'U1', 'Arabic Business Writing', 'كتابة الخطابات والتقارير', 2, 'retired', null],
            ['udemy', 'U2', 'Withdrawn course', 'gone', 1, 'removed', null],
            ['edx', 'E1', 'Classroom Management', '100% practical_work', 6, 'available', null],
        ] as [$provider, $id, $title, $about, $hours, $status]) {
            ExternalCourse::create(['provider' => $provider, 'external_id' => $id, 'title' => $title, 'description' => $about, 'url' => "https://x.example/{$id}", 'hours' => $hours, 'status' => $status]);
        }
        $titles = fn (string $query) => collect($head->getJson('/api/v1/admin/learning-providers-courses?'.$query)->assertOk()->json('data'))->pluck('title')->sort()->values()->all();

        $this->assertSame(['Arabic Business Writing', 'Classroom Management', 'Interaction Design', 'Project Management Principles'], $titles(''), 'what the provider no longer lists is left out unless asked for');
        $this->assertSame(['Project Management Principles'], $titles('q=project'));
        $this->assertSame(['Interaction Design'], $titles('q=JOY'), 'the description is searched too, whatever the case');
        $this->assertSame(['Arabic Business Writing'], $titles('q='.rawurlencode('التقارير')));
        $this->assertSame(['Classroom Management'], $titles('q='.rawurlencode('100%')), 'a percent sign is a character, not a wildcard');
        $this->assertSame(['Classroom Management'], $titles('q='.rawurlencode('practical_w')));
        $this->assertSame([], $titles('q='.rawurlencode('pract_cal_work')), 'nor is an underscore (as a wildcard it would match "practical_work")');
        $this->assertSame(['Interaction Design', 'Project Management Principles'], $titles('provider=coursera'));
        $this->assertSame(['Arabic Business Writing'], $titles('status=retired'));
        $this->assertSame(['Withdrawn course'], $titles('status=removed'));
        $this->assertSame([], $titles('provider=coursera&imported=1'));
        $this->assertCount(4, $titles('imported=0'));
        $head->getJson('/api/v1/admin/learning-providers-courses?provider=nope')->assertUnprocessable();
        $head->getJson('/api/v1/admin/learning-providers-courses?per_page=2')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('total', 4);
    }

    public function test_problems_are_listed_and_an_administrator_can_set_one_aside(): void
    {
        $head = $this->manager();
        $open = ExternalCompletionIssue::create(['provider' => 'coursera', 'external_course_id' => 'C1', 'course_title' => 'Project Management', 'learner_ref' => 'stranger@example.test', 'reason' => 'unknown_employee', 'first_seen_at' => now()->subDay(), 'last_seen_at' => now()]);
        ExternalCompletionIssue::create(['provider' => 'udemy', 'external_course_id' => 'U1', 'learner_ref' => 'E-1', 'reason' => 'not_registered', 'first_seen_at' => now(), 'last_seen_at' => now(), 'resolved_at' => now(), 'resolution' => 'fixed']);

        $rows = $head->getJson('/api/v1/admin/learning-providers-issues')->assertOk()->json('data');
        $this->assertCount(1, $rows, 'open ones by default');
        $this->assertSame(['coursera', 'unknown_employee', 'stranger@example.test'], [$rows[0]['provider'], $rows[0]['reason'], $rows[0]['learner_ref']]);
        $this->assertCount(2, $head->getJson('/api/v1/admin/learning-providers-issues?state=all')->json('data'));
        $this->assertCount(1, $head->getJson('/api/v1/admin/learning-providers-issues?state=resolved')->json('data'));
        $this->assertCount(0, $head->getJson('/api/v1/admin/learning-providers-issues?provider=udemy')->json('data'));

        $head->postJson("/api/v1/admin/learning-providers-issues/{$open->id}/dismiss")->assertOk()->assertJsonPath('data.resolution', 'dismissed');
        $this->assertNotNull($open->fresh()->resolved_at);
        $this->assertNotNull($open->fresh()->resolved_by);
        $this->assertCount(0, $head->getJson('/api/v1/admin/learning-providers-issues')->json('data'));
        $this->asUser($this->makeUser(Role::EMPLOYEE))->postJson("/api/v1/admin/learning-providers-issues/{$open->id}/dismiss")->assertForbidden();
    }
}
