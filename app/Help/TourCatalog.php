<?php

namespace App\Help;

use App\Models\Role;

/**
 * The guided tours. The welcome tour opens with the steps below — what everyone shares, then the story of the person's own role from
 * the first action to the last — and the web app follows them with one chapter per section of the menu the person can actually
 * see, so every feature they may use is shown, and none they may not. A step may carry an icon, bullet points and a page to open.
 */
class TourCatalog
{
    /** @param list<string> $pointsAr @param list<string> $pointsEn */
    private static function step(string $ar, string $en, string $bodyAr, string $bodyEn, ?string $target = null, string $icon = 'sparkles', array $pointsAr = [], array $pointsEn = [], ?string $route = null): array
    {
        return ['title_ar' => $ar, 'title_en' => $en, 'body_ar' => $bodyAr, 'body_en' => $bodyEn, 'target' => $target, 'icon' => $icon, 'points_ar' => $pointsAr, 'points_en' => $pointsEn, 'route' => $route];
    }

    /** What every account shares. */
    private static function common(): array
    {
        return [
            self::step('مرحبًا بك في منصة التدريب', 'Welcome to the training platform', 'هذه جولة تعرّفك بكل ما تتيحه المنصة لدورك، خطوة بخطوة. يمكنك تخطيها وإعادتها من مركز المساعدة في أي وقت.', 'This tour shows everything the platform offers your role, step by step. You can skip it and replay it from the help centre at any time.', null, 'sparkles'),
            self::step('ابحث عن أي شيء', 'Find anything', 'اضغط Ctrl+K (أو Cmd+K) في أي صفحة لتبحث في الصفحات والإعدادات والبرامج وتنتقل إليها مباشرة.', 'Press Ctrl+K (or Cmd+K) on any page to search pages, settings and programs and jump straight to them.', null, 'search', ['بحث شامل بالعربية والإنجليزية', 'اختصارات للتنقل السريع'], ['Search in Arabic and English', 'Shortcuts for quick navigation']),
            self::step('زر المساعدة', 'The help button', 'زر «؟» في أعلى كل صفحة يعرض المقالات والفيديوهات المتعلقة بالصفحة التي أنت فيها، ويفتح مركز المساعدة والتذاكر.', 'The "?" button at the top of every page shows the articles and videos for the page you are on, and opens the help centre and tickets.', 'help-button', 'life-buoy'),
            self::step('الإشعارات', 'Notifications', 'تصلك التنبيهات هنا وعبر الإشعارات الفورية والبريد والرسائل القصيرة وتطبيق الجوال حسب تفضيلاتك، ويمكنك ضبط ما يصلك ومتى.', 'Alerts arrive here and as push, e-mail, SMS and in the mobile app, as you prefer — and you decide what reaches you and when.', 'notifications', 'bell', [], [], '/portal/notification-preferences'),
            self::step('اللغة والحساب والأمان', 'Language, account and security', 'بدّل بين العربية والإنجليزية، وراجع ملفك الشخصي، وفعّل التحقق بخطوتين، وتحكّم في خصوصية بياناتك من قائمة حسابك.', 'Switch between Arabic and English, review your profile, turn on two-step verification and control your data privacy from your account menu.', null, 'user-cog'),
        ];
    }

    public static function firstLogin(string $role): array
    {
        return [...self::common(), ...self::journey($role)];
    }

