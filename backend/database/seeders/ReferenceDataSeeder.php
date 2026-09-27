<?php

namespace Database\Seeders;

use App\Models\Department;
use App\Models\JobTitle;
use App\Models\ProgramCategory;
use App\Models\Skill;
use App\Models\TrainingRoom;
use Illuminate\Database\Seeder;

class ReferenceDataSeeder extends Seeder
{
    public function run(): void
    {
        $jobTitles = [
            ['TEACHER', 'معلم', 'Teacher', 'teaching'],
            ['SENIOR_TEACHER', 'معلم أول', 'Senior Teacher', 'teaching'],
            ['SUBJECT_COORD', 'منسق مادة', 'Subject Coordinator', 'leadership'],
            ['VICE_PRINCIPAL', 'نائب مدير', 'Vice Principal', 'leadership'],
            ['PRINCIPAL', 'مدير مدرسة', 'School Principal', 'leadership'],
            ['ACADEMIC_ADVISOR', 'مرشد أكاديمي', 'Academic Advisor', 'teaching'],
            ['SOCIAL_SPECIALIST', 'أخصائي اجتماعي', 'Social Specialist', 'support'],
            ['PSYCH_SPECIALIST', 'أخصائي نفسي', 'Psychological Specialist', 'support'],
            ['LEARNING_RESOURCES', 'أخصائي مصادر التعلم', 'Learning Resources Specialist', 'support'],
            ['IT_SPECIALIST', 'أخصائي تقنية معلومات', 'IT Specialist', 'administrative'],
            ['ADMIN_OFFICER', 'موظف إداري', 'Administrative Officer', 'administrative'],
        ];
        foreach ($jobTitles as [$code, $ar, $en, $cat]) {
            JobTitle::updateOrCreate(['code' => $code], ['name_ar' => $ar, 'name_en' => $en, 'category' => $cat]);
        }

        $departments = [
            ['ARABIC', 'اللغة العربية', 'Arabic Language'], ['ENGLISH', 'اللغة الإنجليزية', 'English Language'],
            ['MATH', 'الرياضيات', 'Mathematics'], ['SCIENCE', 'العلوم', 'Science'], ['ISLAMIC', 'التربية الإسلامية', 'Islamic Education'],
            ['SOCIAL', 'الدراسات الاجتماعية', 'Social Studies'], ['COMPUTING', 'الحوسبة وتكنولوجيا المعلومات', 'Computing & IT'],
            ['PE', 'التربية البدنية', 'Physical Education'], ['ADMIN', 'الشؤون الإدارية', 'Administration'], ['STUDENT_AFFAIRS', 'شؤون الطلاب', 'Student Affairs'],
        ];
        foreach ($departments as [$code, $ar, $en]) {
            Department::updateOrCreate(['code' => $code, 'school_id' => null], ['name_ar' => $ar, 'name_en' => $en]);
        }

        $skills = [
            ['classroom_management', 'إدارة الصف', 'Classroom Management', 'pedagogy'],
            ['differentiated_instruction', 'التعليم المتمايز', 'Differentiated Instruction', 'pedagogy'],
            ['active_learning', 'استراتيجيات التعلم النشط', 'Active Learning Strategies', 'pedagogy'],
            ['assessment_for_learning', 'التقويم من أجل التعلم', 'Assessment for Learning', 'assessment'],
            ['data_driven_instruction', 'التدريس المبني على البيانات', 'Data-Driven Instruction', 'assessment'],
            ['ai_in_education', 'الذكاء الاصطناعي في التعليم', 'AI Tools for Education', 'digital'],
            ['digital_content', 'تصميم المحتوى الرقمي', 'Digital Content Design', 'digital'],
            ['blended_learning', 'التعلم المدمج', 'Blended Learning', 'digital'],
            ['educational_leadership', 'القيادة التربوية', 'Educational Leadership', 'leadership'],
            ['strategic_planning', 'التخطيط الاستراتيجي المدرسي', 'School Strategic Planning', 'leadership'],
            ['coaching_mentoring', 'الإرشاد والتوجيه المهني', 'Coaching & Mentoring', 'leadership'],
            ['inclusive_education', 'التعليم الدامج', 'Inclusive Education', 'wellbeing'],
            ['student_wellbeing', 'الرفاه النفسي للطلاب', 'Student Wellbeing', 'wellbeing'],
            ['behavior_management', 'تعديل السلوك', 'Behaviour Management', 'wellbeing'],
            ['stem_pedagogy', 'تدريس العلوم والتقنية والهندسة والرياضيات', 'STEM Pedagogy', 'pedagogy'],
            ['arabic_literacy', 'تنمية مهارات القراءة بالعربية', 'Arabic Literacy', 'pedagogy'],
            ['research_skills', 'البحث الإجرائي', 'Action Research', 'professional'],
            ['communication', 'مهارات التواصل الفعال', 'Effective Communication', 'professional'],
        ];
        foreach ($skills as [$code, $ar, $en, $cat]) {
            Skill::updateOrCreate(['code' => $code], ['name_ar' => $ar, 'name_en' => $en, 'category' => $cat]);
        }

        $categories = [
            ['leadership', 'القيادة المدرسية', 'School Leadership', 'crown', '#0B1F3A'],
            ['pedagogy', 'طرائق التدريس', 'Teaching & Pedagogy', 'presentation', '#1E3A8A'],
            ['digital', 'التحول الرقمي والذكاء الاصطناعي', 'Digital & AI', 'cpu', '#0E7490'],
            ['assessment', 'التقويم والقياس', 'Assessment & Measurement', 'chart', '#7C3AED'],
            ['wellbeing', 'الرفاه والدعم الطلابي', 'Wellbeing & Student Support', 'heart', '#BE185D'],
            ['professional', 'التطوير المهني', 'Professional Growth', 'briefcase', '#B45309'],
        ];
        foreach ($categories as [$slug, $ar, $en, $icon, $color]) {
            ProgramCategory::updateOrCreate(['slug' => $slug], ['name_ar' => $ar, 'name_en' => $en, 'icon' => $icon, 'color' => $color]);
        }

        $rooms = [
            ['قاعة الريادة', 'Al Riyada Hall', 'المبنى الرئيسي', 60, ['projector', 'smart_board', 'video_conference']],
            ['قاعة الإبداع', 'Al Ibdaa Lab', 'المبنى الرئيسي', 30, ['computers', 'smart_board']],
            ['قاعة الأثر', 'Al Athar Room', 'مبنى الابتكار', 40, ['projector', 'recording']],
            ['مختبر المستقبل', 'Future Lab', 'مبنى الابتكار', 24, ['vr', 'computers', '3d_printer']],
        ];
        foreach ($rooms as [$ar, $en, $building, $capacity, $facilities]) {
            TrainingRoom::updateOrCreate(['name_en' => $en], ['name_ar' => $ar, 'building' => $building, 'capacity' => $capacity, 'facilities' => $facilities, 'latitude' => 25.3176, 'longitude' => 51.4386]);
        }
    }
}
