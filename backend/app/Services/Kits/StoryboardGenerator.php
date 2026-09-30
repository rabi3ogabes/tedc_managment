<?php

namespace App\Services\Kits;

/** Plans a short explainer video as scenes: caption, narration and an image prompt each. The browser renders the video. */
class StoryboardGenerator
{
    public function __construct(private readonly KitAi $ai) {}

    /**
     * @param  array{topic: string, audience?: ?string, scenes?: int, seconds?: int, language?: string, style?: ?string, instructions?: ?string}  $p
     * @return array{title: string, scenes: list<array>, provider: string}
     */
    public function plan(array $p): array
    {
        $p['language'] = ($p['language'] ?? 'ar') === 'en' ? 'en' : 'ar';
        $p['scenes'] = max(3, min(8, (int) ($p['scenes'] ?? 5)));
        $p['seconds'] = max(20, min(180, (int) ($p['seconds'] ?? 45)));

        $plan = $this->claude($p);
        $provider = $plan ? 'anthropic' : 'template';
        $plan ??= $this->template($p);

        $per = max(4, (int) round($p['seconds'] / max(1, count($plan['scenes']))));
        $plan['scenes'] = array_values(array_map(fn ($s) => [
            'title' => (string) ($s['title'] ?? ''), 'narration' => (string) ($s['narration'] ?? ''), 'image_prompt' => (string) ($s['image_prompt'] ?? $s['title'] ?? ''),
            'seconds' => max(3, min(30, (int) ($s['seconds'] ?? $per))),
        ], array_slice($plan['scenes'], 0, 8)));

        return ['title' => (string) ($plan['title'] ?? $p['topic']), 'scenes' => $plan['scenes'], 'provider' => $provider];
    }

    private function claude(array $p): ?array
    {
        if (! $this->ai->enabled()) {
            return null;
        }
        $system = 'You are a scriptwriter of short educational explainer videos for teachers in Qatar. Write tight, warm narration (one or two sentences per scene) in the requested language (Modern Standard Arabic for Arabic, Western digits). Each scene has a short on-screen title and an English image_prompt describing one simple flat illustration with no text in it. Open with a hook and close with a clear takeaway.';
        $prompt = "Topic: {$p['topic']}\nLanguage: {$p['language']}\nScenes: {$p['scenes']}\nTotal length: about {$p['seconds']} seconds\n"
            .(! empty($p['audience']) ? "Audience: {$p['audience']}\n" : '').(! empty($p['style']) ? "Visual style: {$p['style']}\n" : '').(! empty($p['instructions']) ? "Extra instructions: {$p['instructions']}\n" : '');
        $scene = ['type' => 'object', 'properties' => ['title' => ['type' => 'string'], 'narration' => ['type' => 'string'], 'image_prompt' => ['type' => 'string'], 'seconds' => ['type' => 'integer']], 'required' => ['title', 'narration', 'image_prompt', 'seconds'], 'additionalProperties' => false];
        $data = $this->ai->json($system, $prompt, ['type' => 'object', 'properties' => ['title' => ['type' => 'string'], 'scenes' => ['type' => 'array', 'items' => $scene]], 'required' => ['title', 'scenes'], 'additionalProperties' => false], 4000);

        return $data && ! empty($data['scenes']) ? $data : null;
    }

    private function template(array $p): array
    {
        $ar = $p['language'] === 'ar';
        $t = $p['topic'];
        $style = $p['style'] ?? 'flat educational illustration, warm colors';

        return ['title' => $t, 'scenes' => $ar ? [
            ['title' => 'هل واجهت هذا التحدي؟', 'narration' => "كل معلم يواجه أحياناً صعوبة في {$t}. دعنا نبسّط الأمر في دقيقة.", 'image_prompt' => "A thoughtful teacher at a desk facing a classroom challenge, {$style}"],
            ['title' => "ما هو {$t}؟", 'narration' => "{$t} هو أسلوب عملي يساعدنا على تحسين تعلم الطلاب خطوة بخطوة.", 'image_prompt' => "A simple concept illustration about {$t} with lightbulb and books, {$style}"],
            ['title' => 'الخطوات الأساسية', 'narration' => 'خطّط بوضوح، طبّق داخل الصف، ثم قيّم وحسّن بشكل مستمر.', 'image_prompt' => "Three steps path plan-do-review in a classroom, {$style}"],
            ['title' => 'مثال من الصف', 'narration' => 'عندما شاركنا الطلاب في النشاط، ارتفع تفاعلهم وتحسّنت نتائجهم.', 'image_prompt' => "Students working in groups with a smiling teacher, {$style}"],
            ['title' => 'ابدأ اليوم', 'narration' => 'اختر فكرة واحدة وطبّقها هذا الأسبوع، وشاركنا أثرها.', 'image_prompt' => "A teacher checking a checklist with a sunrise in the window, {$style}"],
        ] : [
            ['title' => 'Ever faced this challenge?', 'narration' => "Every teacher sometimes struggles with {$t}. Let's simplify it in a minute.", 'image_prompt' => "A thoughtful teacher at a desk facing a classroom challenge, {$style}"],
            ['title' => "What is {$t}?", 'narration' => "{$t} is a practical approach that improves student learning step by step.", 'image_prompt' => "A simple concept illustration about {$t} with lightbulb and books, {$style}"],
            ['title' => 'The core steps', 'narration' => 'Plan clearly, apply in the classroom, then review and keep improving.', 'image_prompt' => "Three steps path plan-do-review in a classroom, {$style}"],
            ['title' => 'A classroom example', 'narration' => 'When students joined the activity, engagement and results went up.', 'image_prompt' => "Students working in groups with a smiling teacher, {$style}"],
            ['title' => 'Start today', 'narration' => 'Pick one idea, apply it this week and share the impact.', 'image_prompt' => "A teacher checking a checklist with a sunrise in the window, {$style}"],
        ]];
    }
}
