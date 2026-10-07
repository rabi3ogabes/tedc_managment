<?php

namespace Database\Seeders\Samples;

use App\Models\Announcement;
use App\Models\AnnouncementRsvp;
use App\Models\CourseLesson;
use App\Models\DashboardPreset;
use App\Models\KpiSample;
use App\Models\LibraryCollection;
use App\Models\LibraryItem;
use App\Models\LibraryReview;
use App\Models\LibraryShelf;
use App\Models\Material;
use App\Models\NotificationRule;
use App\Models\PushLog;
use App\Models\ReportDefinition;
use App\Models\ReportSchedule;
use App\Models\ScheduledNotification;
use App\Models\UserNotificationPreference;
use App\Models\VideoInteraction;
use App\Services\Dashboards\DashboardService;
use App\Services\Reports\BuiltInReports;

/** The library and materials, announcements with RSVPs, homepage blocks, notification rules and schedules, saved reports, dashboards and indicators. */
class SampleComms
{
    use Steps;

    public function __construct(private readonly SampleContext $c) {}

    public function run(): void
    {
        $this->step('library', fn () => $this->library());
        $this->step('materials', fn () => $this->materials());
        $this->step('announcements', fn () => $this->announcements());
        $this->step('notifications', fn () => $this->notifications());
        $this->step('reports', fn () => $this->reports());
        $this->step('video', fn () => $this->video());
    }

    private function library(): void
    {
        $admin = $this->c->admin();
        if (LibraryItem::where('title_en', 'The Formative Assessment Handbook')->exists()) {
            return;
        }
        $items = [
            ['book', 'دليل التقويم التكويني', 'The Formative Assessment Handbook', 'مرجع عملي لأدوات التقويم السريع.', 'A practical reference for quick assessment tools.', ['د. منى الكواري'], 2024],
            ['article', 'التعلم النشط: ما الذي تقوله الأبحاث؟', 'Active learning: what research says', 'مراجعة مختصرة للأدلة.', 'A short review of the evidence.', ['فريق البحث التربوي'], 2025],
            ['video', 'إدارة الصف في خمس دقائق', 'Classroom management in five minutes', 'فيديو قصير بأفكار جاهزة.', 'A short video with ready ideas.', ['مركز التدريب'], 2025],
        ];
        $made = [];
        foreach ($items as [$type, $ar, $en, $dar, $den, $authors, $year]) {
            $made[] = LibraryItem::create(['type' => $type, 'title_ar' => $ar, 'title_en' => $en, 'description_ar' => $dar, 'description_en' => $den, 'authors' => $authors, 'publisher' => 'مركز التدريب والتطوير', 'year' => $year, 'language' => 'ar', 'subjects' => ['pedagogy'], 'url' => 'https://example.qa/library/'.$type, 'source' => 'manual', 'rights' => 'open', 'audience' => [], 'status' => 'published', 'search_text' => "{$ar} {$en}", 'views' => 12, 'downloads' => 3, 'created_by' => $admin->id]);
        }
        $col = LibraryCollection::firstOrCreate(['name_en' => 'Essentials for new teachers'], ['name_ar' => 'أساسيات للمعلمين الجدد', 'is_featured' => true, 'sort_order' => 1]);
        foreach ($made as $i => $item) {
            \DB::table('library_collection_items')->insertOrIgnore(['collection_id' => $col->id, 'item_id' => $item->id, 'sort_order' => $i]);
        }
        foreach ($this->c->trainees() as $i => $u) {
            LibraryReview::firstOrCreate(['item_id' => $made[0]->id, 'user_id' => $u->id], ['stars' => 4 + ($i % 2), 'review' => 'مفيد وعملي.']);
            LibraryShelf::firstOrCreate(['item_id' => $made[$i % 3]->id, 'user_id' => $u->id], ['progress' => 20 * ($i + 1)]);
        }
    }

    private function materials(): void
    {
        $admin = $this->c->admin();
        $p = $this->c->program('TEST-P1');
        if (! $p || Material::where('program_id', $p->id)->exists()) {
            return;
        }
        Material::create(['program_id' => $p->id, 'title_ar' => 'عرض اللقاء الأول', 'title_en' => 'First session slides', 'type' => 'link', 'url' => 'https://example.qa/materials/session-1', 'visibility' => 'participants', 'uploaded_by' => $admin->id]);
        Material::create(['program_id' => $p->id, 'title_ar' => 'دليل المتدرب', 'title_en' => 'Trainee guide', 'type' => 'link', 'url' => 'https://example.qa/materials/guide', 'visibility' => 'participants', 'uploaded_by' => $admin->id]);
    }

