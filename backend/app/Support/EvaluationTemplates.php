<?php

namespace App\Support;

/**
 * The centre's two programme forms, as they were run in Microsoft Forms (docs/saber/08-centre-forms.md): the trainee form («تقييم
 * ورشة») and the trainer form («التقييم الذاتي للمدرب»). What those forms asked first — group, programme, sub-programme, trainer; name,
 * job title, programme, date — Shaheen already knows, so it is shown filled in rather than asked. Question and row ids are stable: answers
 * and reports are keyed by them.
 */
final class EvaluationTemplates
{
    public const TRAINEE = 'moehe_trainee_2026';

    public const REFLECTION = 'moehe_trainer_reflection_2026';

    /** @return array{title_ar: string, title_en: string, intro_ar: string, intro_en: string, questions: list<array<string, mixed>>} */
    public static function trainee(): array
    {
        // 5 = أوافق بشدة … 1 = غير موافق بشدة, shown from the strongest agreement as on the original form.
        $agree = ['min' => 1, 'max' => 5, 'order' => 'desc', 'labels' => ['غير موافق بشدة', 'غير موافق', 'محايد', 'أوافق', 'أوافق بشدة']];
        $grid = fn (string $id, string $ar, string $en, array $rows) => ['id' => $id, 'type' => 'matrix', 'title' => $ar, 'title_en' => $en, 'required' => true, 'scale' => $agree, 'mode' => 'competence',
            'rows' => array_map(fn ($r) => ['id' => $r[0], 'label' => $r[1], 'label_en' => $r[2]], $rows)];

        return [
            'title_ar' => 'استمارة المتدرب — تقييم الورشة التدريبية', 'title_en' => 'Trainee form — workshop evaluation',
            'intro_ar' => "أخي المتدرب / أختي المتدربة\n\nنقدّر مشاركتك الفاعلة في هذه الورشة التدريبية، ونؤمن أن رأيك وملاحظاتك قيمة وأساسية في رحلتنا المستمرة نحو التطوير والتميّز. هذه الاستبانة وُضعت خصيصًا لنستمع لصوتك، ونتعرّف على تجربتك بصدق، لنُحسّن من جودة التدريب ونُطوّر بيئته بما يخدمك ويخدم زملاءك مستقبلاً.\n\nإننا نُشجّع على التقييم الصادق والشفاف، ونعِدك أن كل ما تُدلي به من رأي أو اقتراح أو ملاحظة، سيكون موضع اهتمام واحترام، ولن يُستخدم أبدًا للمحاسبة أو إصدار الأحكام، بل سيكون دافعًا لنا نحو الأفضل.\n\nالمتدرب الذي يُقيّم بموضوعية ويقترح ويُلاحظ، هو متدرب مؤثر ومُعزّز، نُقدّر صراحته ووعيه وشراكته في التطوير.\n\nشكرًا لمساهمتك البناءة، ولثقتك التي نعتز بها.",
            'intro_en' => "Dear trainee,\n\nWe value your active part in this workshop, and we believe your views and remarks are essential to our continuous journey of improvement. This questionnaire is here to hear your voice and learn about your experience honestly, so we can raise the quality of training and its environment for you and your colleagues.\n\nWe encourage honest, transparent evaluation. Every opinion, suggestion or remark you share will be received with care and respect — never used to hold anyone to account or pass judgement, only to do better.\n\nThank you for your constructive contribution and for the trust we cherish.",
            'questions' => [
                $grid('trainer', 'أولاً: تقييم المدرب', 'First: the trainer', [
                    ['punctual', 'التزم المدرب بالحضور في المواعيد المحددة', 'The trainer kept to the scheduled times'],
                    ['clarity', 'وضوح العرض والشرح', 'Clear presentation and explanation'],
                    ['methods', 'استخدم المدرب أساليب متنوعة للتوضيح (الأمثلة، الأنشطة، النقاش)', 'The trainer used varied methods (examples, activities, discussion)'],
                    ['expertise', 'امتلاك المدرب للمعرفة والخبرة الكافية بموضوع الورشة', 'The trainer has enough knowledge and experience of the topic'],
                    ['time', 'تمت إدارة الوقت بفاعلية من قبل المدرب', 'The trainer managed time effectively'],
                    ['motivation', 'يمتلك المدرب القدرة على تحفيز المتدربين وتشجيعهم على المشاركة والتفاعل', 'The trainer motivates trainees to take part and interact'],
                ]),
                $grid('content', 'ثانيًا: تقييم المحتوى التدريبي', 'Second: the training content', [
                    ['objectives', 'وضوح أهداف الورشة التدريبية', 'Clear workshop objectives'],
                    ['fit', 'ملاءمة محاور الورشة التدريبية للفئة المستهدفة', 'The workshop themes suit the target group'],
                    ['balance', 'توفر التوازن بين الجانب النظري والتطبيقي', 'A balance between theory and practice'],
                    ['activities', 'جودة وفعالية الأنشطة والتمارين العملية', 'Quality and effectiveness of the practical activities'],
                    ['organised', 'المحتوى التدريبي للبرنامج منظم ومترابط وغير مكرر', 'The content is organised, coherent and not repetitive'],
                    ['resources', 'ساهمت المصادر التعليمية التي وفرها البرنامج في إثراء فهمك للمواضيع التي تم التطرق لها', 'The learning resources enriched your understanding of the topics'],
                ]),
                $grid('environment', 'ثالثًا: تقييم البيئة التدريبية والتسهيلات', 'Third: the training environment and facilities', [
                    ['room', 'ملاءمة تجهيزات القاعة التدريبية (الإضاءة، التكييف، ...)', 'The training room set-up (lighting, air conditioning, …)'],
                    ['facilities', 'التسهيلات والمرافق مناسبة (مصلى، كافتيريا، ...)', 'Suitable facilities (prayer room, cafeteria, …)'],
                    ['equipment', 'الأجهزة والمعدات اللازمة للورشة متوفرة وملائمة', 'The devices and equipment needed are available and suitable'],
                ]),
                $grid('impact', 'رابعًا: تقييم الأثر المتوقع', 'Fourth: the expected impact', [
                    ['knowledge', 'اكتسبت معرفة جديدة يمكنني استخدامها', 'I gained new knowledge I can use'],
                    ['apply', 'سأطبق ما تعلمته في بيئة عملي', 'I will apply what I learnt at work'],
                    ['quality', 'التدريب سينعكس إيجاباً على جودة عملي', 'The training will improve the quality of my work'],
                ]),
                ['id' => 'overall', 'type' => 'single', 'title' => 'تقييمك العام للورشة التدريبية', 'title_en' => 'Your overall rating of the workshop', 'required' => true, 'options' => [
                    ['id' => 'excellent', 'label' => 'ممتاز'], ['id' => 'very_good', 'label' => 'جيد جدا'], ['id' => 'good', 'label' => 'جيد'], ['id' => 'fair', 'label' => 'مقبول'], ['id' => 'weak', 'label' => 'ضعيف'],
                ]],
                ['id' => 'opinion', 'type' => 'long_text', 'title' => 'عبِّر عن رأيك .. فرُبَّ كلمة منك تؤثِّر', 'title_en' => 'Say what you think — one word from you may make a difference', 'required' => true],
            ],
        ];
    }

