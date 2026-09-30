<?php

namespace App\Services\Kits;

use Illuminate\Support\Str;

/**
 * Generates a deck from a prompt. Claude writes the outline when an API key is configured;
 * otherwise a built-in instructional template produces a sound first draft to edit.
 */
class DeckGenerator
{
    public const TYPES = ['title', 'section', 'agenda', 'content', 'two_column', 'image_text', 'quote', 'activity', 'summary', 'assessment'];

    public function __construct(private readonly KitAi $ai, private readonly DeckBuilder $builder) {}

    /**
     * @param  array{topic: string, audience?: ?string, objectives?: string[], slide_count?: int, language?: string, tone?: ?string, duration_hours?: ?float, activities?: bool, quiz?: bool, instructions?: ?string}  $p
     * @return array{deck: array, outline: array, provider: string}
     */
    public function generate(array $p): array
    {
        $p['language'] = ($p['language'] ?? 'ar') === 'en' ? 'en' : 'ar';
        $p['slide_count'] = max(4, min(40, (int) ($p['slide_count'] ?? 12)));
        $p['objectives'] = array_values(array_filter(array_map('trim', (array) ($p['objectives'] ?? []))));

        $outline = $this->ai->enabled() ? $this->claude($p) : null;
        $provider = $outline ? 'anthropic' : 'template';
        $outline ??= $this->template($p);
        $outline['language'] = $p['language'];

        return ['deck' => $this->builder->build($outline, $p['language']), 'outline' => $outline, 'provider' => $provider];
    }

    private function claude(array $p): ?array
    {
        $system = <<<'PROMPT'
You are a senior instructional designer building professional-development slide decks for teachers and school leaders in the State of Qatar.
Design decks that a trainer can deliver directly: a clear opening, learning objectives, an agenda, content chunked into short slides, an interactive activity roughly every 3-4 content slides, a summary of key takeaways, and a short assessment or exit ticket when asked.
Rules: at most 6 bullets per slide and at most 14 words per bullet; concrete classroom examples over abstract statements; no filler; do not invent statistics or citations.
Write speaker notes for the trainer (timing, questions to ask, how to run activities) in the deck language.
Arabic decks use Modern Standard Arabic suitable for official government communication. Use Western digits (0-9).
For slides that benefit from a visual, give image_prompt in English describing a simple, flat, education-themed illustration (no text inside the image).
PROMPT;

        $prompt = "Create a {$p['slide_count']}-slide deck.\n"
            ."Language: {$p['language']}\nTopic: {$p['topic']}\n"
            .(! empty($p['audience']) ? "Audience: {$p['audience']}\n" : '')
            .(! empty($p['duration_hours']) ? "Session length: {$p['duration_hours']} hours\n" : '')
            .(! empty($p['tone']) ? "Tone: {$p['tone']}\n" : '')
            .($p['objectives'] ? "Learning objectives:\n- ".implode("\n- ", $p['objectives'])."\n" : '')
            .(($p['activities'] ?? true) ? "Include interactive activities.\n" : "No activity slides.\n")
            .(($p['quiz'] ?? true) ? "End with an assessment slide.\n" : '')
            .(! empty($p['instructions']) ? "Extra instructions from the author: {$p['instructions']}\n" : '')
            .'Allowed slide types: '.implode(', ', self::TYPES).'. The first slide must be type "title".';

        $data = $this->ai->json($system, $prompt, $this->schema(), 12000);
        if (! $data || empty($data['slides']) || ! is_array($data['slides'])) {
            return null;
        }

        $data['slides'] = array_values(array_filter(array_map(function ($s) {
            $s = (array) $s;
            $s['type'] = in_array($s['type'] ?? null, self::TYPES, true) ? $s['type'] : 'content';

            return $s;
        }, array_slice($data['slides'], 0, 40)), fn ($s) => trim((string) ($s['title'] ?? '')) !== ''));

        return $data['slides'] ? $data : null;
    }

    private function schema(): array
    {
        $strings = ['type' => 'array', 'items' => ['type' => 'string']];
        $slide = [
            'type' => 'object',
            'properties' => [
                'type' => ['type' => 'string', 'enum' => self::TYPES], 'title' => ['type' => 'string'], 'subtitle' => ['type' => 'string'],
                'bullets' => $strings, 'left_title' => ['type' => 'string'], 'left' => $strings, 'right_title' => ['type' => 'string'], 'right' => $strings,
                'quote' => ['type' => 'string'], 'minutes' => ['type' => 'integer'], 'notes' => ['type' => 'string'], 'image_prompt' => ['type' => 'string'],
            ],
            'required' => ['type', 'title', 'subtitle', 'bullets', 'left_title', 'left', 'right_title', 'right', 'quote', 'minutes', 'notes', 'image_prompt'],
            'additionalProperties' => false,
        ];

        return [
            'type' => 'object',
            'properties' => ['title' => ['type' => 'string'], 'subtitle' => ['type' => 'string'], 'slides' => ['type' => 'array', 'items' => $slide]],
            'required' => ['title', 'subtitle', 'slides'],
            'additionalProperties' => false,
        ];
    }