    private function announcements(): void
    {
        $admin = $this->c->admin();
        if (Announcement::where('title_en', 'Registration is open for the spring programs')->exists()) {
            return;
        }
        $a = Announcement::create(['type' => 'announcement', 'title_ar' => 'التسجيل مفتوح لبرامج الربيع', 'title_en' => 'Registration is open for the spring programs', 'body_ar' => 'يمكنكم التسجيل في البرامج الجديدة من صفحة البرامج.', 'body_en' => 'You can register for the new programs from the programs page.', 'audience' => 'all', 'is_public' => true, 'status' => 'published', 'published_at' => now()->subDay(), 'created_by' => $admin->id, 'is_pinned' => true, 'notify_push' => false, 'notify_email' => false]);
        $e = Announcement::create(['type' => 'event', 'title_ar' => 'ملتقى المعلم المبتكر', 'title_en' => 'Innovative Teacher Forum', 'body_ar' => 'ملتقى سنوي لعرض الممارسات المبتكرة.', 'body_en' => 'An annual forum to showcase innovative practice.', 'audience' => 'all', 'is_public' => true, 'status' => 'published', 'published_at' => now()->subDays(2), 'starts_at' => now()->addDays(12)->setTime(9, 0), 'ends_at' => now()->addDays(12)->setTime(14, 0), 'created_by' => $admin->id, 'event' => ['location' => 'قاعة المؤتمرات', 'capacity' => 120, 'rsvp' => true]]);
        Announcement::create(['type' => 'circular', 'title_ar' => 'تعميم: مواعيد الاختبارات الإلكترونية', 'title_en' => 'Circular: online test dates', 'body_ar' => 'تُجرى الاختبارات الإلكترونية في الأسبوع الأخير من الشهر.', 'body_en' => 'Online tests take place in the last week of the month.', 'audience' => 'all', 'is_public' => false, 'status' => 'draft', 'created_by' => $admin->id]);
        foreach ($this->c->trainees() as $i => $u) {
            AnnouncementRsvp::firstOrCreate(['announcement_id' => $e->id, 'user_id' => $u->id], ['status' => $i % 3 === 2 ? 'waitlisted' : 'going']);
        }
    }

    private function notifications(): void
    {
        $admin = $this->c->admin();
        NotificationRule::firstOrCreate(['event' => 'registration.approved', 'name' => 'اعتماد التسجيل — بريد وتطبيق'], ['audience_filter' => [], 'channels' => ['in_app', 'email', 'push'], 'enabled' => true, 'quiet_hours' => ['from' => '22:00', 'to' => '06:00'], 'delay_minutes' => 0, 'priority' => 'normal']);
        NotificationRule::firstOrCreate(['event' => 'session.reminder', 'name' => 'تذكير الجلسة قبل ساعة'], ['audience_filter' => [], 'channels' => ['in_app', 'push'], 'enabled' => true, 'delay_minutes' => 0, 'priority' => 'high']);
        ScheduledNotification::firstOrCreate(['title_en' => 'Weekly learning digest'], ['title_ar' => 'ملخص التعلّم الأسبوعي', 'body_ar' => 'ما تعلمته هذا الأسبوع وما ينتظرك.', 'body_en' => 'What you learned this week and what is next.', 'channels' => ['in_app'], 'audience' => ['all' => true], 'send_at' => now()->addDays(2)->setTime(8, 0), 'repeat' => 'weekly', 'status' => 'scheduled', 'runs' => 0, 'created_by' => $admin->id]);
        PushLog::firstOrCreate(['type' => 'announcement', 'title' => 'التسجيل مفتوح لبرامج الربيع'], ['recipients' => 120, 'devices' => 96, 'delivered' => 90, 'failed' => 4, 'pruned' => 2, 'triggered_by' => $admin->id]);
        foreach ($this->c->trainees() as $u) {
            foreach ([['sessions', 'push', true], ['announcements', 'email', false]] as [$group, $ch, $on]) {
                UserNotificationPreference::firstOrCreate(['user_id' => $u->id, 'event_group' => $group, 'channel' => $ch], ['enabled' => $on]);
            }
        }
    }

    private function reports(): void
    {
        $admin = $this->c->admin();
        app(BuiltInReports::class)->ensure();   // the built-in report list
        $def = ReportDefinition::where('key', 'programs_paths_groups')->first() ?? ReportDefinition::first();
        if ($def && ! ReportSchedule::where('definition_id', $def->id)->exists()) {
            ReportSchedule::create(['definition_id' => $def->id, 'params' => [], 'frequency' => 'weekly', 'formats' => ['xlsx'], 'recipients' => [$admin->email], 'lang' => 'ar', 'next_run_at' => now()->addDays(3), 'is_active' => true, 'created_by' => $admin->id]);
            \DB::table('report_favorites')->insertOrIgnore(['definition_id' => $def->id, 'user_id' => $admin->id]);
        }
        foreach ([['training_hours', 885], ['active_learners', 122], ['completion_rate', 78.5], ['satisfaction', 86.8]] as $i => [$metric, $value]) {
            foreach (range(0, 6) as $d) {
                KpiSample::create(['metric' => $metric, 'value' => $value - $d * ($i + 1) * 0.7, 'window' => 'day', 'measured_at' => now()->subDays($d)]);
            }
        }
        DashboardPreset::firstOrCreate(['role_slug' => 'executive'], ['widgets' => array_slice(array_keys(DashboardService::WIDGETS), 0, 4)]);
    }

    private function video(): void
    {
        $lesson = CourseLesson::where('type', 'video')->orderBy('created_at')->first();
        if ($lesson && ! VideoInteraction::where('lesson_id', $lesson->id)->exists()) {
            VideoInteraction::create(['lesson_id' => $lesson->id, 'at_seconds' => 8, 'type' => 'prompt', 'prompt_ar' => 'ما الفكرة الأساسية في هذا الجزء؟', 'prompt_en' => 'What is the main idea of this part?', 'required' => false, 'blocks_progress' => false, 'allow_skip' => true, 'sort_order' => 1]);
        }
    }
}