    /** @return array{title_ar: string, title_en: string, questions: list<array<string, mixed>>} */
    public static function trainerReflection(): array
    {
        // 4 = أوافق تماماً … 1 = لا أوافق أبداً.
        $agree = ['min' => 1, 'max' => 4, 'order' => 'desc', 'labels' => ['لا أوافق أبداً', 'لا أوافق', 'أوافق', 'أوافق تماماً']];
        $grid = fn (string $id, string $ar, string $en, array $rows) => ['id' => $id, 'type' => 'matrix', 'title' => $ar, 'title_en' => $en, 'required' => true, 'scale' => $agree, 'mode' => 'competence',
            'rows' => array_map(fn ($r) => ['id' => $r[0], 'label' => $r[1], 'label_en' => $r[2]], $rows)];

        return [
            'title_ar' => 'التقييم الذاتي للمدرب', 'title_en' => 'Trainer self-evaluation',
            'questions' => [
                $grid('centre', 'ثانياً: معلومات عن مركز التدريب والتطوير', 'Second: the Training and Development Centre', [
                    ['kit', 'وفّر مركز التدريب حقيبة تدريبية واضحة حول البرنامج التدريبي الذي كُلّفت بتقديمه', 'The centre provided a clear training kit for the programme I was asked to deliver'],
                    ['material', 'توفير مادة تدريبية لكل متدرب وفق المعايير المعتمدة للمركز', 'Training material for every trainee to the centre’s standards'],
                    ['aids', 'توفير أجهزة ووسائل تدريب سمعية وبصرية وورقية للتدريب', 'Audio, visual and printed training aids were provided'],
                    ['devices', 'حرص المسؤول عن البرنامج التدريبي على التأكد من عمل الأجهزة في قاعة التدريب قبل البدء في التدريب بفترة كافية', 'The programme officer checked the room devices well before the training started'],
                    ['follow_up', 'متابعة استمارات تقييم المتدربين النهائي للبرنامج التدريبي', 'The trainees’ final evaluation forms were followed up'],
                    ['specialist', 'حرص أخصائي التدريب المسؤول عن البرنامج التدريبي على التعاون مع المدرب والمتدربين لتحقيق هدف التدريب', 'The training specialist worked with the trainer and trainees towards the training goal'],
                    ['organisation', 'حرص القائمين على المركز على التنظيم الجيد للبرنامج التدريبي', 'The centre organised the programme well'],
                    ['resources', 'وفّر مركز التدريب المصادر التدريبية الكافية لتقديم البرنامج على نحو فعال', 'The centre provided enough resources to deliver the programme effectively'],
                ]),
                $grid('delivery', 'ثالثاً: معلومات عن تقديم البرنامج التدريبي', 'Third: delivering the programme', [
                    ['prepared', 'كنت مستعداً استعداداً كاملاً لتقديم البرنامج التدريبي بفاعلية', 'I was fully prepared to deliver the programme effectively'],
                    ['objectives', 'أطلعت المشاركين على أهداف البرنامج التدريبي ومدى تحقق تلك الأهداف أثناء التدريب', 'I shared the objectives with the participants and how far they were met'],
                    ['engaging', 'كان محتوى البرنامج التدريبي محفزاً وممتعاً بالنسبة للمشاركين', 'The content was motivating and enjoyable for the participants'],
                    ['organised', 'كان محتوى البرنامج التدريبي منظماً تنظيماً جيداً', 'The content was well organised'],
                    ['involved', 'كان المشاركون منخرطين ومهتمين طوال فترة الجلسة', 'The participants were engaged and interested throughout'],
                    ['activities', 'كانت المصادر والأنشطة محفزة للمشاركين', 'The resources and activities motivated the participants'],
                    ['relevance', 'أظهر المشاركون اهتمامهم بالموضوع ووجدوا أنه مرتبط باحتياجاتهم', 'The participants showed interest and found the topic relevant to their needs'],
                    ['needs', 'كانت الأنشطة التدريبية للبرنامج تتطابق مع احتياجات جميع المشاركين', 'The activities matched the needs of all participants'],
                    ['assessment', 'كانت أنشطة التقييم عالية الفاعلية للتحقق من مخرجات تعلم المشاركين', 'The assessment activities checked the learning outcomes effectively'],
                ]),
                ['id' => 'overall', 'type' => 'single', 'title' => 'ما هو تقييمك العام للبرنامج التدريبي؟', 'title_en' => 'What is your overall rating of the programme?', 'required' => true, 'options' => [
                    ['id' => 'excellent', 'label' => 'ممتاز'], ['id' => 'good', 'label' => 'جيد'], ['id' => 'satisfactory', 'label' => 'مرضي'], ['id' => 'unsatisfactory', 'label' => 'غير مرضي'],
                ]],
            ],
        ];
    }
}