    // Built-in template -----------------------------------------------------------------------

    /** A pedagogically sound skeleton the author then edits (used when AI is not configured). */
    public function template(array $p): array
    {
        $ar = $p['language'] === 'ar';
        $topic = trim($p['topic']);
        $n = $p['slide_count'];
        $activities = $p['activities'] ?? true;
        $quiz = $p['quiz'] ?? true;
        $minutesPer = ! empty($p['duration_hours']) ? max(3, (int) round($p['duration_hours'] * 60 / $n)) : 8;
        $objectives = $p['objectives'] ?: ($ar
            ? ["أن يوضح المتدرب مفهوم {$topic}", "أن يطبق المتدرب استراتيجيات {$topic} في الصف", "أن يقيّم المتدرب أثر تطبيق {$topic} على تعلم الطلاب"]
            : ["Explain the concept of {$topic}", "Apply {$topic} strategies in the classroom", "Evaluate the impact of {$topic} on student learning"]);

        $chunks = $this->chunks($ar, $topic);
        $fixed = 3 + ($activities ? 0 : 0) + 1 + ($quiz ? 1 : 0); // title, objectives, agenda, summary, [assessment]
        $contentSlots = max(1, $n - $fixed);

        $slides = [
            ['type' => 'title', 'title' => $topic, 'subtitle' => trim(($p['audience'] ?? '').($p['audience'] ? ' · ' : '').($ar ? 'حقيبة تدريبية' : 'Training kit')), 'notes' => $ar ? 'رحّب بالمتدربين وعرّف بنفسك وبموضوع الحقيبة.' : 'Welcome participants and introduce yourself and the topic.'],
            ['type' => 'content', 'title' => $ar ? 'أهداف الجلسة' : 'Session objectives', 'bullets' => $objectives, 'notes' => $ar ? 'اطلب من المتدربين ربط كل هدف بموقف من عملهم.' : 'Ask participants to link each objective to a situation from their work.'],
        ];

        $agendaItems = [];
        $body = [];
        $contentCount = 0;
        $sinceActivity = 0;
        for ($i = 0; $contentCount < $contentSlots; $i++) {
            if ($activities && $sinceActivity >= 3 && $contentCount < $contentSlots) {
                $act = $this->activity($ar, $topic, count($body) + 1, $minutesPer);
                $body[] = $act;
                $contentCount++;
                $sinceActivity = 0;

                continue;
            }
            $chunk = $chunks[$i % count($chunks)];
            if ($i >= count($chunks) && isset($objectives[$i - count($chunks)])) {
                $chunk = ['title' => Str::limit($objectives[$i - count($chunks)], 70), 'bullets' => $chunk['bullets']];
            }
            $type = $i % 4 === 2 ? 'two_column' : ($i % 4 === 1 ? 'image_text' : 'content');
            $slide = ['type' => $type, 'title' => $chunk['title'], 'bullets' => $chunk['bullets'], 'notes' => ($ar ? "الزمن المقترح: {$minutesPer} دقائق. " : "Suggested time: {$minutesPer} minutes. ").($ar ? 'اطرح سؤالاً افتتاحياً قبل عرض المحتوى.' : 'Ask an opening question before presenting the content.')];
            if ($type === 'two_column') {
                $half = (int) ceil(count($chunk['bullets']) / 2);
                $slide += ['left_title' => $ar ? 'ما الذي نفعله؟' : 'What we do', 'left' => array_slice($chunk['bullets'], 0, $half), 'right_title' => $ar ? 'كيف نطبقه؟' : 'How we apply it', 'right' => array_slice($chunk['bullets'], $half)];
            }
            if ($type === 'image_text') {
                $slide['image_prompt'] = "Flat educational illustration about {$topic}, teachers and students in a modern classroom, warm colors";
            }
            $body[] = $slide;
            $agendaItems[] = $chunk['title'];
            $contentCount++;
            $sinceActivity++;
        }

        $slides[] = ['type' => 'agenda', 'title' => $ar ? 'محاور الجلسة' : 'Agenda', 'bullets' => array_slice(array_values(array_unique($agendaItems)), 0, 7), 'notes' => $ar ? 'استعرض المحاور بإيجاز مع الزمن المتوقع لكل محور.' : 'Walk through the agenda with expected timing for each part.'];
        array_push($slides, ...$body);
        $slides[] = ['type' => 'summary', 'title' => $ar ? 'أبرز ما تعلمناه' : 'Key takeaways', 'bullets' => $ar
            ? ["مفهوم {$topic} وأهميته", 'الخطوات العملية للتطبيق', 'الأخطاء الشائعة وكيف نتجنبها', 'مؤشرات قياس الأثر']
            : ["The concept of {$topic} and why it matters", 'Practical steps to apply it', 'Common mistakes and how to avoid them', 'Indicators to measure impact'],
            'notes' => $ar ? 'اطلب من كل متدرب أن يذكر فكرة واحدة سيطبقها هذا الأسبوع.' : 'Ask each participant to name one idea they will apply this week.'];
        if ($quiz) {
            $slides[] = ['type' => 'assessment', 'title' => $ar ? 'تقويم ختامي' : 'Exit assessment', 'bullets' => $ar
                ? ["ما تعريفك لـ {$topic} بأسلوبك؟", 'اذكر خطوتين ستطبقهما في صفك الأسبوع القادم.', 'ما التحدي الأكبر المتوقع وكيف ستتعامل معه؟']
                : ["Define {$topic} in your own words.", 'Name two steps you will apply in your class next week.', 'What is the biggest expected challenge and how will you handle it?'],
                'notes' => $ar ? 'يُستخدم كتذكرة خروج؛ اجمع الإجابات لتحسين الجلسة القادمة.' : 'Use as an exit ticket; collect answers to improve the next session.'];
        }

        return ['title' => $topic, 'subtitle' => (string) ($p['audience'] ?? ''), 'slides' => array_slice($slides, 0, $n)];
    }

