<?php

namespace Database\Seeders;

use App\Models\CalendarApproval;
use App\Models\CalendarDay;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;

/**
 * Sample training calendar: Qatar public holidays, school vacations, exam periods and a few restricted
 * working days. Lunar dates (Eid) are approximate and can be adjusted by administrators.
 */
class DemoCalendarSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::where('email', 'admin@tedc.qa')->first();

        // [from, to, type, ar, en, notes]
        $rows = [
            ['2026-10-25', '2026-10-29', 'exam', 'اختبارات منتصف الفصل الأول', 'First-semester mid-term exams', 'لا تُجدول برامج تدريبية للمعلمين خلال فترة الاختبارات.'],
            ['2026-11-15', '2026-11-19', 'exam', 'اختبارات المهارات الوطنية', 'National skills assessments', null],
            ['2026-12-18', '2026-12-18', 'vacation', 'اليوم الوطني لدولة قطر', 'Qatar National Day', 'إجازة رسمية.'],
            ['2027-01-10', '2027-01-21', 'exam', 'اختبارات نهاية الفصل الأول', 'End-of-first-semester exams', null],
            ['2027-01-24', '2027-01-28', 'vacation', 'إجازة منتصف العام الدراسي', 'Mid-year break', null],
            ['2027-02-09', '2027-02-09', 'vacation', 'اليوم الرياضي للدولة', 'National Sports Day', 'إجازة رسمية (ثاني ثلاثاء من فبراير).'],
            ['2027-03-08', '2027-03-14', 'vacation', 'إجازة عيد الفطر', 'Eid al-Fitr holiday', 'تاريخ تقريبي بحسب رؤية الهلال.'],
            ['2027-05-16', '2027-05-22', 'vacation', 'إجازة عيد الأضحى', 'Eid al-Adha holiday', 'تاريخ تقريبي بحسب رؤية الهلال.'],
            ['2027-05-30', '2027-06-10', 'exam', 'اختبارات نهاية العام الدراسي', 'End-of-year exams', null],
            ['2027-07-01', '2027-08-15', 'vacation', 'الإجازة الصيفية', 'Summer vacation', 'لا يوجد تدريب في الإجازة الصيفية إلا بموافقة الإدارة.'],
            ['2026-10-06', '2026-10-06', 'normal', 'يوم التسجيل العام للمدارس', 'School registration day', 'يوم عمل عادي مغلق للتدريب لانشغال المدارس بالتسجيل.'],
            ['2026-11-03', '2026-11-03', 'normal', 'اجتماع مديري المدارس', 'School principals meeting', 'يوم عمل عادي مغلق للتدريب.'],
            ['2027-02-16', '2027-02-16', 'normal', 'يوم التخطيط الإداري', 'Administrative planning day', 'يوم عمل عادي مغلق للتدريب.'],
        ];

        foreach ($rows as [$from, $to, $type, $ar, $en, $notes]) {
            for ($day = CarbonImmutable::parse($from); $day->lte(CarbonImmutable::parse($to)); $day = $day->addDay()) {
                CalendarDay::updateOrCreate(['date' => $day->toDateString()], [
                    'type' => $type, 'title_ar' => $ar, 'title_en' => $en, 'notes' => $notes, 'created_by' => $admin?->id,
                ]);
            }
        }

        // One approved exception, so the approval flow is visible on the calendar.
        CalendarApproval::updateOrCreate(['date' => '2026-11-03'], ['reason' => 'ورشة تدريبية لمديري المدارس بعد الاجتماع', 'approved_by' => $admin?->id]);
    }
}
