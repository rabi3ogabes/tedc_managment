<?php

namespace App\Services\Notifications;

/**
 * Every automatic notification of the platform ("action"), with its default wording. Administrators can switch
 * each one off and rewrite it (Settings → Notification templates); the placeholders are filled when it is sent.
 */
class NotificationCatalog
{
    /** Placeholders usable in a template. */
    public const VARIABLES = ['name', 'program', 'program_code', 'session', 'date', 'time', 'status', 'center'];

    /** @return array<string, array{group: string, name_ar: string, name_en: string, title_ar: string, title_en: string, body_ar: string, body_en: string}> */
    public static function events(): array
    {
        $registration = fn (string $ar, string $en) => ['group' => 'registration', 'name_ar' => "تسجيل: {$ar}", 'name_en' => "Registration: {$en}",
            'title_ar' => 'تحديث حالة التسجيل', 'title_en' => 'Registration update',
            'body_ar' => 'تسجيلك في برنامج «{{program}}» {{status}}.', 'body_en' => 'Your registration for "{{program}}" is {{status}}.'];

        return [
            'program.assigned' => ['group' => 'registration', 'name_ar' => 'إسناد برنامج إلى متدرب', 'name_en' => 'Program assigned to a trainee',
                'title_ar' => 'تم إسنادك إلى برنامج تدريبي', 'title_en' => 'You have been assigned to a program',
                'body_ar' => 'تم تسجيلك في برنامج «{{program}}» — يبدأ {{date}}. راجع تفاصيله في تطبيقك.', 'body_en' => 'You have been enrolled in "{{program}}" — it starts {{date}}. See the details in your app.'],
            'registration.approved' => $registration('اعتماد', 'approved'),
            'registration.rejected' => $registration('رفض', 'rejected'),
            'registration.pending' => $registration('قيد المراجعة', 'under review'),
            'registration.waitlisted' => $registration('قائمة الانتظار', 'waiting list'),
            'registration.cancelled' => $registration('إلغاء', 'cancelled'),
            'registration.completed' => $registration('إكمال', 'completed'),
            'program.invite' => ['group' => 'program', 'name_ar' => 'دعوة إلى برنامج جديد', 'name_en' => 'Invitation to a new program',
                'title_ar' => 'برنامج تدريبي جديد يناسبك', 'title_en' => 'A new training program for you',
                'body_ar' => '«{{program}}» متاح للتسجيل ضمن الفئة المستهدفة.', 'body_en' => '"{{program}}" is open to your group.'],
            'survey.open' => ['group' => 'survey', 'name_ar' => 'استبيان البرنامج متاح', 'name_en' => 'Program survey is open',
                'title_ar' => 'استبيان البرنامج متاح الآن', 'title_en' => 'The program survey is open',
                'body_ar' => 'يمكنك الآن تعبئة استبيان تقييم برنامج «{{program}}». رأيك يهمّنا.', 'body_en' => 'You can now fill in the evaluation survey of "{{program}}". Your opinion matters.'],
            'session.reminder' => ['group' => 'session', 'name_ar' => 'تذكير بجلسة', 'name_en' => 'Session reminder',
                'title_ar' => 'تذكير بجلسة تدريبية', 'title_en' => 'Training session reminder',
                'body_ar' => 'جلسة «{{session}}» من برنامج «{{program}}» تبدأ {{date}} {{time}}.', 'body_en' => '"{{session}}" ({{program}}) starts {{date}} {{time}}.'],
            'session.attendance_open' => ['group' => 'session', 'name_ar' => 'بدء تسجيل الحضور', 'name_en' => 'Check-in is open',
                'title_ar' => 'بدأ تسجيل الحضور', 'title_en' => 'Check-in is open',
                'body_ar' => 'افتح التطبيق وامسح رمز الحضور لجلسة «{{session}}». يتطلب التسجيل وجودك في مكان التدريب.', 'body_en' => 'Open the app and scan the code for "{{session}}". You must be at the venue to check in.'],
            'session.attendance_missed' => ['group' => 'session', 'name_ar' => 'لم تسجّل حضورك', 'name_en' => 'Check-in missed',
                'title_ar' => 'لم تسجّل حضورك بعد', 'title_en' => 'You have not checked in yet',
                'body_ar' => 'بدأت جلسة «{{session}}». سجّل حضورك الآن قبل أن يُحتسب تأخير أكبر.', 'body_en' => '"{{session}}" has started. Check in now to avoid being marked late.'],
            'task.approved' => ['group' => 'task', 'name_ar' => 'المهام: اعتماد', 'name_en' => 'Task: approved', 'title_ar' => 'تم اعتماد مهمتك', 'title_en' => 'Your task was approved', 'body_ar' => '{{program}}', 'body_en' => '{{program}}'],
            'task.needs_changes' => ['group' => 'task', 'name_ar' => 'المهام: مطلوب تعديل', 'name_en' => 'Task: changes requested', 'title_ar' => 'مطلوب تعديل على مهمتك', 'title_en' => 'Changes requested on your task', 'body_ar' => '{{program}}', 'body_en' => '{{program}}'],
            'course.completed' => ['group' => 'course', 'name_ar' => 'إتمام المحتوى التدريبي', 'name_en' => 'Course completed',
                'title_ar' => 'أتممت المحتوى التدريبي 🎉', 'title_en' => 'You completed the course 🎉',
                'body_ar' => 'أنهيت كل دروس برنامج «{{program}}».', 'body_en' => 'You finished every lesson of "{{program}}".'],
            'course.nudge' => ['group' => 'course', 'name_ar' => 'تذكير بمتابعة المحتوى', 'name_en' => 'Course reminder',
                'title_ar' => 'أكمل من حيث توقفت', 'title_en' => 'Pick up where you left off',
                'body_ar' => 'محتوى برنامج «{{program}}» ينتظرك — تبقّى لك القليل.', 'body_en' => 'The content of "{{program}}" is waiting for you — you are close.'],
            'certificate.issued' => ['group' => 'certificate', 'name_ar' => 'إصدار الشهادة', 'name_en' => 'Certificate issued',
                'title_ar' => 'تم إصدار شهادتك', 'title_en' => 'Your certificate is ready',
                'body_ar' => 'تهانينا! صدرت شهادة إتمام برنامج «{{program}}».', 'body_en' => 'Congratulations! Your certificate for "{{program}}" has been issued.'],
            'certificate.available' => ['group' => 'certificate', 'name_ar' => 'الشهادة جاهزة للتحميل (متدرب)', 'name_en' => 'Certificate ready to download (trainee)',
                'title_ar' => 'شهادتك جاهزة للتحميل', 'title_en' => 'Your certificate is ready to download',
                'body_ar' => 'شكرًا لتعبئة الاستبيان. يمكنك الآن تحميل شهادة برنامج «{{program}}» من تطبيقك.', 'body_en' => 'Thank you for the survey. You can now download your certificate for "{{program}}" in the app.'],
            'certificate.survey_needed' => ['group' => 'certificate', 'name_ar' => 'الشهادة بانتظار الاستبيان', 'name_en' => 'Certificate waiting for the survey',
                'title_ar' => 'شهادتك بانتظار تعبئة الاستبيان', 'title_en' => 'Your certificate is waiting for the survey',
                'body_ar' => 'صدرت شهادة برنامج «{{program}}». عبّئ استبيان البرنامج لتتمكن من تحميلها.', 'body_en' => 'Your certificate for "{{program}}" is issued. Fill in the program survey to download it.'],
            'certificate.trainer_available' => ['group' => 'certificate', 'name_ar' => 'شهادة شكر المدرب جاهزة', 'name_en' => 'Trainer thank-you certificate ready',
                'title_ar' => 'شهادة الشكر والتقدير جاهزة', 'title_en' => 'Your thank-you certificate is ready',
                'body_ar' => 'أكملتَ ساعات برنامج «{{program}}». يمكنك الآن تحميل شهادة الشكر والتقدير من تطبيقك.', 'body_en' => 'You completed your hours in "{{program}}". You can now download your thank-you certificate in the app.'],
            'certificate.sent' => ['group' => 'certificate', 'name_ar' => 'إرسال الشهادة بالبريد', 'name_en' => 'Certificate e-mailed',
                'title_ar' => 'وصلتك شهادتك', 'title_en' => 'Your certificate was sent',
                'body_ar' => 'أُرسلت شهادة «{{program}}» إلى بريدك الإلكتروني.', 'body_en' => 'The certificate for "{{program}}" was sent to your e-mail.'],
            'impact.survey' => ['group' => 'survey', 'name_ar' => 'استبيان قياس الأثر', 'name_en' => 'Impact survey',
                'title_ar' => 'استبيان أثر التدريب', 'title_en' => 'Training impact survey',
                'body_ar' => 'شاركنا كيف طبّقت ما تعلمته في برنامج «{{program}}».', 'body_en' => 'Tell us how you applied what you learned in "{{program}}".'],
            'profile.request_approved' => ['group' => 'account', 'name_ar' => 'الموافقة على طلب تعديل بيانات', 'name_en' => 'Data change request approved',
                'title_ar' => 'تمت الموافقة على طلب تعديل بياناتك', 'title_en' => 'Your change request was approved', 'body_ar' => 'راجع بياناتك في «حسابي».', 'body_en' => 'Check your details in "My account".'],
            'profile.request_rejected' => ['group' => 'account', 'name_ar' => 'رفض طلب تعديل بيانات', 'name_en' => 'Data change request rejected',
                'title_ar' => 'لم تتم الموافقة على طلب التعديل', 'title_en' => 'Your change request was not approved', 'body_ar' => 'راجع التفاصيل في «حسابي».', 'body_en' => 'See the details in "My account".'],
            'needs_survey.invite' => ['group' => 'survey', 'name_ar' => 'دعوة إلى استبانة الاحتياجات', 'name_en' => 'Needs survey invitation',
                'title_ar' => 'استبانة جديدة', 'title_en' => 'New survey', 'body_ar' => 'شاركنا احتياجاتك التدريبية — تستغرق دقائق قليلة.', 'body_en' => 'Share your training needs — it only takes a few minutes.'],
            'needs_survey.reminder' => ['group' => 'survey', 'name_ar' => 'تذكير باستبانة الاحتياجات', 'name_en' => 'Needs survey reminder',
                'title_ar' => 'تذكير باستبانة', 'title_en' => 'Survey reminder', 'body_ar' => 'لم نستلم إجاباتك بعد، رأيك يصنع برامج التدريب القادمة.', 'body_en' => 'We have not received your answers yet — your input shapes the next programs.'],
        ];
    }

    public static function groups(): array
    {
        return ['registration' => ['ar' => 'التسجيل والإسناد', 'en' => 'Registration & assignment'], 'program' => ['ar' => 'البرامج', 'en' => 'Programs'], 'survey' => ['ar' => 'الاستبيانات', 'en' => 'Surveys'],
            'session' => ['ar' => 'الجلسات والحضور', 'en' => 'Sessions & attendance'], 'task' => ['ar' => 'المهام', 'en' => 'Tasks'], 'certificate' => ['ar' => 'الشهادات', 'en' => 'Certificates'], 'course' => ['ar' => 'المحتوى التدريبي', 'en' => 'Online course'], 'account' => ['ar' => 'الحساب', 'en' => 'Account'], 'custom' => ['ar' => 'قوالب مخصصة', 'en' => 'Custom templates']];
    }
}
