<?php

namespace App\Help;

use App\Models\Role;

/** The steps of the guided tours: a short welcome per role and the «what's new» tour of the current release. */
class TourCatalog
{
    private static function step(string $ar, string $en, string $bodyAr, string $bodyEn, ?string $target = null): array
    {
        return ['title_ar' => $ar, 'title_en' => $en, 'body_ar' => $bodyAr, 'body_en' => $bodyEn, 'target' => $target];
    }

    public static function firstLogin(string $role): array
    {
        $common = [
            self::step('مرحبًا بك في بوابة التدريب', 'Welcome to the training portal', 'هذه جولة قصيرة تعرّفك بأهم الأماكن. يمكنك تخطيها وإعادتها من مركز المساعدة في أي وقت.', 'A short tour of the main places. You can skip it and replay it from the help centre at any time.'),
            self::step('زر المساعدة', 'The help button', 'زر «؟» في أعلى كل صفحة يعرض المقالات المتعلقة بالصفحة التي أنت فيها، مع فيديوهات قصيرة.', 'The "?" button at the top of every page shows the articles for the page you are on, with short videos.', 'help-button'),
            self::step('الإشعارات', 'Notifications', 'تصلك التنبيهات هنا وعبر البريد والرسائل القصيرة وتطبيق الجوال حسب تفضيلاتك.', 'Alerts arrive here and by email, SMS and the mobile app, according to your preferences.', 'notifications'),
        ];
        $own = match ($role) {
            Role::EMPLOYEE => [self::step('تدريبي', 'My training', 'سجّل في البرامج، وتابع حضورك ودورات التعلم الإلكتروني وشهاداتك من صفحة «تدريبي».', 'Register for programs and follow your attendance, e-courses and certificates from "My training".', 'my-training')],
            Role::TRAINER => [self::step('جلساتي', 'My sessions', 'تجد جدولك وسجل الحضور والمهام والتقييمات لكل مجموعة تدرّبها.', 'Your timetable, attendance, tasks and assessments for every group you teach.', 'my-training')],
            Role::SCHOOL_ADMIN, Role::SUPERVISOR, Role::ACADEMIC_DEPUTY => [self::step('موظفو مدرستك', 'Your staff', 'اعتمد الترشيحات وتابع تدريب موظفيك واحتياجاتهم من لوحة المدرسة.', 'Approve nominations and follow your staff\'s training and needs from the school dashboard.', 'dashboard')],
            default => [self::step('لوحة العمل', 'Your dashboard', 'تبدأ من اللوحة الرئيسية، وتظهر لك الصفحات التي تسمح بها صلاحياتك فقط.', 'You start at the dashboard; you only see the pages your permissions allow.', 'dashboard')],
        };

        return [...$common, ...$own];
    }

    public static function whatsNew(): array
    {
        return [
            self::step('جديد: مركز المساعدة', 'New: the help centre', 'أدلة لكل دور، وفيديوهات، وتنزيل PDF، وجولات إرشادية.', 'Manuals for every role, videos, PDF downloads and guided tours.'),
            self::step('جديد: المجتمعات والإنجازات', 'New: communities and achievements', 'اسأل المدربين، وشارك زملاءك، واجمع الشارات.', 'Ask trainers, share with colleagues and collect badges.'),
            self::step('جديد: المساعد الذكي والتوصيات', 'New: the smart assistant and recommendations', 'اقتراحات مخصصة لك ومساعد يجيب من محتوى المنصة.', 'Suggestions made for you and an assistant that answers from the portal\'s own content.'),
        ];
    }
}
