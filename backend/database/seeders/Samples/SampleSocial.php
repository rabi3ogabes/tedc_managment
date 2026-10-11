<?php

namespace Database\Seeders\Samples;

use App\Gamification\Defaults;
use App\Gamification\GamificationService;
use App\Models\Badge;
use App\Models\Challenge;
use App\Models\ChallengeParticipant;
use App\Models\GamificationProfile;
use App\Models\LessonNote;
use App\Models\Material;
use App\Models\PointLedger;
use App\Models\Poll;
use App\Models\Post;
use App\Models\Reward;
use App\Models\RewardRedemption;
use App\Models\SocialUpload;
use App\Models\Space;
use App\Models\UserBadge;
use App\Services\FileStorage;
use App\Social\PostService;
use App\Social\SocialFiles;
use App\Social\SpaceService;
use Illuminate\Support\Str;

/** Communities, forums, a trainer channel, polls, events, reports and shared files; then points, badges, challenges, rewards and the leaderboard. */
class SampleSocial
{
    public function __construct(private readonly SampleContext $c, private readonly SpaceService $spaces, private readonly PostService $posts, private readonly GamificationService $game, private readonly FileStorage $storage) {}

    public function run(): void
    {
        $this->social();
        $this->sharedFiles();
        $this->gamification();
    }

    private function social(): void
    {
        $admin = $this->c->admin();
        $trainer = $this->c->user('trainer@tedc.qa') ?? $admin;
        $t = $this->c->trainees();
        if (Space::where('title_en', 'Active learning practice community')->exists() || count($t) < 2) {
            return;
        }
        $comm = $this->spaces->create($trainer, ['title_ar' => 'مجتمع ممارسات التعلم النشط', 'title_en' => 'Active learning practice community', 'description_ar' => 'شاركوا تجاربكم الصفية وأسئلتكم.', 'description_en' => 'Share your classroom experience and questions.', 'visibility' => 'members', 'join_policy' => 'open']);
        $comm2 = $this->spaces->create($admin, ['title_ar' => 'مجتمع القيادة المدرسية', 'title_en' => 'School leadership community', 'description_ar' => 'نقاش بين قادة المدارس.', 'description_en' => 'A conversation among school leaders.', 'visibility' => 'members', 'join_policy' => 'request']);
        foreach ([...$t, $this->c->user('school@tedc.qa')] as $u) {
            if ($u) {
                $this->spaces->join($comm, $u);
            }
        }
        $q = $this->posts->create($comm, $t[0], ['kind' => 'question', 'title' => 'كيف أبدأ حصة بنشاط تمهيدي؟', 'body' => 'أبحث عن أفكار لأنشطة افتتاحية لا تتجاوز خمس دقائق لصف من ثلاثين طالبًا.']);
        $a = $this->posts->comment($q, $trainer, ['body' => 'جرّب «فكّر – زاوج – شارك»: سؤال واحد، دقيقة تفكير فردي، ثم نقاش ثنائي.']);
        $this->posts->comment($q, $t[1], ['body' => 'وأنا أستخدم صورة غامضة وأطلب من الطلاب توقّع الدرس.']);
        $this->posts->acceptAnswer($q, $a, $t[0]);
        $d = $this->posts->create($comm, $trainer, ['kind' => 'discussion', 'title' => 'ما أفضل أدوات التقويم السريع؟', 'body' => 'شاركونا الأدوات التي وفّرت عليكم الوقت في التقويم التكويني.']);
        $this->posts->comment($d, $t[1], ['body' => 'بطاقات الخروج من الحصة (Exit tickets) بسيطة وفعّالة.']);
        $this->posts->react('post', $d->id, $t[0]);
        $this->posts->react('post', $d->id, $t[1]);
        $this->posts->create($comm, $trainer, ['kind' => 'announcement', 'title' => 'لقاء افتراضي هذا الأسبوع', 'body' => 'سنلتقي مساء الخميس لمناقشة نتائج ورشة التقويم.']);
        $poll = $this->posts->create($comm, $trainer, ['kind' => 'poll', 'title' => 'أي موضوع تفضلون في الورشة القادمة؟', 'body' => 'صوّتوا لموضوع الورشة القادمة.', 'poll' => ['options' => ['التقويم التكويني', 'إدارة الصف', 'التعلم الرقمي', 'التفكير الناقد'], 'multiple' => false]]);
        $p = $poll->poll ?? Poll::where('post_id', $poll->id)->first();
        if ($p) {
            $this->posts->vote($p, $t[0], ['o1']);
            $this->posts->vote($p, $t[1], ['o3']);
        }
        $ev = $this->spaces->createEvent($comm, $trainer, ['title' => 'لقاء مجتمع التعلم النشط', 'starts_at' => now()->addDays(3)->setTime(18, 0), 'ends_at' => now()->addDays(3)->setTime(19, 0), 'online_url' => 'https://teams.example.qa/meet/active-learning', 'agenda' => 'عرض تجارب + أسئلة وأجوبة', 'rsvp_required' => true]);
        foreach ($t as $i => $u) {
            $this->spaces->rsvp($ev, $u, $i % 2 ? 'maybe' : 'going');
        }
        $spam = $this->posts->create($comm, $t[1], ['kind' => 'discussion', 'title' => 'عرض ترويجي', 'body' => 'اشتروا دورات خارجية بخصم كبير!']);
        $this->posts->report('post', $spam->id, $t[0], 'spam', 'محتوى إعلاني لا علاقة له بالمجتمع.');
        $this->posts->create($comm2, $admin, ['kind' => 'discussion', 'title' => 'قيادة التغيير في المدرسة', 'body' => 'كيف تُشركون الهيئة التعليمية في خطط التحسين؟']);

        // Forums of a program and a group, the trainers' channel and a lesson thread.
        if ($prog = $this->c->program('TEST-P1')) {
            $forum = $this->spaces->programForum($prog);
            $this->spaces->syncMembers($forum);
            $this->posts->create($forum, $admin, ['kind' => 'announcement', 'title' => 'مرحبًا بكم في منتدى البرنامج', 'body' => 'اطرحوا أسئلتكم هنا وسيجيب عنها فريق التدريب.']);
        }
        foreach ($this->c->groups(1) as $g) {
            $gf = $this->spaces->groupForum($g);
            $this->spaces->syncMembers($gf);
            $this->posts->create($gf, $admin, ['kind' => 'announcement', 'title' => 'موعد أول لقاء', 'body' => 'نبدأ يوم الأحد في القاعة الرئيسية.']);
        }
        $channel = $this->spaces->trainersChannel();
        $this->spaces->syncMembers($channel);
        $this->posts->create($channel, $admin, ['kind' => 'discussion', 'title' => 'تبادل مواد تدريبية', 'body' => 'أرفع هنا عروض الورشة الأخيرة ليستفيد منها الزملاء.']);
    }

