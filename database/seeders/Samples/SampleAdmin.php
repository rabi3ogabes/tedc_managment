<?php

namespace Database\Seeders\Samples;

use App\Learning\EKits\EKitBuilder;
use App\Learning\EKits\EKitSource;
use App\Models\ContactMessage;
use App\Models\DataSubjectRequest;
use App\Models\HelpArticle;
use App\Models\HelpFeedback;
use App\Models\IntegrationLog;
use App\Models\MigrationBatch;
use App\Models\ProfileChangeRequest;
use App\Models\School;
use App\Models\SchoolGroup;
use App\Models\SharingPolicy;
use App\Models\SupportTicket;
use App\Models\UserIdentity;
use App\Models\WebhookSubscription;
use Illuminate\Support\Str;

/** Support tickets, data-subject requests, contact messages, sharing rules, school groups, integration logs, webhooks, profile changes, the two e-kits and help feedback. */
class SampleAdmin
{
    use Steps;

    public function __construct(private readonly SampleContext $c) {}

    public function run(): void
    {
        $this->step('support', fn () => $this->support());
        $this->step('org', fn () => $this->org());
        $this->step('integrations', fn () => $this->integrations());
        $this->step('ekits', fn () => $this->ekits());
        $this->step('help', fn () => $this->help());
    }

    private function support(): void
    {
        $t = $this->c->trainees();
        if (! $t || SupportTicket::where('subject', 'like', '[عينة]%')->exists()) {
            return;
        }
        foreach ([['bug', 'normal', '[عينة] الصفحة لا تفتح بعد تسجيل الدخول', 'queued', null], ['request', 'low', '[عينة] طلب تعديل بيانات الاتصال', 'open', 'SAAED-10234'], ['access', 'high', '[عينة] لا أستطيع الدخول للمقرر', 'closed', 'SAAED-10188']] as $i => [$cat, $prio, $subject, $status, $no]) {
            SupportTicket::create(['user_id' => $t[$i % count($t)]->id, 'category' => $cat, 'priority' => $prio, 'subject' => $subject, 'description' => 'وصف مفصّل للمشكلة كما كتبه المستخدم.', 'page_url' => 'https://example.qa/portal', 'context' => ['platform' => 'web'], 'status' => $status, 'saaed_ticket_no' => $no, 'saaed_status' => $status === 'closed' ? 'Resolved' : ($no ? 'In progress' : null), 'attempts' => $status === 'queued' ? 1 : 0]);
        }
        DataSubjectRequest::firstOrCreate(['user_id' => $t[0]->id, 'type' => 'access'], ['details' => 'أرغب في نسخة من بياناتي الشخصية.', 'status' => 'received', 'due_at' => now()->addDays(28)]);
        DataSubjectRequest::firstOrCreate(['user_id' => $t[1]->id, 'type' => 'correction'], ['details' => 'تصحيح كتابة اسمي بالإنجليزية.', 'status' => 'in_progress', 'due_at' => now()->addDays(20), 'handled_by' => $this->c->admin()->id]);
        ContactMessage::firstOrCreate(['email' => 'visitor.sample@example.qa', 'subject' => 'استفسار عن البرامج'], ['name' => 'زائر تجريبي', 'phone' => '55507777', 'message' => 'هل تتوفر برامج للمعلمين الجدد خلال الفصل القادم؟', 'status' => 'new']);
        if ($e = $t[0]->employee) {
            ProfileChangeRequest::firstOrCreate(['user_id' => $t[0]->id, 'field' => 'phone'], ['kind' => 'update', 'current_value' => '55500000', 'requested_value' => '55512345', 'note' => 'تغيير الرقم', 'status' => 'pending']);
        }
    }

    private function org(): void
    {
        $g = SchoolGroup::firstOrCreate(['code' => 'SMP-NORTH'], ['name_ar' => 'مدارس المنطقة الشمالية', 'name_en' => 'Northern region schools', 'type' => 'cluster', 'description' => 'عنقود تجريبي']);
        $ids = School::query()->limit(5)->pluck('id');
        if ($ids->isNotEmpty()) {
            \DB::table('school_group_school')->insertOrIgnore($ids->map(fn ($id) => ['school_group_id' => $g->id, 'school_id' => $id])->all());
        }
        SharingPolicy::firstOrCreate(['role' => 'trainer'], ['resource_types' => ['material', 'kit_file'], 'target_types' => ['program', 'group'], 'allow_reshare' => false, 'allow_download' => true, 'watermark' => true]);
    }

    private function integrations(): void
    {
        $admin = $this->c->admin();
        if (IntegrationLog::where('correlation_id', 'like', 'smp-%')->exists()) {
            return;
        }
        foreach ([['teams', 'out', 'create_meeting', 'ok', 340], ['saaed', 'out', 'create_ticket', 'ok', 210], ['hudhud', 'out', 'send_push', 'error', 1200], ['hr', 'in', 'sync_employees', 'ok', 880]] as $i => [$key, $dir, $op, $status, $ms]) {
            IntegrationLog::create(['integration_key' => $key, 'direction' => $dir, 'operation' => $op, 'status' => $status, 'duration_ms' => $ms, 'request_summary' => 'عينة', 'response_summary' => $status === 'ok' ? '200' : null, 'error' => $status === 'error' ? 'انتهت مهلة الاتصال' : null, 'correlation_id' => 'smp-'.Str::random(8), 'created_at' => now()->subMinutes(15 * ($i + 1))]);
        }
        WebhookSubscription::firstOrCreate(['name' => 'نظام الأرشفة (عينة)'], ['url' => 'https://example.qa/hooks/tedc', 'secret' => Str::random(32), 'events' => ['registration.approved', 'certificate.issued'], 'enabled' => false, 'created_by' => $admin->id]);
        MigrationBatch::firstOrCreate(['kind' => 'employees', 'filename' => 'employees-sample.csv', 'checksum' => sha1('employees-sample')], ['status' => 'validated', 'mapping' => [], 'value_maps' => [], 'defaults' => [], 'total_rows' => 120, 'valid_rows' => 116, 'invalid_rows' => 3, 'duplicate_rows' => 1, 'created_rows' => 0, 'updated_rows' => 0, 'report' => ['notes' => 'عينة للتجربة'], 'created_by' => $admin->id, 'expires_at' => now()->addDays(14)]);
        if ($u = $this->c->user('teacher@tedc.qa')) {
            UserIdentity::firstOrCreate(['user_id' => $u->id, 'provider' => 'entra'], ['subject' => 'entra-sample-'.substr($u->id, 0, 8), 'upn' => 'teacher@example.onmicrosoft.com', 'last_login_at' => now()->subDays(2)]);
        }
    }

    private function ekits(): void
    {
        foreach (EKitSource::all() as $kit) {
            app(EKitBuilder::class)->build($kit);   // leaves a kit that is already published untouched
        }
    }

    private function help(): void
    {
        $t = $this->c->trainees();
        $a = HelpArticle::where('slug', 'register-for-a-program')->first();
        if ($a && $t && ! HelpFeedback::where('article_id', $a->id)->exists()) {
            HelpFeedback::create(['article_id' => $a->id, 'user_id' => $t[0]->id, 'helpful' => true, 'article_version' => $a->version]);
            HelpFeedback::create(['article_id' => $a->id, 'user_id' => $t[1]->id, 'helpful' => false, 'comment' => 'أحتاج مثالًا على الأهلية.', 'article_version' => $a->version]);
        }
    }
}
