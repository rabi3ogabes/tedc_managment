<?php

namespace Tests\Feature;

use App\Models\AppNotification;
use App\Models\Employee;
use App\Models\RegistrationForm;
use App\Models\RegistrationRequest;
use App\Models\Role;
use App\Models\Trainer;
use App\Models\User;
use App\Services\Channels\ChannelSettings;
use App\Services\Channels\EmailSender;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class ExternalRegistrationTest extends TestCase
{
    /** @var list<array{to: string, heading: string, text: ?string}> */
    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();
        $outbox = &$this->sent;
        $this->app->instance(EmailSender::class, new class(app(ChannelSettings::class), $outbox) extends EmailSender
        {
            public function __construct(ChannelSettings $s, private array &$outbox)
            {
                parent::__construct($s);
            }

            public function send(string $to, string $heading, ?string $text, string $locale = 'ar'): array
            {
                $this->outbox[] = ['to' => $to, 'heading' => $heading, 'text' => $text];

                return ['ok' => true, 'error' => null];
            }
        });
    }

    private function form(array $extra = []): RegistrationForm
    {
        return RegistrationForm::create($extra + [
            'slug' => 'partners-2027', 'title_ar' => 'تسجيل المدربين', 'title_en' => 'Trainer registration', 'audience' => 'trainer', 'is_active' => true,
            'fields' => [
                ['key' => 'name_ar', 'type' => 'text', 'label_ar' => 'الاسم', 'label_en' => 'Name', 'required' => true],
                ['key' => 'name_en', 'type' => 'text', 'label_ar' => 'الاسم بالإنجليزية', 'label_en' => 'English name', 'required' => true],
                ['key' => 'phone', 'type' => 'phone', 'label_ar' => 'الجوال', 'label_en' => 'Phone', 'required' => false],
                ['key' => 'national_id', 'type' => 'national_id', 'label_ar' => 'الرقم الشخصي', 'label_en' => 'National ID', 'required' => false],
                ['key' => 'organization', 'type' => 'text', 'label_ar' => 'الجهة', 'label_en' => 'Organization', 'required' => false],
                ['key' => 'level', 'type' => 'select', 'label_ar' => 'المستوى', 'label_en' => 'Level', 'required' => true, 'options' => ['junior', 'senior']],
            ],
        ]);
    }

    private function code(string $slug, string $email): string
    {
        $this->postJson("/api/v1/public/forms/{$slug}/verify-email", ['email' => $email])->assertOk();
        $mail = collect($this->sent)->last(fn ($m) => $m['to'] === $email);
        preg_match('/\b(\d{6})\b/', (string) $mail['text'], $m);

        return $m[1];
    }

    private function submit(string $email, array $data = [], ?string $code = null)
    {
        return $this->postJson('/api/v1/public/forms/partners-2027/submit', ['email' => $email, 'code' => $code ?? $this->code('partners-2027', $email), 'data' => $data + ['name_ar' => 'سامي', 'name_en' => 'Sami', 'level' => 'senior']]);
    }

    public function test_only_an_active_form_inside_its_window_is_shown(): void
    {
        $this->form();
        $this->getJson('/api/v1/public/forms/partners-2027')->assertOk()->assertJsonPath('data.slug', 'partners-2027')->assertJsonCount(6, 'data.fields');
        $this->getJson('/api/v1/public/forms/nope')->assertNotFound();
        RegistrationForm::where('slug', 'partners-2027')->update(['closes_at' => now()->subDay()]);
        $this->getJson('/api/v1/public/forms/partners-2027')->assertStatus(410);
    }

    public function test_an_email_code_is_required_and_a_wrong_code_is_refused(): void
    {
        $this->form();
        $this->submit('a@example.com', [], '000000')->assertStatus(422)->assertJsonPath('code', 'code_invalid');
        $this->submit('a@example.com')->assertCreated()->assertJsonPath('data.status', 'submitted');
    }

    public function test_a_submission_is_validated_numbered_snapshotted_and_announced_to_reviewers(): void
    {
        $this->form();
        $reviewer = $this->makeUser(Role::TRAINING_HEAD);

        $this->postJson('/api/v1/public/forms/partners-2027/submit', ['email' => 'b@example.com', 'code' => $this->code('partners-2027', 'b@example.com'), 'data' => ['name_ar' => 'x']])->assertStatus(422);

        $res = $this->submit('b@example.com', ['phone' => '+97455512345', 'national_id' => '12345678901'])->assertCreated();
        $req = RegistrationRequest::find($res->json('data.id'));
        $this->assertMatchesRegularExpression('/^REQ-\d{2}-\d{4}$/', $req->number);
        $this->assertNotNull($req->snapshot_path);
        $this->assertNotNull($req->email_verified_at);
        $this->assertNotNull($req->national_id_hash);
        $this->assertTrue(AppNotification::where('user_id', $reviewer->id)->where('type', 'external_request.submitted')->exists());
        $this->assertTrue(collect($this->sent)->contains(fn ($m) => $m['to'] === 'b@example.com' && str_contains((string) $m['text'], $req->number)));
    }

    public function test_conditions_and_duplicates_are_enforced(): void
    {
        $this->form(['conditions' => ['allowed_email_domains' => ['partner.qa']]]);
        $this->postJson('/api/v1/public/forms/partners-2027/verify-email', ['email' => 'c@gmail.com'])->assertStatus(422)->assertJsonPath('code', 'email_domain_not_allowed');
        $this->postJson('/api/v1/public/forms/partners-2027/submit', ['email' => 'c@gmail.com', 'code' => '123456', 'data' => ['name_ar' => 'x']])->assertStatus(422)->assertJsonPath('code', 'email_domain_not_allowed');

        RegistrationForm::where('slug', 'partners-2027')->first()->update(['conditions' => null]);
        $this->assertNull(RegistrationForm::where('slug', 'partners-2027')->first()->conditions);
        $this->submit('d@example.com', ['national_id' => '99999999'])->assertCreated();
        $this->submit('d@example.com')->assertStatus(422)->assertJsonPath('code', 'duplicate_request');
        $this->submit('e@example.com', ['national_id' => '99999999'])->assertStatus(422)->assertJsonPath('code', 'duplicate_request');
        User::create(['name' => 'Existing', 'email' => 'f@example.com', 'password' => 'Secret#12345']);
        $this->submit('f@example.com')->assertStatus(422)->assertJsonPath('code', 'account_exists');
    }

    public function test_approving_a_trainer_creates_the_account_trainer_profile_and_an_activation_link(): void
    {
        $this->form();
        $reviewer = $this->makeUser(Role::TRAINING_HEAD);
        $id = $this->submit('g@example.com', ['organization' => 'Acme Training'])->json('data.id');

        $this->asUser($reviewer)->postJson("/api/v1/admin/registration-requests/{$id}/approve")->assertOk()->assertJsonPath('data.status', 'approved');

        $user = User::where('email', 'g@example.com')->first();
        $this->assertTrue($user->roles->contains('slug', Role::TRAINER));
        $this->assertSame('external', Trainer::where('user_id', $user->id)->value('source'));
        $mail = collect($this->sent)->last(fn ($m) => $m['to'] === 'g@example.com');
        preg_match('/token=([A-Za-z0-9]+)/', (string) $mail['text'], $m);

        $this->postJson('/api/v1/auth/activate', ['email' => 'g@example.com', 'token' => $m[1], 'password' => 'Brand#New12345', 'password_confirmation' => 'Brand#New12345'])->assertOk();
        $this->assertTrue(\Hash::check('Brand#New12345', $user->fresh()->password));
        $this->postJson('/api/v1/auth/activate', ['email' => 'g@example.com', 'token' => $m[1], 'password' => 'Another#12345', 'password_confirmation' => 'Another#12345'])->assertStatus(422);
    }

    public function test_a_trainee_request_becomes_an_employee_account_and_a_rejection_needs_a_reason_and_is_emailed(): void
    {
        $this->form(['audience' => 'trainee', 'slug' => 'partners-2027']);
        $reviewer = $this->makeUser(Role::COORDINATOR);
        $a = $this->submit('h@example.com')->json('data.id');
        $b = $this->submit('i@example.com')->json('data.id');

        $this->asUser($reviewer)->postJson("/api/v1/admin/registration-requests/{$a}/approve")->assertOk();
        $this->assertNotNull(Employee::where('user_id', User::where('email', 'h@example.com')->value('id'))->first());

        $this->asUser($reviewer)->postJson("/api/v1/admin/registration-requests/{$b}/reject")->assertStatus(422);
        $this->asUser($reviewer)->postJson("/api/v1/admin/registration-requests/{$b}/reject", ['note' => 'Not eligible'])->assertOk()->assertJsonPath('data.status', 'rejected');
        $this->assertTrue(collect($this->sent)->contains(fn ($m) => $m['to'] === 'i@example.com' && str_contains((string) $m['text'], 'Not eligible')));
    }

    public function test_more_information_can_be_requested_and_the_applicant_resubmits(): void
    {
        $this->form();
        $reviewer = $this->makeUser(Role::TRAINING_HEAD);
        $id = $this->submit('j@example.com')->json('data.id');

        $this->asUser($reviewer)->postJson("/api/v1/admin/registration-requests/{$id}/request-info", ['note' => 'Add your organisation'])->assertOk()->assertJsonPath('data.status', 'needs_info');
        $this->submit('j@example.com', ['organization' => 'Acme'])->assertCreated()->assertJsonPath('data.id', $id)->assertJsonPath('data.status', 'submitted');
        $this->assertSame('Acme', RegistrationRequest::find($id)->data['organization']);
    }

    public function test_forms_are_managed_only_by_those_who_may_and_the_code_is_throttled(): void
    {
        $head = $this->makeUser(Role::TRAINING_HEAD);
        $payload = ['title_ar' => 'نموذج', 'title_en' => 'Form', 'audience' => 'trainee', 'fields' => [['key' => 'name_ar', 'type' => 'text', 'label_ar' => 'الاسم', 'label_en' => 'Name', 'required' => true]]];

        $this->asUser($this->makeUser(Role::EMPLOYEE))->postJson('/api/v1/admin/registration-forms', $payload)->assertForbidden();
        $res = $this->asUser($head)->postJson('/api/v1/admin/registration-forms', $payload)->assertCreated();
        $this->assertNotEmpty($res->json('data.slug'));

        Cache::flush();
        $this->form(['slug' => 'partners-2027']);
        foreach (range(1, 5) as $i) {
            $this->postJson('/api/v1/public/forms/partners-2027/verify-email', ['email' => 'k@example.com']);
        }
        $this->postJson('/api/v1/public/forms/partners-2027/verify-email', ['email' => 'k@example.com'])->assertStatus(429);
    }
}