    /** A post with a photo and a PDF in the community, a discussion beside a material, and a private note on it (PLC-03, COL-02). */
    private function sharedFiles(): void
    {
        $comm = Space::where('title_en', 'Active learning practice community')->first();
        $trainer = $this->c->user('trainer@tedc.qa') ?? $this->c->admin();
        if (! $comm || SocialUpload::exists()) {
            return;
        }
        $board = $this->store($trainer->id, $comm->id, 'لوحة نشاط التهيئة.png', $this->samplePng(), 'image/png', 960, 540);
        $guide = $this->store($trainer->id, $comm->id, 'دليل بطاقات الخروج.pdf', $this->samplePdf(), 'application/pdf');
        $post = Post::create(['space_id' => $comm->id, 'author_id' => $trainer->id, 'kind' => 'resource', 'title' => 'نموذج لوحة التهيئة ودليل بطاقات الخروج',
            'body' => '<p>هذه اللوحة التي استخدمتها في الحصة، ومعها دليل مختصر لبطاقات الخروج.</p>', 'status' => 'published',
            'attachments' => collect([$board, $guide])->map(fn (SocialUpload $u) => ['type' => 'file', 'upload_id' => $u->id, 'name' => $u->name, 'mime' => $u->mime, 'size' => $u->size, 'width' => $u->width, 'height' => $u->height])
                ->push(['type' => 'link', 'url' => 'https://www.edu.gov.qa', 'name' => 'بوابة الوزارة'])->all()]);
        app(SocialFiles::class)->attach(collect([$board, $guide]), 'post', $post->id);
        $comm->increment('posts_count');

        $t = $this->c->trainees();
        $material = Material::first();
        if ($material && $t) {
            $thread = $this->spaces->materialThread($material);
            $this->spaces->syncMembers($thread);
            Post::create(['space_id' => $thread->id, 'author_id' => $t[0]->id, 'kind' => 'question', 'title' => null, 'body' => '<p>هل الشريحة الخاصة بالتقويم متاحة بصيغة قابلة للتعديل؟</p>', 'status' => 'published']);
            $thread->increment('posts_count');
            LessonNote::create(['user_id' => $t[0]->id, 'subject_type' => 'material', 'subject_id' => $material->id, 'body' => 'أراجع الجزء الثاني قبل اللقاء القادم.']);
        }
    }

