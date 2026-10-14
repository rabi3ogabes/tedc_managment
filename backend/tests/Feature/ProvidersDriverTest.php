<?php

namespace Tests\Feature;

use App\Integrations\Contracts\ContractFixtures;
use App\Integrations\IntegrationManager;
use App\Models\Employee;
use App\Models\ExternalCompletionIssue;
use App\Models\ExternalCourse;
use App\Models\ExternalSyncRun;
use App\Models\Integration;
use App\Models\IntegrationLog;
use App\Models\Program;
use App\Models\Registration;
use App\Models\Role;
use App\Services\AnnualHoursService;
use App\Services\Library\ExternalLearningService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The learning providers (Coursera for Business, Udemy Business, LinkedIn Learning, Open edX) follow each provider's own published API through
 * a contract: the catalogue becomes courses, a completion ends a registration and counts its hours, and what cannot be matched is said
 * plainly. The recorded answers are the shapes the providers document.
 */
class ProvidersDriverTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    /** @param  array<string, mixed>  $settings */
    private function connect(string $provider, array $settings = [], string $driver = 'http'): void
    {
        app(IntegrationManager::class)->update($provider, ['enabled' => true, 'driver' => $driver, 'settings' => $settings + ['client_id' => 'tedc-client', 'client_secret' => 'tedc-secret']]);
    }

    private function employee(string $no, string $email): Employee
    {
        return $this->makeEmployee(['employee_no' => $no], $this->makeUser(Role::EMPLOYEE, ['email' => $email]));
    }

    private function programme(string $provider, string $externalId): Program
    {
        return app(ExternalLearningService::class)->programFromCourse(ExternalCourse::where('provider', $provider)->where('external_id', $externalId)->firstOrFail(), $this->makeUser(Role::TRAINING_HEAD));
    }

    private function register(Program $p, Employee $e): Registration
    {
        return Registration::create(['program_id' => $p->id, 'employee_id' => $e->id, 'source' => 'center_nomination', 'status' => Registration::STATUS_APPROVED]);
    }

    /** @return Collection<int, Request> */
    private function sent(?string $contains = null)
    {
        return collect(Http::recorded())->map(fn ($p) => $p[0])->filter(fn (Request $r) => $contains === null || str_contains($r->url(), $contains))->values();
    }

    // ---- Coursera for Business ----------------------------------------------------------------------------

    public function test_coursera_courses_come_in_with_their_hours_and_a_completion_ends_a_registration(): void
    {
        $this->connect('coursera', ['org_id' => 'ORG123', 'default_hours' => 4]);
        ContractFixtures::fake('coursera', 'https://api.coursera.com/ent', settings: ['org_id' => 'ORG123']);
        $svc = app(ExternalLearningService::class);

        $first = $svc->sync('coursera');
        $this->assertSame(3, $first['courses']);
        $this->assertSame([], $first['errors']);
        $course = ExternalCourse::where('provider', 'coursera')->where('external_id', 'Course~aRxESQNMEeuH3Q6N8dCjzw')->firstOrFail();
        $this->assertSame(['Interaction Design Foundations', 'https://www.coursera.org/learn/interaction-design-foundations', 5, 'beginner', 'en'], [$course->title, $course->url, $course->hours, $course->level, $course->language], '15,689 seconds is 4.36 hours: counted as 5');
        $this->assertSame(4, ExternalCourse::where('external_id', 'Specialization~ZxUbYRX3EeWXAyIAC0mP-A')->value('hours'), 'a course the provider gives no duration for counts the default hours');
        $this->assertStringContainsString('Learn how to design digital products', (string) $course->description);
        $this->assertStringNotContainsString('<p>', (string) $course->description);

        $token = $this->sent('/oauth2/client_credentials/token')->first();
        $this->assertSame('https://api.coursera.com/oauth2/client_credentials/token', $token->url());
        $this->assertTrue($token->hasHeader('Authorization', 'Basic '.base64_encode('tedc-client:tedc-secret')), 'the app key and secret sign in with the Authorization header');
        $this->assertSame('client_credentials', $token['grant_type']);
        $contents = $this->sent('/ent/api/businesses.v1/ORG123/contents')->first();
        $this->assertStringStartsWith('Bearer mock-token-', $contents->header('Authorization')[0]);
        $this->assertStringContainsString('contentTypes=Course%2CSpecialization', $contents->url());
        $this->assertStringContainsString('limit=100', $contents->url());

        $sara = $this->employee('E-1001', 'sara.kuwari@example.test');
        $programme = $this->programme('coursera', 'Course~aRxESQNMEeuH3Q6N8dCjzw');
        $registration = $this->register($programme, $sara);
        $second = $svc->sync('coursera');
        $this->assertSame(1, $second['completed']);
        $this->assertSame(0, $svc->sync('coursera')['completed'], 'a completion counts once');
        $this->assertSame('completed', $registration->fresh()->status);
        $this->assertEquals(5.0, (float) $registration->certificates()->first()->hours);
        $this->assertEquals(5.0, app(AnnualHoursService::class)->allTime($sara->fresh()));
        $reports = $this->sent('/enrollmentReports')->first();
        $this->assertStringContainsString('includeS12n=true', $reports->url());
        $this->assertStringContainsString('completedAtAfter=', $reports->url());
    }

    public function test_a_completion_that_cannot_be_matched_is_said_with_its_reason_and_ends_when_the_cause_does(): void
    {
        $this->connect('coursera', ['org_id' => 'ORG123']);
        ContractFixtures::fake('coursera', 'https://api.coursera.com/ent', settings: ['org_id' => 'ORG123']);
        $svc = app(ExternalLearningService::class);
        $svc->sync('coursera');
        $this->employee('E-1001', 'sara.kuwari@example.test');   // not registered in the programme
        $ali = $this->employee('E-1002', 'ali.naimi@example.test');
        foreach (['Course~aRxESQNMEeuH3Q6N8dCjzw', 'Course~r0GnHOZaEees-Q6jQMxlrg', 'Specialization~ZxUbYRX3EeWXAyIAC0mP-A'] as $id) {
            $programmes[$id] = $this->programme('coursera', $id);
        }

        $result = $svc->sync('coursera');
        $this->assertSame(0, $result['completed']);
        $issues = ExternalCompletionIssue::orderBy('reason')->get();
        $this->assertSame(['not_registered', 'not_registered', 'unknown_employee'], $issues->pluck('reason')->all(), 'Sara and Ali are known but not registered; the third person is not known');
        $unknown = $issues->firstWhere('reason', 'unknown_employee');
        $this->assertSame(['coursera', 'Course~r0GnHOZaEees-Q6jQMxlrg', 'stranger@example.test'], [$unknown->provider, $unknown->external_course_id, $unknown->learner_ref]);
        $this->assertSame('Project Management Principles', $unknown->course_title);
        $this->assertNull($unknown->resolved_at);

        $svc->sync('coursera');
        $this->assertSame(1, ExternalCompletionIssue::where('reason', 'unknown_employee')->first()->occurrences - 1, 'seen again, not written twice');
        $this->assertSame(3, ExternalCompletionIssue::count());

        $this->register($programmes['Specialization~ZxUbYRX3EeWXAyIAC0mP-A'], $ali);
        $this->assertSame(1, $svc->sync('coursera')['completed']);
        $this->assertNotNull(ExternalCompletionIssue::where('learner_ref', 'E-1002')->first()->resolved_at, 'the registration ended the problem');
        $this->assertSame(2, ExternalCompletionIssue::whereNull('resolved_at')->count());
    }

    public function test_the_catalogue_is_asked_for_what_changed_after_the_first_sync_and_in_full_once_a_week(): void
    {
        Carbon::setTestNow('2026-10-14 10:00:00');
        $this->connect('coursera', ['org_id' => 'ORG123']);
        ContractFixtures::fake('coursera', 'https://api.coursera.com/ent', settings: ['org_id' => 'ORG123']);
        $svc = app(ExternalLearningService::class);
        $contentsCalls = fn () => $this->sent('/businesses.v1/ORG123/contents');

        $svc->sync('coursera');
        $this->assertStringNotContainsString('modifiedSinceTimestamp', $contentsCalls()->last()->url(), 'the first time it asks for everything');
        Carbon::setTestNow('2026-10-15 10:00:00');
        $svc->sync('coursera');
        $this->assertStringContainsString('modifiedSinceTimestamp='.Carbon::parse('2026-10-13 10:00:00')->timestamp, $contentsCalls()->last()->url(), 'a day later it asks for what changed since a day before the last sync');
        Carbon::setTestNow('2026-10-22 10:00:00');
        $svc->sync('coursera');
        $this->assertStringNotContainsString('modifiedSinceTimestamp', $contentsCalls()->last()->url(), 'a week after the last full catalogue it asks for everything again');
        $svc->sync('coursera', full: true);
        $this->assertStringNotContainsString('modifiedSinceTimestamp', $contentsCalls()->last()->url(), 'and an administrator can ask for everything whenever');
        Carbon::setTestNow();
    }

    public function test_a_course_the_provider_no_longer_lists_is_marked_removed_after_a_full_sync(): void
    {
        $this->connect('coursera', ['org_id' => 'ORG123']);
        ContractFixtures::fake('coursera', 'https://api.coursera.com/ent', settings: ['org_id' => 'ORG123']);
        $gone = ExternalCourse::create(['provider' => 'coursera', 'external_id' => 'Course~withdrawn', 'title' => 'Withdrawn', 'url' => 'https://www.coursera.org/learn/withdrawn', 'hours' => 3]);
        app(ExternalLearningService::class)->sync('coursera', full: true);
        $this->assertSame('removed', $gone->fresh()->status);
        $this->assertSame('available', ExternalCourse::where('external_id', 'Course~aRxESQNMEeuH3Q6N8dCjzw')->value('status'));
    }

    // ---- Udemy Business -----------------------------------------------------------------------------------

    public function test_udemy_courses_are_read_with_the_client_in_basic_authentication_and_activity_in_a_window(): void
    {
        Carbon::setTestNow('2026-10-14 12:00:00');
        $this->connect('udemy', ['subdomain' => 'acme', 'organization_id' => '8888']);
        ContractFixtures::fake('udemy', 'https://acme.udemy.com', settings: ['organization_id' => '8888']);
        $svc = app(ExternalLearningService::class);

        $this->assertSame(3, $svc->sync('udemy')['courses']);
        $course = ExternalCourse::where('provider', 'udemy')->where('external_id', '12345678')->firstOrFail();
        $this->assertSame(['Data Engineering Foundations', 1, 'https://learning.udemy.example.test/data-engineering-foundations/'], [$course->title, $course->hours, $course->url], '42 minutes count as one hour');
        $list = $this->sent('/courses/list/')->first();
        $this->assertTrue($list->hasHeader('Authorization', 'Basic '.base64_encode('tedc-client:tedc-secret')));
        $this->assertStringStartsWith('https://acme.udemy.com/api-2.0/organizations/8888/courses/list/', $list->url());
        $this->assertStringContainsString('page_size=100', $list->url());

        $sara = $this->employee('E-1001', 'sara.kuwari@example.test');
        $registration = $this->register($this->programme('udemy', '12345678'), $sara);
        $this->assertSame(1, $svc->sync('udemy')['completed']);
        $this->assertSame('completed', $registration->fresh()->status);
        $activity = $this->sent('/analytics/user-course-activity/')->last();
        $this->assertStringContainsString('from_date=2026-10-07', $activity->url(), 'a week back, the default look-back');
        $this->assertStringContainsString('to_date=2026-10-14', $activity->url());
        Carbon::setTestNow();
    }

    public function test_a_completion_is_matched_by_the_e_mail_when_the_provider_has_no_employee_number(): void
    {
        $this->connect('udemy', ['subdomain' => 'acme', 'organization_id' => '8888']);
        ContractFixtures::fake('udemy', 'https://acme.udemy.com', settings: ['organization_id' => '8888']);
        $svc = app(ExternalLearningService::class);
        $svc->sync('udemy');
        $employee = $this->employee('E-7777', 'stranger@example.test');   // the fourth row has no external id, only an address
        $registration = $this->register($this->programme('udemy', '12345678'), $employee);
        $this->assertSame(1, $svc->sync('udemy')['completed']);
        $this->assertSame('completed', $registration->fresh()->status);
    }

    // ---- LinkedIn Learning --------------------------------------------------------------------------------

    public function test_linkedin_learning_courses_use_the_sso_link_and_the_duration_in_its_own_unit(): void
    {
        $this->connect('linkedin_learning');
        ContractFixtures::fake('linkedin_learning', 'https://api.linkedin.com/v2');
        $this->assertSame(3, app(ExternalLearningService::class)->sync('linkedin_learning')['courses']);

        $one = ExternalCourse::where('external_id', 'urn:li:lyndaCourse:187499')->firstOrFail();
        $this->assertSame([2, 'https://www.linkedin.example.test/learning-login/share/collaborative-design-process-and-efficiency', 'available'], [$one->hours, $one->url, $one->status], '95 minutes count as 2 hours; the single sign-on link is preferred');
        $two = ExternalCourse::where('external_id', 'urn:li:lyndaCourse:187500')->firstOrFail();
        $this->assertSame([2, 'https://www.linkedin.example.test/learning/collaborative-design-vision-and-strategy'], [$two->hours, $two->url], '2 hours; the web link when there is no single sign-on link');
        $this->assertSame('retired', ExternalCourse::where('external_id', 'urn:li:lyndaCourse:144562')->value('status'));
        $token = $this->sent('linkedin.com/oauth/v2/accessToken')->first();
        $this->assertSame(['client_credentials', 'tedc-client', 'tedc-secret'], [$token['grant_type'], $token['client_id'], $token['client_secret']], 'LinkedIn takes the client in the form');
        $assets = $this->sent('/learningAssets')->first();
        $this->assertStringContainsString('assetFilteringCriteria.licensedOnly=true', $assets->url());
        $this->assertStringContainsString('count=100', $assets->url());
    }

    public function test_linkedin_activity_is_asked_in_windows_of_at_most_two_weeks(): void
    {
        Carbon::setTestNow('2026-10-14 12:00:00');
        $this->connect('linkedin_learning', ['completions_lookback_days' => 20]);
        ContractFixtures::fake('linkedin_learning', 'https://api.linkedin.com/v2');
        $svc = app(ExternalLearningService::class);
        $svc->sync('linkedin_learning');
        $calls = $this->sent('/learningActivityReports');
        $this->assertCount(2, $calls, '20 days are two windows');
        $from = Carbon::parse('2026-09-24 12:00:00');
        $this->assertStringContainsString('startedAt='.($from->timestamp * 1000), $calls[0]->url());
        $this->assertStringContainsString('timeOffset.duration=14', $calls[0]->url());
        $this->assertStringContainsString('startedAt='.($from->copy()->addDays(14)->timestamp * 1000), $calls[1]->url());
        $this->assertStringContainsString('timeOffset.duration=6', $calls[1]->url());
        $this->assertStringContainsString('timeOffset.unit=DAY', $calls[1]->url());

        $sara = $this->employee('E-1001', 'sara.kuwari@example.test');
        $registration = $this->register($this->programme('linkedin_learning', 'urn:li:lyndaCourse:187499'), $sara);
        $this->assertSame(1, $svc->sync('linkedin_learning')['completed']);
        $this->assertSame('completed', $registration->fresh()->status);
        Carbon::setTestNow();
    }

    // ---- Open edX -----------------------------------------------------------------------------------------

    public function test_open_edx_signs_in_with_a_jwt_and_asks_for_the_certificates_of_the_people_who_are_registered(): void
    {
        $this->connect('edx', ['base_url' => 'https://lms.example.test', 'username' => 'tedc-service', 'default_hours' => 6]);
        ContractFixtures::fake('edx', 'https://lms.example.test', settings: ['username' => 'tedc-service']);
        $svc = app(ExternalLearningService::class);

        $this->assertSame(2, $svc->sync('edx')['courses']);
        $course = ExternalCourse::where('external_id', 'course-v1:MOE+TEACH101+2026')->firstOrFail();
        $this->assertSame(['Classroom Management', 6, 'https://lms.example.test/courses/course-v1:MOE+TEACH101+2026/about'], [$course->title, $course->hours, $course->url], 'the address is the platform\'s own; the hours are the default');
        $token = $this->sent('/oauth2/access_token')->first();
        $this->assertTrue($token->hasHeader('Authorization', 'Basic '.base64_encode('tedc-client:tedc-secret')));
        $this->assertSame(['client_credentials', 'jwt'], [$token['grant_type'], $token['token_type']]);
        $courses = $this->sent('/api/courses/v1/courses/')->first();
        $this->assertStringStartsWith('JWT mock-token-', $courses->header('Authorization')[0], 'Open edX takes the token as JWT, not Bearer');
        $this->assertStringContainsString('username=tedc-service', $courses->url());

        $sara = $this->employee('E-1001', 'sara.kuwari@example.test');
        $registration = $this->register($this->programme('edx', 'course-v1:MOE+TEACH101+2026'), $sara);
        $result = $svc->sync('edx');
        $this->assertSame(1, $result['completed']);
        $this->assertSame('completed', $registration->fresh()->status);
        $this->assertEquals(6.0, (float) $registration->certificates()->first()->hours);
        $this->assertCount(1, $this->sent('/api/certificates/v0/certificates/'), 'one registered person, one question');
        $this->assertSame('https://lms.example.test/api/certificates/v0/certificates/E-1001/', $this->sent('/api/certificates/v0/certificates/')->first()->url());
        $this->assertSame(0, $svc->sync('edx')['completed'], 'a person who has finished is not asked about again');
        $this->assertCount(1, $this->sent('/api/certificates/v0/certificates/'));
    }

    public function test_open_edx_can_use_the_e_mail_as_the_user_name(): void
    {
        $this->connect('edx', ['base_url' => 'https://lms.example.test', 'username' => 'tedc-service', 'username_source' => 'email']);
        ContractFixtures::fake('edx', 'https://lms.example.test', settings: ['username' => 'tedc-service']);
        $svc = app(ExternalLearningService::class);
        $svc->sync('edx');
        $this->register($this->programme('edx', 'course-v1:MOE+TEACH101+2026'), $this->employee('E-1001', 'sara.kuwari@example.test'));
        $svc->sync('edx');
        $this->assertStringContainsString('/certificates/sara.kuwari%40example.test/', $this->sent('/api/certificates/v0/certificates/')->first()->url());
    }

    // ---- shared behaviour ---------------------------------------------------------------------------------

    public function test_the_training_mode_gives_sample_courses_and_reads_completions_from_its_setting(): void
    {
        $this->connect('udemy', driver: 'fake');
        $svc = app(ExternalLearningService::class);
        $this->assertSame(2, $svc->sync('udemy')['courses']);
        $this->assertSame(['udemy-101', 'udemy-102'], ExternalCourse::where('provider', 'udemy')->orderBy('external_id')->pluck('external_id')->all());
        Http::assertNothingSent();

        $employee = $this->employee('E-5001', 'fake.learner@example.test');
        $registration = $this->register($this->programme('udemy', 'udemy-101'), $employee);
        app(IntegrationManager::class)->update('udemy', ['settings' => ['fake_completions' => [['course_id' => 'udemy-101', 'employee_no' => 'E-5001', 'completed_at' => '2026-10-12T09:30:00+00:00']]]]);
        $this->assertSame(1, $svc->sync('udemy')['completed']);
        $this->assertSame('completed', $registration->fresh()->status);
        $this->assertEquals(6.0, (float) $registration->certificates()->first()->hours);
    }

    public function test_a_provider_that_fails_is_said_plainly_and_the_others_still_run(): void
    {
        $this->connect('coursera', ['org_id' => 'ORG123']);
        $this->connect('udemy', ['subdomain' => 'acme', 'organization_id' => '8888']);
        ContractFixtures::fake('coursera', 'https://api.coursera.com/ent', scenario: 'server_error', settings: ['org_id' => 'ORG123']);
        ContractFixtures::fake('udemy', 'https://acme.udemy.com', settings: ['organization_id' => '8888']);

        $result = app(ExternalLearningService::class)->sync();
        $this->assertSame(3, $result['courses'], 'Udemy\'s three courses');
        $this->assertCount(1, $result['errors']);
        $this->assertStringStartsWith('coursera:', $result['errors'][0]);
        $this->assertStringNotContainsString('tedc-secret', $result['errors'][0]);

        $runs = ExternalSyncRun::orderBy('provider')->get();
        $this->assertSame(['coursera' => 'failed', 'udemy' => 'ok'], $runs->pluck('status', 'provider')->all());
        $this->assertNotEmpty($runs->firstWhere('provider', 'coursera')->error);
        $this->assertSame(3, $runs->firstWhere('provider', 'udemy')->courses_seen);
        $this->assertNotNull(Integration::where('key', 'udemy')->value('last_sync_at'), 'the card shows when it last synced');
        $this->assertGreaterThan(0, IntegrationLog::where('integration_key', 'coursera')->where('status', 'error')->count(), 'the hub logged the failure');
    }

    public function test_a_provider_that_is_switched_off_or_not_set_up_is_left_alone_and_the_missing_settings_are_named(): void
    {
        Http::fake();
        $svc = app(ExternalLearningService::class);
        $this->assertSame(0, $svc->sync()['courses'], 'nothing is switched on');
        Http::assertNothingSent();

        $this->connect('edx', ['base_url' => 'https://lms.example.test']);   // no username
        $result = $svc->sync('edx');
        $this->assertSame(0, $result['courses']);
        $this->assertStringContainsString('username', $result['errors'][0], 'the setting that is missing is named');
        $this->assertSame(['username'], $svc->missing('edx'));
        Http::assertNothingSent();
    }
}
