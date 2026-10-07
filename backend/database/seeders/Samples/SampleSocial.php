<?php

namespace Database\Seeders\Samples;

use App\Gamification\Defaults;
use App\Gamification\GamificationService;
use App\Models\Badge;
use App\Models\Challenge;
use App\Models\ChallengeParticipant;
use App\Models\GamificationProfile;
use App\Models\PointLedger;
use App\Models\Poll;
use App\Models\Reward;
use App\Models\RewardRedemption;
use App\Models\Space;
use App\Models\UserBadge;
use App\Social\PostService;
use App\Social\SpaceService;

/** Communities, forums, a trainer channel, polls, events and reports; then points, badges, challenges, rewards and the leaderboard. */
class SampleSocial
{
    public function __construct(private readonly SampleContext $c, private readonly SpaceService $spaces, private readonly PostService $posts, private readonly GamificationService $game) {}

    public function run(): void
    {
        $this->social();
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
