<?php

namespace App\Services\Cms;

/** Ready-made layouts for the public home page: the administrator picks one, adjusts it in the editor and publishes. */
class HomeLayouts
{
    private static function t(string $ar, string $en): array
    {
        return ['ar' => $ar, 'en' => $en];
    }

    private static function b(string $type, array $config, int $i): array
    {
        return ['id' => null, 'type' => $type, 'config' => $config, 'sort_order' => $i, 'is_visible' => true, 'audience' => 'public', 'starts_at' => null, 'ends_at' => null];
    }

    private static function hero(string $titleAr, string $titleEn, string $subAr, string $subEn, string $labelAr, string $labelEn, string $url): array
    {
        return ['slides' => [['title' => self::t($titleAr, $titleEn), 'subtitle' => self::t($subAr, $subEn), 'label' => self::t($labelAr, $labelEn), 'url' => $url, 'image' => null]]];
    }

    private static function cta(string $titleAr, string $titleEn, string $textAr, string $textEn, string $labelAr, string $labelEn, string $url): array
    {
        return ['title' => self::t($titleAr, $titleEn), 'text' => self::t($textAr, $textEn), 'label' => self::t($labelAr, $labelEn), 'url' => $url];
    }

    /** @return list<array{id: string, name_ar: string, name_en: string, description_ar: string, description_en: string, blocks: list<array<string, mixed>>}> */
    public static function all(): array
    {
        $stats = self::t('المركز بالأرقام', 'The centre in numbers');
        $programs = fn (int $n) => ['title' => self::t('برامج مميزة', 'Featured programs'), 'limit' => $n];
        $news = fn (int $n) => ['title' => self::t('آخر الأخبار', 'Latest news'), 'limit' => $n];
        $events = fn (int $n) => ['title' => self::t('فعاليات قادمة', 'Upcoming events'), 'limit' => $n];
        $faq = ['title' => self::t('أسئلة شائعة', 'Frequently asked questions'), 'items' => [
            ['question' => self::t('كيف أسجل في برنامج؟', 'How do I register?'), 'answer' => self::t('<p>من صفحة البرامج اختر البرنامج ثم «سجّل».</p>', '<p>From the programs page choose a program, then Register.</p>')],
            ['question' => self::t('كيف أحصل على شهادتي؟', 'How do I get my certificate?'), 'answer' => self::t('<p>تظهر في «شهاداتي» بعد إتمام شروط النجاح.</p>', '<p>It appears under My certificates once you meet the pass conditions.</p>')],
        ]];

        return [
            ['id' => 'classic', 'name_ar' => 'الكلاسيكي', 'name_en' => 'Classic', 'description_ar' => 'البطل، الأرقام، البرامج، الأخبار والفعاليات.', 'description_en' => 'Hero, numbers, programs, news and events.', 'blocks' => [
                self::b('hero_slider', ['slides' => []], 0), self::b('stats', ['title' => $stats], 1), self::b('featured_programs', $programs(6), 2), self::b('news', $news(3), 3), self::b('events', $events(3), 4),
            ]],
            ['id' => 'programs_first', 'name_ar' => 'البرامج أولًا', 'name_en' => 'Programs first', 'description_ar' => 'للتسجيل: البرامج المميزة مباشرة بعد البطل مع دعوة للتسجيل.', 'description_en' => 'For registration season: featured programs straight after the hero, with a call to register.', 'blocks' => [
                self::b('hero_slider', self::hero('ابدأ رحلتك التدريبية', 'Start your training journey', 'برامج معتمدة لتطوير مهاراتك المهنية.', 'Accredited programs to grow your professional skills.', 'استعرض البرامج', 'Browse programs', '/programs'), 0),
                self::b('featured_programs', $programs(9), 1), self::b('cta', self::cta('سجّل اليوم', 'Register today', 'المقاعد محدودة في كل مجموعة.', 'Seats are limited in each group.', 'سجّل الآن', 'Register now', '/programs'), 2),
                self::b('stats', ['title' => $stats], 3), self::b('news', $news(3), 4),
            ]],
            ['id' => 'news_events', 'name_ar' => 'الأخبار والفعاليات', 'name_en' => 'News and events', 'description_ar' => 'يبرز ما يجري في المركز: الأخبار والفعاليات القادمة.', 'description_en' => 'Puts what is happening at the centre first: news and upcoming events.', 'blocks' => [
                self::b('hero_slider', ['slides' => []], 0), self::b('news', $news(6), 1), self::b('events', $events(6), 2), self::b('stats', ['title' => $stats], 3), self::b('featured_programs', $programs(3), 4),
            ]],
            ['id' => 'teachers_day', 'name_ar' => 'يوم المعلم', 'name_en' => "Teachers' Day", 'description_ar' => 'رسالة شكر للمعلمين وفيديو ودعوة لبرامج تطوير المعلم.', 'description_en' => 'A thank-you message to teachers, a video and a call to teacher-development programs.', 'blocks' => [
                self::b('hero_slider', self::hero('شكرًا لمن يصنع الأجيال', 'Thank you to those who shape generations', 'في يوم المعلم نحتفي بعطائكم ونواصل دعم نموّكم المهني.', "On Teachers' Day we celebrate your work and keep supporting your growth.", 'برامج المعلمين', 'Programs for teachers', '/programs'), 0),
                self::b('rich_text', ['title' => self::t('كلمة المركز', 'A word from the centre'), 'body' => self::t('<p>المعلم هو الأساس. نقدّم لكم برامج تطوير مهني تراعي وقتكم وتدعم أثركم في الصف.</p>', '<p>The teacher is the foundation. We offer professional-development programs that respect your time and strengthen your impact in the classroom.</p>')], 1),
                self::b('featured_programs', $programs(6), 2), self::b('stats', ['title' => $stats], 3),
                self::b('cta', self::cta('كل معلم يستحق فرصة للنمو', 'Every teacher deserves a chance to grow', 'سجّل في برنامجك القادم.', 'Register for your next program.', 'سجّل الآن', 'Register now', '/programs'), 4),
            ]],
            ['id' => 'ramadan', 'name_ar' => 'رمضان', 'name_en' => 'Ramadan', 'description_ar' => 'رسالة رمضان، مواعيد العمل في الشهر الكريم، وبرامج مختارة.', 'description_en' => 'A Ramadan greeting, working hours in the holy month and selected programs.', 'blocks' => [
                self::b('hero_slider', self::hero('رمضان كريم', 'Ramadan Kareem', 'نتمنى لكم شهرًا مباركًا مليئًا بالعطاء والتعلّم.', 'Wishing you a blessed month of giving and learning.', 'برامج رمضان', 'Ramadan programs', '/programs'), 0),
                self::b('rich_text', ['title' => self::t('مواعيد العمل في رمضان', 'Working hours in Ramadan'), 'body' => self::t('<p>تُقام البرامج صباحًا بين التاسعة والثانية ظهرًا، وتتاح المنصة على مدار الساعة.</p>', '<p>Programs run in the morning between 9:00 and 14:00; the platform is open around the clock.</p>')], 1),
                self::b('featured_programs', $programs(6), 2), self::b('events', $events(3), 3), self::b('faq', $faq, 4),
            ]],
            ['id' => 'national_day', 'name_ar' => 'اليوم الوطني', 'name_en' => 'National Day', 'description_ar' => 'احتفاء باليوم الوطني مع الأرقام والأخبار.', 'description_en' => 'A National Day celebration with the numbers and the news.', 'blocks' => [
                self::b('hero_slider', self::hero('كل عام وقطر بخير', 'Happy Qatar National Day', 'نفخر بوطننا ونواصل بناء الإنسان.', 'We are proud of our nation and keep investing in people.', 'اكتشف برامجنا', 'Discover our programs', '/programs'), 0),
                self::b('stats', ['title' => $stats], 1), self::b('news', $news(3), 2), self::b('events', $events(3), 3), self::b('featured_programs', $programs(3), 4),
            ]],
            ['id' => 'minimal', 'name_ar' => 'مبسّط', 'name_en' => 'Minimal', 'description_ar' => 'بطل وبرامج ودعوة واحدة: صفحة هادئة وسريعة.', 'description_en' => 'A hero, programs and one call to action: calm and quick.', 'blocks' => [
                self::b('hero_slider', ['slides' => []], 0), self::b('featured_programs', $programs(6), 1),
                self::b('cta', self::cta('جاهز للبدء؟', 'Ready to begin?', 'سجّل دخولك وتابع تدريبك من مكان واحد.', 'Sign in and follow your training in one place.', 'تسجيل الدخول', 'Sign in', '/login'), 2),
            ]],
        ];
    }

    public static function find(string $id): ?array
    {
        foreach (self::all() as $l) {
            if ($l['id'] === $id) {
                return $l;
            }
        }

        return null;
    }
}