    private function activity(bool $ar, string $topic, int $n, int $minutes): array
    {
        return $ar
            ? ['type' => 'activity', 'title' => 'نشاط تفاعلي: تطبيق في مجموعات', 'minutes' => max(10, $minutes * 2), 'bullets' => ['قسّموا أنفسكم إلى مجموعات من 4 أفراد', "اختاروا موقفاً حقيقياً من عملكم مرتبطاً بـ {$topic}", 'صمّموا حلاً عملياً في 10 دقائق', 'اعرضوا الحل على بقية المجموعات وتلقوا الملاحظات'], 'notes' => 'تجوّل بين المجموعات، وجّه بالأسئلة ولا تعطِ الحل مباشرة.']
            : ['type' => 'activity', 'title' => 'Activity: group application', 'minutes' => max(10, $minutes * 2), 'bullets' => ['Form groups of four', "Pick a real situation from your work related to {$topic}", 'Design a practical solution in 10 minutes', 'Present it to the other groups and gather feedback'], 'notes' => 'Circulate, guide with questions and avoid giving the solution directly.'];
    }

    /** @return list<array{title: string, bullets: list<string>}> */
    private function chunks(bool $ar, string $topic): array
    {
        return $ar ? [
            ['title' => "ما هو {$topic}؟", 'bullets' => ["تعريف {$topic} بلغة مبسطة", 'أهميته في العمل التربوي اليومي', 'أبرز المفاهيم المرتبطة به']],
            ['title' => 'لماذا يهمنا؟', 'bullets' => ['أثره على تعلم الطلاب', 'أثره على الأداء المهني للمعلم', 'المؤشرات الدالة على التطبيق الجيد']],
            ['title' => 'المبادئ الأساسية', 'bullets' => ['الوضوح في الأهداف والتوقعات', 'التدرج من السهل إلى الصعب', 'التغذية الراجعة المستمرة', 'المشاركة الفاعلة للمتعلمين']],
            ['title' => 'خطوات التطبيق العملي', 'bullets' => ['التخطيط والتحضير', 'التنفيذ داخل الصف', 'المتابعة والتقويم', 'التحسين والتطوير المستمر']],
            ['title' => 'أمثلة وحالات تطبيقية', 'bullets' => ['مثال من الحصة الدراسية', 'مثال من إدارة المدرسة', 'ما الذي نجح؟ وما الذي يمكن تحسينه؟']],
            ['title' => 'أخطاء شائعة وكيف نتجنبها', 'bullets' => ['التركيز على المحتوى دون المتعلم', 'إهمال التغذية الراجعة', 'الاكتفاء بالتطبيق مرة واحدة']],
            ['title' => 'أدوات ومصادر مساندة', 'bullets' => ['نماذج وقوائم مراجعة', 'مصادر رقمية موثوقة', 'مجتمعات التعلم المهنية']],
            ['title' => 'قياس الأثر', 'bullets' => ['مؤشرات الأداء المتوقعة', 'أدوات جمع الأدلة', 'خطة المتابعة بعد التدريب']],
        ] : [
            ['title' => "What is {$topic}?", 'bullets' => ["A plain-language definition of {$topic}", 'Why it matters in everyday teaching', 'Key related concepts']],
            ['title' => 'Why it matters', 'bullets' => ['Impact on student learning', "Impact on the teacher's professional practice", 'Indicators of good practice']],
            ['title' => 'Core principles', 'bullets' => ['Clear goals and expectations', 'Progress from easy to hard', 'Continuous feedback', 'Active learner participation']],
            ['title' => 'Applying it step by step', 'bullets' => ['Plan and prepare', 'Deliver in the classroom', 'Follow up and assess', 'Improve continuously']],
            ['title' => 'Examples and cases', 'bullets' => ['An example from a lesson', 'An example from school leadership', 'What worked and what can improve?']],
            ['title' => 'Common mistakes and how to avoid them', 'bullets' => ['Focusing on content instead of the learner', 'Neglecting feedback', 'Applying it only once']],
            ['title' => 'Tools and supporting resources', 'bullets' => ['Templates and checklists', 'Reliable digital resources', 'Professional learning communities']],
            ['title' => 'Measuring impact', 'bullets' => ['Expected performance indicators', 'Tools for gathering evidence', 'Post-training follow-up plan']],
        ];
    }
}