    /** The story of one role: what they do, in the order they do it. */
    private static function journey(string $role): array
    {
        return match ($role) {
            Role::EMPLOYEE => [
                self::step('سجّل في البرامج', 'Register for programs', 'تصفّح البرامج المتاحة لك وسجّل ذاتياً، أو تلقَّ ترشيحاً من مدرستك أو من مركز التدريب.', 'Browse the programs open to you and register yourself, or be nominated by your school or the training centre.', 'my-training', 'book-open', ['التسجيل الذاتي والترشيح وقوائم الانتظار', 'معرفة أهليتك وسبب عدم الأهلية', 'إشعار فور اعتماد التسجيل'], ['Self-registration, nomination and waiting lists', 'Know why you are or are not eligible', 'A notice as soon as you are approved'], '/portal/training'),
                self::step('احضر وتعلّم', 'Attend and learn', 'سجّل حضورك بمسح الرمز في القاعة أو بالانضمام للجلسة عن بُعد، وتابع الدورات الإلكترونية والمهام والاختبارات والتقييمات.', 'Mark your attendance by scanning the code in the room or joining the remote session, and follow e-courses, tasks, assessments and evaluations.', null, 'calendar-check', ['رمز الحضور والبصمة وعن بُعد', 'دورات إلكترونية ومهام واختبارات قبلية وبعدية', 'تقييم البرنامج والمدرب بعد الانتهاء'], ['Attendance by code, fingerprint or remote join', 'E-courses, tasks, pre- and post-tests', 'Evaluate the program and the trainer when it ends'], '/portal/tasks'),
                self::step('انمُ مهنياً', 'Grow professionally', 'شهاداتك وجواز التدريب وساعاتك ومسارك المهني واحتياجاتك التدريبية ومكتبتك في مكان واحد.', 'Your certificates, training passport, hours, career path, training needs and library in one place.', null, 'award', ['شهادات يمكن التحقق منها', 'مسار الترخيص والتطوير المهني', 'طلب احتياج تدريبي جديد', 'مجتمعات التعلم والإنجازات'], ['Verifiable certificates', 'Licensing and professional-development path', 'Raise a new training need', 'Learning communities and achievements'], '/portal/passport'),
            ],
            Role::TRAINER => [
                self::step('مكتب التدريس', 'Your teaching desk', 'برامجك ومجموعاتك وقاعاتك والمشرف عليها وحقائبك التدريبية، وبدء الجلسة، من مكان واحد. وعند إسنادك إلى برنامج يصلك إشعار ونافذة تعريفية.', 'Your programs, groups, rooms, supervisor and training kits, and starting a session, from one place. When you are assigned a program you get a notification and a popup.', 'nav:/admin/teaching', 'presentation', ['الحقيبة التدريبية ملك المركز التدريبي: تُعرض ولا تُنزَّل', 'اسمك على كل صفحة وكل محاولة تصوير تُسجَّل'], ['The training kit is the property of the training centre: shown, never downloaded', 'Your name is on every page and every capture attempt is logged'], '/admin/teaching'),
                self::step('قدّم من الموقع مباشرة', 'Present straight from the site', 'افتح العرض التقديمي في استوديو العرض: الشريحة التالية وملاحظات المدرب وساعة الجلسة وقلم ومؤشر وتكبير، ونافذة ثانية لشاشة القاعة.', 'Open the presentation in the presenter studio: the next slide, speaker notes, session clock, pen, pointer, magnifier, and a second window for the room screen.', null, 'monitor-play', ['شاشة سوداء وبيضاء ومؤقت استراحة', 'اختيار متدرب عشوائي من قائمة الحضور', 'ملاحظاتك الخاصة لكل شريحة'], ['Black and white screens and a break timer', 'Pick a random trainee from the roster', 'Your own private notes for each slide']),
                self::step('الحضور والمهام والتقييم', 'Attendance, tasks and assessments', 'اعرض رمز الحضور، وسجّل الغياب والأعذار، وصحّح المهام والاختبارات، وأرسل تقييماتك.', 'Show the attendance code, record absences and excuses, grade tasks and tests, and send your evaluations.', null, 'clipboard-check', ['رمز دوّار وماسح للحضور', 'بنوك الأسئلة والاختبارات وتصحيحها', 'استبانة التأمل الذاتي للمدرب'], ['Rotating attendance code and scanner', 'Question banks, tests and grading', 'The trainer self-reflection survey']),
                self::step('طلبات الإسناد والمجتمعات', 'Assignments and communities', 'تلقَّ اقتراحات الإسناد وعبّئ نموذجها، وأجب أسئلة المتدربين، وشارك في مجتمعات التعلم.', 'Receive assignment proposals and fill in their form, answer trainees\' questions and take part in learning communities.', null, 'users', [], [], '/admin/my-assignments'),
            ],
            Role::SCHOOL_ADMIN, Role::SUPERVISOR, Role::ACADEMIC_DEPUTY => [
                self::step('موظفو مدرستك', 'Your school\'s staff', 'ابحث عن الموظفين وتابع ساعاتهم وشهاداتهم وما سجّلوا فيه، وراجع ملفاتهم.', 'Find your staff and follow their hours, certificates and registrations, and review their files.', 'dashboard', 'school'),
                self::step('رشّح واعتمد', 'Nominate and approve', 'رشّح موظفيك للبرامج (بالمفرد أو بالجملة)، واعتمد طلباتهم قبل وصولها لمركز التدريب، وتلقَّ إشعاراً حين يرشّح المركز أحدهم.', 'Nominate your staff for programs (singly or in bulk), approve their requests before they reach the training centre, and be notified when the centre nominates one.', null, 'clipboard-check', ['ترشيح فردي وجماعي واستيراد من ملف', 'اعتماد المدير المباشر', 'إشعارك عند فتح التسجيل في برنامج لموظفيك'], ['Individual, group and file-import nominations', 'Direct-manager approval', 'A notice when registration opens for your staff']),
                self::step('الاحتياجات والأثر', 'Needs and impact', 'ارفع احتياجات مدرستك التدريبية، وأجب استبانات المركز، وتابع أثر التدريب على الأداء بعد أشهر.', 'Raise your school\'s training needs, answer the centre\'s surveys and follow the impact of training on performance months later.', null, 'target', [], [], '/admin/needs-hub'),
            ],
            Role::COORDINATOR, Role::CENTER_ADMIN, Role::SUPER_ADMIN => [
                self::step('دورة البرنامج كاملة', 'The whole program cycle', 'من الاحتياج إلى الخطة السنوية إلى البرنامج فالتسجيل فالتنفيذ فالتقييم فالشهادة فالأثر، كلها في مكان واحد.', 'From the need to the annual plan, the program, registration, delivery, evaluation, certificate and impact — all in one place.', 'nav:/admin/programs', 'route', ['برنامج جديد بالصوت أو الكتابة مع جلسات وقاعات تلقائية', 'رمز البرنامج من نوعه، وحقائب واستبانات واختبارات مولَّدة', 'إشعار المدرب ومسؤول النظام في المدرسة والمتدرب'], ['A new program by voice or text with automatic sessions and rooms', 'The code from its type, with generated kits, surveys and tests', 'Notices to the trainer, the school\'s system administrator and the trainee'], '/admin/programs'),
                self::step('الحقائب والجودة', 'Kits and quality', 'استوديو الحقائب التدريبية: إعداد ومراجعة واعتماد ونشر، وحماية الحقيبة لأنها ملك المركز التدريبي.', 'The training kit studio: develop, review, approve and publish, and protect the kit because it belongs to the training centre.', 'nav:/admin/kits', 'package-open', [], [], '/admin/kits'),
                self::step('الاعتماد والمتابعة', 'Approvals and follow-up', 'اعتماد التسجيل والانسحاب، ومتابعة الحضور المباشر والغياب، والقاعات واللوجستيات، وخدمة الدعم.', 'Approve registrations and withdrawals, follow live attendance and absences, rooms and logistics, and the support desk.', null, 'shield-check'),
                self::step('التقارير والإعدادات', 'Reports and settings', 'تقارير ولوحات مؤشرات وتحليلات، وإعدادات الهوية والإشعارات والقنوات والأدوار والأمان.', 'Reports, KPI dashboards and analytics; and settings for identity, notifications, channels, roles and security.', null, 'bar-chart'),
            ],
            Role::TRAINING_HEAD, Role::CENTER_LEADERSHIP, Role::EXECUTIVE => [
                self::step('الصورة الكاملة', 'The full picture', 'لوحات تنفيذية بمؤشرات الأداء والتغطية والرضا والأثر، مع إمكانية التفصيل حتى المدرسة والمدرب.', 'Executive dashboards with performance, coverage, satisfaction and impact indicators, drilling down to the school and the trainer.', 'nav:/admin/analytics', 'bar-chart', ['توقعات ومخاطر', 'مقارنة بين الفترات والمناطق', 'تقارير تُصدَّر Excel وPDF'], ['Forecasts and risks', 'Comparisons across periods and regions', 'Reports exported to Excel and PDF'], '/admin/analytics'),
                self::step('القرار والمتابعة', 'Decide and follow up', 'اعتمد الخطط والاستثناءات، وتابع الجودة والتقييمات والشكاوى، واطّلع على سجل التدقيق.', 'Approve plans and exceptions, follow quality, evaluations and complaints, and read the audit trail.', null, 'shield-check'),
            ],
            Role::PLANNING_HEAD, Role::PLANNING_SPECIALIST => [
                self::step('الاحتياجات والأولويات', 'Needs and priorities', 'اجمع الاحتياجات من المدارس والموظفين وبيانات الأداء، وحلّل الفجوات، وحدّد الأولويات.', 'Gather needs from schools, staff and performance data, analyse the gaps and set the priorities.', 'nav:/admin/needs-hub', 'target', [], [], '/admin/needs-hub'),
                self::step('الخطة السنوية', 'The annual plan', 'ابنِ الخطة السنوية من الاحتياجات، وراجعها واعتمدها، وحوّل بنودها إلى برامج.', 'Build the annual plan from the needs, review and approve it, and turn its items into programs.', 'nav:/admin/plans', 'calendar-check', [], [], '/admin/plans'),
            ],
            Role::LOGISTICS_OFFICER => [
                self::step('القاعات والحجوزات', 'Rooms and bookings', 'قاعات المركز وسعتها وتجهيزاتها وحجوزاتها، وتفادي التعارض تلقائياً، وشاشات القاعات.', 'The centre\'s rooms with their capacity, equipment and bookings, automatic clash avoidance, and the room screens.', 'nav:/admin/rooms', 'building', [], [], '/admin/rooms'),
                self::step('اللوجستيات', 'Logistics', 'ضيافة ومواد وتجهيزات كل جلسة، وتشغيل القاعات يوماً بيوم.', 'Catering, materials and set-up for every session, and day-by-day running of the rooms.', null, 'package-open', [], [], '/admin/logistics'),
            ],
            Role::FINANCE_OFFICER => [
                self::step('المدفوعات وقوائم الأسعار', 'Payments and price lists', 'قوائم أسعار البرامج، وطلبات الدفع والإيصالات، وحسابات الجهات الخارجية.', 'Program price lists, payment orders and receipts, and external entities\' accounts.', 'nav:/admin/finance', 'wallet', [], [], '/admin/finance'),
                self::step('التقارير المالية', 'Financial reports', 'تقارير الإيرادات والمستحقات وتصديرها.', 'Revenue and receivables reports and their export.', null, 'bar-chart'),
            ],
            Role::KIT_DEVELOPER => [
                self::step('استوديو الحقائب', 'The kit studio', 'ارفع ملفات الحقيبة أو ابنِ العروض في المحرر مع ذكاء اصطناعي للشرائح والصور، وتتبّع الإصدارات.', 'Upload the kit\'s files or build presentations in the editor with AI for slides and images, and track the versions.', 'nav:/admin/kits', 'package-open', ['عروض جاهزة للعرض من الموقع', 'أدوات تقويم قبلية وبعدية', 'إصدارات وتعليقات'], ['Presentations ready to present from the site', 'Pre- and post-assessment tools', 'Versions and comments'], '/admin/kits'),
                self::step('المراجعة والنشر', 'Review and publish', 'أرسل الحقيبة للمراجعة، وعالج الملاحظات، وانتظر الاعتماد والنشر.', 'Send the kit for review, resolve the comments, and wait for approval and publishing.', null, 'shield-check'),
            ],
            Role::QA_REVIEWER => [
                self::step('مراجعة الحقائب', 'Reviewing kits', 'افحص الحقائب بقوائم الجودة، وعلّق على المواضع، واطلب التعديل أو اعتمد.', 'Check kits against quality checklists, comment on the exact spot, and request changes or approve.', 'nav:/admin/kits', 'shield-check', [], [], '/admin/kits'),
            ],
            default => [
                self::step('لوحة العمل', 'Your dashboard', 'تبدأ من اللوحة الرئيسية، وتظهر لك الصفحات التي تسمح بها صلاحياتك فقط.', 'You start at the dashboard; you only see the pages your permissions allow.', 'dashboard', 'layout-dashboard'),
            ],
        };
    }

    public static function whatsNew(): array
    {
        return [
            self::step('جديد: مكتب التدريس', 'New: the teaching desk', 'إشعار الإسناد ونافذته، وقارئ الحقيبة المحمي، واستوديو العرض من الموقع.', 'The assignment notice and popup, the protected kit reader, and the presenter studio on the site.', null, 'presentation', [], [], '/admin/teaching'),
            self::step('جديد: البرنامج الجديد الذكي', 'New: the smart new-program form', 'أمر صوتي، ورمز تلقائي، وجلسات وقاعات مولَّدة، وحقائب واستبانات واختبارات.', 'A voice order, an automatic code, generated sessions and rooms, kits, surveys and tests.', null, 'sparkles'),
            self::step('جديد: المجتمعات والإنجازات', 'New: communities and achievements', 'اسأل المدربين، وشارك زملاءك، واجمع الشارات.', 'Ask trainers, share with colleagues and collect badges.', null, 'users'),
        ];
    }
}
