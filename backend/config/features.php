<?php

/*
| Feature flags. `default` is used outside production, `production` (falls back to `default`) in production.
| `unsafe` tools are the ones the audit found risky on a live system: in production they start off, and switching
| them on needs a reason, `users.manage`, and shows a banner. DB overrides (Settings → Features) win over these defaults.
*/
return [
    'flags' => [
        'impersonation' => ['phase' => 0, 'unsafe' => true, 'default' => true, 'production' => false,
            'title' => ['ar' => 'الدخول بحساب مستخدم آخر', 'en' => 'Sign in as another user'],
            'description' => ['ar' => 'يتيح لمدير النظام فتح حساب مستخدم لفحص مشكلة. كل استخدام مسجّل في سجل التدقيق.', 'en' => 'Lets a system administrator open a user\'s account to check a problem. Every use is written to the audit log.']],
        'test_accounts' => ['phase' => 0, 'unsafe' => true, 'default' => true, 'production' => false,
            'title' => ['ar' => 'الحسابات التجريبية', 'en' => 'Test accounts'],
            'description' => ['ar' => 'إنشاء متدربين ومدربين تجريبيين وعرض بيانات دخولهم لتجربة التطبيق.', 'en' => 'Creates demo trainees and trainers and shows their sign-in details for trying the app.']],
        'demo_scenarios' => ['phase' => 0, 'unsafe' => true, 'default' => true, 'production' => false,
            'title' => ['ar' => 'سيناريو العرض التجريبي', 'en' => 'Demo scenario'],
            'description' => ['ar' => 'يبني برامج وتسجيلات وإشعارات للعرض ويحرّكها يومياً مع التقويم.', 'en' => 'Builds programs, registrations and notifications for presentations and moves them forward every day.']],
        'self_heal' => ['phase' => 0, 'unsafe' => true, 'default' => true, 'production' => false,
            'title' => ['ar' => 'الإصلاح التلقائي للأخطاء', 'en' => 'Automatic error fixing'],
            'description' => ['ar' => 'يطبّق علاجات معروفة للأخطاء (مثل ترحيل قاعدة البيانات). عند الإيقاف يقترح العلاج فقط.', 'en' => 'Applies known remedies to errors (such as database migrations). When off, it only suggests the remedy.']],
        'ai' => ['phase' => 15, 'unsafe' => false, 'default' => true,
            'title' => ['ar' => 'الذكاء الاصطناعي', 'en' => 'Artificial intelligence'],
            'description' => ['ar' => 'المساعد الذكي والتوصيات وتوليد المحتوى. عند الإيقاف تعمل المنصة بالقواعد الثابتة.', 'en' => 'The assistant, recommendations and content generation. When off, the platform uses fixed rules.']],
        'payments' => ['phase' => 16, 'unsafe' => false, 'default' => false,
            'title' => ['ar' => 'الدفع وشراء الدورات', 'en' => 'Payments and course purchasing'],
            'description' => ['ar' => 'السلة والدفع عبر بوابة الوزارة وقسائم المقاعد والفواتير.', 'en' => 'Cart, checkout through the Ministry gateway, seat vouchers and invoices.']],
        'gamification' => ['phase' => 14, 'unsafe' => false, 'default' => false,
            'title' => ['ar' => 'التلعيب', 'en' => 'Gamification'],
            'description' => ['ar' => 'النقاط والشارات والمستويات والتحديات ولوحة المتصدرين.', 'en' => 'Points, badges, levels, challenges and the leaderboard.']],
        'proctoring' => ['phase' => 6, 'unsafe' => false, 'default' => false,
            'title' => ['ar' => 'مراقبة الاختبارات', 'en' => 'Exam proctoring'],
            'description' => ['ar' => 'رصد مغادرة الصفحة واللقطات الاختيارية للكاميرا أثناء الاختبار.', 'en' => 'Detects leaving the page and optional webcam snapshots during an exam.']],
        'plc' => ['phase' => 14, 'unsafe' => false, 'default' => false,
            'title' => ['ar' => 'مجتمعات التعلم المهنية', 'en' => 'Professional learning communities'],
            'description' => ['ar' => 'مجتمعات ونقاشات واستطلاعات وفعاليات للمعلمين.', 'en' => 'Communities, discussions, polls and events for educators.']],
        'forums' => ['phase' => 14, 'unsafe' => false, 'default' => false,
            'title' => ['ar' => 'منتديات البرامج', 'en' => 'Program forums'],
            'description' => ['ar' => 'منتدى لكل برنامج ومجموعة وقناة للمدربين.', 'en' => 'A forum for every program and group, and a trainers\' channel.']],
        'offline_mobile' => ['phase' => 10, 'unsafe' => false, 'default' => false,
            'title' => ['ar' => 'التعلم دون اتصال في التطبيق', 'en' => 'Offline learning in the app'],
            'description' => ['ar' => 'تنزيل الدروس مشفّرة ومزامنة التقدم عند عودة الاتصال.', 'en' => 'Encrypted lesson downloads and progress sync when the connection returns.']],
        'external_signup' => ['phase' => 4, 'unsafe' => false, 'default' => false,
            'title' => ['ar' => 'تسجيل المستخدمين من خارج الوزارة', 'en' => 'External user registration'],
            'description' => ['ar' => 'نموذج عام للتسجيل من خارج الوزارة مع مسار موافقة.', 'en' => 'A public sign-up form for people outside the Ministry, with an approval path.']],
    ],
];
