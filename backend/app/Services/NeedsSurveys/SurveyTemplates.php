<?php

namespace App\Services\NeedsSurveys;

use App\Models\Skill;

/**
 * Ready-made training-needs assessment templates. Statements and options are linked to the
 * skills catalogue so the report can turn answers into training needs automatically.
 * Profile data (school, job title, experience, specialization, nationality) is captured
 * from the respondent's record, so templates never ask for it again.
 */
class SurveyTemplates
{
    public function all(): array
    {
        $skills = Skill::all()->keyBy('code');
        $s = fn (string $code) => ($skill = $skills->get($code)) ? ['skill_id' => $skill->id, 'skill_name' => $skill->name_ar] : ['skill_name' => $code];
        $row = fn (string $id, string $label, string $code) => ['id' => $id, 'label' => $label] + $s($code);
        $competence = ['min' => 1, 'max' => 5, 'min_label' => 'مبتدئ', 'max_label' => 'متمكن جداً'];
        $format = ['id' => 'format', 'type' => 'single', 'title' => 'ما صيغة التدريب المفضلة لديك؟', 'required' => true, 'options' => [
            ['id' => 'in_person', 'label' => 'حضوري في المركز'], ['id' => 'online', 'label' => 'عن بُعد (مباشر)'],
            ['id' => 'blended', 'label' => 'مدمج'], ['id' => 'self_paced', 'label' => 'ذاتي عبر المنصة'],
        ]];
        $timing = ['id' => 'timing', 'type' => 'single', 'title' => 'ما الوقت الأنسب لحضور التدريب؟', 'required' => false, 'options' => [
            ['id' => 'morning', 'label' => 'أثناء الدوام'], ['id' => 'afternoon', 'label' => 'بعد الدوام'],
            ['id' => 'weekend', 'label' => 'نهاية الأسبوع'], ['id' => 'holidays', 'label' => 'الإجازات'],
        ]];

        $templates = [
            [
                'key' => 'teacher_tna',
                'icon' => 'graduation',
                'accent' => '#8A1538',
                'title' => 'تحديد الاحتياجات التدريبية للمعلمين',
                'description' => 'تقييم ذاتي شامل لكفايات التدريس والتقويم والتقنية، مع أولويات التدريب والصيغة المفضلة.',
                'questions' => [
                    ['id' => 'sec1', 'type' => 'section', 'title' => 'الكفايات التدريسية', 'description' => 'قيّم مستوى تمكّنك الحالي في كل مجال بصدق؛ النتائج تُستخدم لتصميم برامج تلائم احتياجاتك.'],
                    ['id' => 'teach', 'type' => 'matrix', 'title' => 'ما مستوى تمكّنك في المجالات الآتية؟', 'required' => true, 'mode' => 'competence', 'scale' => $competence, 'rows' => [
                        $row('r1', 'إدارة الصف وبناء بيئة تعلم إيجابية', 'classroom_management'),
                        $row('r2', 'التعليم المتمايز ومراعاة الفروق الفردية', 'differentiated_instruction'),
                        $row('r3', 'استراتيجيات التعلم النشط', 'active_learning'),
                        $row('r4', 'التقويم من أجل التعلم والتغذية الراجعة', 'assessment_for_learning'),
                        $row('r5', 'توظيف بيانات الطلاب في التخطيط', 'data_driven_instruction'),
                        $row('r6', 'دمج الطلاب ذوي الاحتياجات الخاصة', 'inclusive_education'),
                        $row('r7', 'تعديل السلوك والانضباط الإيجابي', 'behavior_management'),
                    ]],
                    ['id' => 'sec2', 'type' => 'section', 'title' => 'الكفايات الرقمية'],
                    ['id' => 'digital', 'type' => 'matrix', 'title' => 'ما مستوى تمكّنك في التقنيات التعليمية الآتية؟', 'required' => true, 'mode' => 'competence', 'scale' => $competence, 'rows' => [
                        $row('r1', 'أدوات الذكاء الاصطناعي في التعليم', 'ai_in_education'),
                        $row('r2', 'تصميم المحتوى الرقمي التفاعلي', 'digital_content'),
                        $row('r3', 'التعلم المدمج وإدارة الفصول الافتراضية', 'blended_learning'),
                    ]],
                    ['id' => 'sec3', 'type' => 'section', 'title' => 'الأولويات والتفضيلات'],
                    ['id' => 'top', 'type' => 'multiple', 'title' => 'اختر أهم ثلاثة مجالات تحتاج إلى التدريب عليها هذا العام', 'required' => true, 'max_select' => 3, 'options' => [
                        $row('o1', 'إدارة الصف', 'classroom_management'), $row('o2', 'التعليم المتمايز', 'differentiated_instruction'),
                        $row('o3', 'التقويم من أجل التعلم', 'assessment_for_learning'), $row('o4', 'الذكاء الاصطناعي في التعليم', 'ai_in_education'),
                        $row('o5', 'التعليم الدامج', 'inclusive_education'), $row('o6', 'الرفاه النفسي للطلاب', 'student_wellbeing'),
                        $row('o7', 'تنمية مهارات القراءة بالعربية', 'arabic_literacy'), $row('o8', 'تدريس STEM', 'stem_pedagogy'),
                    ]],
                    $format,
                    $timing,
                    ['id' => 'other', 'type' => 'long_text', 'title' => 'هل توجد احتياجات تدريبية أخرى لم تُذكر؟', 'required' => false],
                ],
            ],
            [
                'key' => 'leadership_tna',
                'icon' => 'crown',
                'accent' => '#A29475',
                'title' => 'احتياجات القيادات المدرسية',
                'description' => 'لمديري المدارس ونوابهم ومنسقي المواد: القيادة التربوية والتخطيط والتوجيه المهني.',
                'questions' => [
                    ['id' => 'lead', 'type' => 'matrix', 'title' => 'ما مستوى تمكّنك في مجالات القيادة الآتية؟', 'required' => true, 'mode' => 'competence', 'scale' => $competence, 'rows' => [
                        $row('r1', 'القيادة التربوية وقيادة التغيير', 'educational_leadership'),
                        $row('r2', 'التخطيط الاستراتيجي المدرسي', 'strategic_planning'),
                        $row('r3', 'الإرشاد والتوجيه المهني للمعلمين', 'coaching_mentoring'),
                        $row('r4', 'اتخاذ القرار المبني على البيانات', 'data_driven_instruction'),
                        $row('r5', 'التواصل الفعال مع المجتمع المدرسي', 'communication'),
                        $row('r6', 'البحث الإجرائي وتطوير الممارسات', 'research_skills'),
                    ]],
                    ['id' => 'rank', 'type' => 'ranking', 'title' => 'رتّب الأولويات التطويرية لمدرستك (الأهم أولاً)', 'required' => true, 'options' => [
                        $row('o1', 'تطوير أداء المعلمين', 'coaching_mentoring'), $row('o2', 'تحسين نتائج الطلاب', 'data_driven_instruction'),
                        $row('o3', 'التحول الرقمي', 'blended_learning'), $row('o4', 'الرفاه والبيئة المدرسية', 'student_wellbeing'),
                    ]],
                    ['id' => 'team', 'type' => 'number', 'title' => 'كم عدد أفراد فريقك الذين يحتاجون إلى تدريب قيادي؟', 'required' => false],
                    $format,
                    ['id' => 'challenge', 'type' => 'long_text', 'title' => 'ما أبرز تحدٍّ قيادي تواجهه حالياً؟', 'required' => false],
                ],
            ],
            [
                'key' => 'digital_readiness',
                'icon' => 'cpu',
                'accent' => '#0E7490',
                'title' => 'الجاهزية الرقمية والذكاء الاصطناعي',
                'description' => 'قياس استخدام الأدوات الرقمية والذكاء الاصطناعي مع أسئلة ذكية تتفرع حسب الإجابة.',
                'questions' => [
                    ['id' => 'uses_ai', 'type' => 'yes_no', 'title' => 'هل تستخدم أدوات الذكاء الاصطناعي في التحضير أو التدريس؟', 'required' => true],
                    ['id' => 'ai_level', 'type' => 'rating', 'title' => 'كيف تقيّم مهارتك في استخدام هذه الأدوات؟', 'required' => true, 'mode' => 'competence', 'scale' => ['min' => 1, 'max' => 5]] + $s('ai_in_education') + ['show_if' => ['question' => 'uses_ai', 'op' => 'equals', 'value' => 'yes']],
                    ['id' => 'ai_block', 'type' => 'multiple', 'title' => 'ما الذي يمنعك من استخدامها؟', 'required' => false, 'show_if' => ['question' => 'uses_ai', 'op' => 'equals', 'value' => 'no'], 'options' => [
                        ['id' => 'o1', 'label' => 'لا أعرف الأدوات المناسبة'], ['id' => 'o2', 'label' => 'غياب التدريب'],
                        ['id' => 'o3', 'label' => 'قلق من الخصوصية'], ['id' => 'o4', 'label' => 'ضيق الوقت'],
                    ]],
                    ['id' => 'need', 'type' => 'matrix', 'title' => 'إلى أي درجة تحتاج إلى تدريب في المجالات الآتية؟', 'required' => true, 'mode' => 'need', 'scale' => ['min' => 1, 'max' => 5, 'min_label' => 'لا أحتاج', 'max_label' => 'أحتاج بشدة'], 'rows' => [
                        $row('r1', 'الذكاء الاصطناعي في التعليم', 'ai_in_education'),
                        $row('r2', 'تصميم المحتوى الرقمي', 'digital_content'),
                        $row('r3', 'التعلم المدمج', 'blended_learning'),
                        $row('r4', 'تحليل البيانات التعليمية', 'data_driven_instruction'),
                    ]],
                    ['id' => 'confidence', 'type' => 'nps', 'title' => 'ما مدى ثقتك في توظيف التقنية داخل الفصل؟ (0 - 10)', 'required' => false],
                ],
            ],
            [
                'key' => 'wellbeing_inclusion',
                'icon' => 'heart',
                'accent' => '#9D174D',
                'title' => 'الرفاه والتعليم الدامج',
                'description' => 'احتياجات الدعم في الرفاه النفسي للطلاب والدمج وتعديل السلوك.',
                'questions' => [
                    ['id' => 'need', 'type' => 'matrix', 'title' => 'إلى أي درجة تحتاج إلى تدريب في المجالات الآتية؟', 'required' => true, 'mode' => 'need', 'scale' => ['min' => 1, 'max' => 5, 'min_label' => 'لا أحتاج', 'max_label' => 'أحتاج بشدة'], 'rows' => [
                        $row('r1', 'دعم الرفاه النفسي للطلاب', 'student_wellbeing'),
                        $row('r2', 'دمج الطلاب ذوي الاحتياجات الخاصة', 'inclusive_education'),
                        $row('r3', 'تعديل السلوك', 'behavior_management'),
                        $row('r4', 'التواصل مع أولياء الأمور', 'communication'),
                    ]],
                    ['id' => 'cases', 'type' => 'yes_no', 'title' => 'هل يوجد في فصولك طلاب من ذوي الاحتياجات الخاصة؟', 'required' => true],
                    ['id' => 'cases_n', 'type' => 'number', 'title' => 'كم عددهم تقريباً؟', 'required' => false, 'show_if' => ['question' => 'cases', 'op' => 'equals', 'value' => 'yes']],
                    ['id' => 'support', 'type' => 'long_text', 'title' => 'ما نوع الدعم الذي تحتاجه أكثر؟', 'required' => false],
                ],
            ],
            [
                'key' => 'quick_pulse',
                'icon' => 'zap',
                'accent' => '#A16207',
                'title' => 'استطلاع سريع للاحتياجات',
                'description' => 'أربعة أسئلة في دقيقة واحدة لرصد الأولويات بسرعة.',
                'questions' => [
                    ['id' => 'areas', 'type' => 'multiple', 'title' => 'في أي المجالات تحتاج إلى تدريب؟', 'required' => true, 'options' => [
                        $row('o1', 'طرائق التدريس', 'active_learning'), $row('o2', 'التقويم', 'assessment_for_learning'),
                        $row('o3', 'التقنية والذكاء الاصطناعي', 'ai_in_education'), $row('o4', 'القيادة', 'educational_leadership'),
                        $row('o5', 'الرفاه والدمج', 'student_wellbeing'),
                    ]],
                    ['id' => 'urgency', 'type' => 'scale', 'title' => 'ما مدى إلحاح حاجتك للتدريب؟', 'required' => true, 'mode' => 'need', 'scale' => ['min' => 1, 'max' => 5, 'min_label' => 'غير ملح', 'max_label' => 'ملح جداً']],
                    $format,
                    ['id' => 'note', 'type' => 'short_text', 'title' => 'موضوع تدريبي تقترحه', 'required' => false],
                ],
            ],
        ];

        return array_map(fn ($t) => $t + ['count' => count(array_filter($t['questions'], fn ($q) => $q['type'] !== 'section'))], $templates);
    }

    public function find(string $key): ?array
    {
        return collect($this->all())->firstWhere('key', $key);
    }
}