    private function store(string $userId, string $spaceId, string $name, string $bytes, string $mime, ?int $w = null, ?int $h = null): SocialUpload
    {
        $ext = pathinfo($name, PATHINFO_EXTENSION);
        $path = $this->storage->put(SocialFiles::BUCKET, "social/{$userId}/".Str::uuid().".{$ext}", $bytes, $mime);

        return SocialUpload::create(['user_id' => $userId, 'space_id' => $spaceId, 'path' => $path, 'name' => $name, 'mime' => $mime, 'size' => strlen($bytes), 'width' => $w, 'height' => $h, 'status' => 'pending']);
    }

    /** A plain maroon-and-gold board (no personal data, no metadata). */
    private function samplePng(): string
    {
        $img = imagecreatetruecolor(960, 540);
        for ($y = 0; $y < 540; $y++) {
            $mix = $y / 539;
            imageline($img, 0, $y, 959, $y, imagecolorallocate($img, (int) (138 + (162 - 138) * $mix), (int) (21 + (148 - 21) * $mix), (int) (56 + (117 - 56) * $mix)));
        }
        imagefilledrectangle($img, 80, 200, 880, 340, imagecolorallocatealpha($img, 255, 255, 255, 90));
        ob_start();
        imagepng($img);

        return (string) ob_get_clean();
    }

    private function samplePdf(): string
    {
        return "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 595 842]>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF";
    }

    private function gamification(): void
    {
        Defaults::seed();
        $t = $this->c->trainees();
        $admin = $this->c->admin();
        if (! $t || PointLedger::where('note', 'sample')->exists()) {
            return;
        }
        $events = [['lesson_completed', 10], ['assessment_passed', 20], ['comment_posted', 2], ['daily_login', 2], ['post_created', 5], ['course_completed', 50]];
        foreach ($t as $i => $u) {
            GamificationProfile::firstOrCreate(['user_id' => $u->id], ['hidden' => false]);
            for ($d = 0; $d < 10 + $i * 4; $d++) {
                [$ev, $pts] = $events[($d + $i) % count($events)];
                PointLedger::create(['user_id' => $u->id, 'event' => $ev, 'points' => $pts, 'note' => 'sample', 'created_at' => now()->subDays($d % 12)->subHours($d)]);
            }
        }
        foreach (Badge::whereIn('code', ['first_lesson', 'conversation_starter'])->get() as $b) {
            foreach (array_slice($t, 0, 3) as $u) {
                UserBadge::firstOrCreate(['user_id' => $u->id, 'badge_id' => $b->id], ['source' => 'auto', 'awarded_at' => now()->subDays(3)]);
            }
        }
        if ($b = Badge::where('code', 'course_finisher')->first()) {
            UserBadge::firstOrCreate(['user_id' => $t[0]->id, 'badge_id' => $b->id], ['source' => 'manual', 'awarded_at' => now()->subDay()]);
        }
        $ch = Challenge::firstOrCreate(['title_en' => 'Seven days of learning'], ['title_ar' => 'سبعة أيام من التعلّم', 'description_ar' => 'أكمل درسًا كل يوم لمدة أسبوع.', 'description_en' => 'Finish a lesson every day for a week.', 'starts_at' => now()->subDays(2), 'ends_at' => now()->addDays(12), 'audience' => ['all' => true], 'goal' => ['event' => 'lesson_completed', 'count' => 7], 'reward' => ['points' => 50], 'type' => 'individual', 'is_active' => true]);
        foreach ($t as $i => $u) {
            ChallengeParticipant::firstOrCreate(['challenge_id' => $ch->id, 'user_id' => $u->id], ['progress' => min(7, 2 + $i * 2), 'completed_at' => $i === 3 ? now()->subHours(5) : null]);
        }
        $r1 = Reward::firstOrCreate(['title_en' => 'Certificate of excellence in learning'], ['title_ar' => 'شهادة تميّز في التعلّم', 'description_ar' => 'شهادة تقدير رقمية.', 'description_en' => 'A digital certificate of appreciation.', 'kind' => 'certificate', 'cost_points' => 100, 'min_level' => 1, 'stock' => 50, 'is_active' => true]);
        Reward::firstOrCreate(['title_en' => 'Free seat in a workshop'], ['title_ar' => 'مقعد مجاني في ورشة', 'description_ar' => 'قسيمة لورشة من اختيارك.', 'description_en' => 'A voucher for a workshop of your choice.', 'kind' => 'voucher', 'cost_points' => 400, 'min_level' => 2, 'stock' => 10, 'is_active' => true]);
        RewardRedemption::firstOrCreate(['reward_id' => $r1->id, 'user_id' => $t[0]->id], ['points' => $r1->cost_points, 'code' => 'RW-SAMPLE-1', 'status' => 'fulfilled']);
        $this->game->snapshot();
    }
}
