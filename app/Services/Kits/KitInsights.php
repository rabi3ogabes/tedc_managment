<?php

namespace App\Services\Kits;

use App\Models\KitComment;
use App\Models\TrainingKit;

/** Completeness of a kit and smart suggestions for what to build next. */
class KitInsights
{
    /** @return array{percent: int, items: list<array>} */
    public function completeness(TrainingKit $kit): array
    {
        $files = $kit->relationLoaded('files') ? $kit->files : $kit->files()->get();
        $hasCategory = fn (string $c) => $files->contains('category', $c);
        $hasActivitySlide = $files->contains(fn ($f) => $f->kind === 'presentation' && collect($f->content['slides'] ?? [])->contains('layout', 'activity'));
        $blocking = KitComment::where('kit_id', $kit->id)->whereNull('parent_id')->whereIn('severity', KitComment::BLOCKING)->where('status', '!=', 'resolved')->count();

        $items = [
            ['key' => 'details', 'weight' => 10, 'done' => count((array) $kit->objectives) > 0 && filled($kit->audience)],
            ['key' => 'presentation', 'weight' => 20, 'done' => $files->contains('kind', 'presentation')],
            ['key' => 'trainer_guide', 'weight' => 15, 'done' => $hasCategory('trainer_guide')],
            ['key' => 'handout', 'weight' => 10, 'done' => $hasCategory('handout')],
            ['key' => 'assessment', 'weight' => 10, 'done' => $hasCategory('assessment')],
            ['key' => 'activities', 'weight' => 5, 'done' => $hasCategory('activity') || $hasActivitySlide],
            ['key' => 'media', 'weight' => 5, 'done' => $files->contains(fn ($f) => in_array($f->kind, ['image', 'video'], true))],
            ['key' => 'no_blockers', 'weight' => 10, 'done' => $files->isNotEmpty() && $blocking === 0],
            ['key' => 'qa', 'weight' => 15, 'done' => in_array($kit->status, [TrainingKit::APPROVED, TrainingKit::PUBLISHED], true)],
        ];
        $total = array_sum(array_column($items, 'weight'));
        $done = array_sum(array_map(fn ($i) => $i['done'] ? $i['weight'] : 0, $items));

        return ['percent' => (int) round($done / $total * 100), 'items' => $items, 'blocking_comments' => $blocking];
    }

    /**
     * What to build next, with a ready-made prompt for each generator.
     *
     * @return list<array{key: string, action: string, title_ar: string, title_en: string, hint_ar: string, hint_en: string, params: array}>
     */
    public function suggestions(TrainingKit $kit): array
    {
        $done = collect($this->completeness($kit)['items'])->pluck('done', 'key');
        $topic = $kit->title_ar ?: $kit->title_en;
        $objectives = array_values((array) $kit->objectives);
        $out = [];

        if (! $done['presentation']) {
            $out[] = ['key' => 'presentation', 'action' => 'generate_deck', 'title_ar' => 'عرض تقديمي للحقيبة', 'title_en' => 'Kit presentation',
                'hint_ar' => 'مسودة عرض كاملة: أهداف، محاور، محتوى، نشاط، ملخص وتقويم.', 'hint_en' => 'A full draft: objectives, agenda, content, activity, summary and assessment.',
                'params' => ['topic' => $topic, 'audience' => $kit->audience, 'objectives' => $objectives, 'duration_hours' => $kit->duration_hours, 'slide_count' => (int) max(8, min(24, round($kit->duration_hours * 3 ?: 12)))]];
        }
        if (! $done['trainer_guide']) {
            $out[] = ['key' => 'trainer_guide', 'action' => 'upload', 'title_ar' => 'دليل المدرب', 'title_en' => 'Trainer guide', 'hint_ar' => 'ارفع دليل المدرب (Word/PDF): الجدول الزمني، الأسئلة، وإدارة الأنشطة.', 'hint_en' => 'Upload the trainer guide (Word/PDF): timing, questions and how to run the activities.', 'params' => ['category' => 'trainer_guide']];
        }
        if (! $done['handout']) {
            $out[] = ['key' => 'handout', 'action' => 'upload', 'title_ar' => 'دليل المتدرب', 'title_en' => 'Trainee handout', 'hint_ar' => 'ارفع المادة التي يحملها المتدرب معه (Word/PDF).', 'hint_en' => 'Upload the material participants take home (Word/PDF).', 'params' => ['category' => 'handout']];
        }
        if (! $done['assessment']) {
            $out[] = ['key' => 'assessment', 'action' => 'upload', 'title_ar' => 'أدوات التقويم', 'title_en' => 'Assessment tools', 'hint_ar' => 'اختبار قبلي/بعدي أو تذكرة خروج لقياس التعلم.', 'hint_en' => 'A pre/post test or exit ticket to measure learning.', 'params' => ['category' => 'assessment']];
        }
        if (! $done['media']) {
            $out[] = ['key' => 'media', 'action' => 'generate_video', 'title_ar' => 'فيديو تمهيدي قصير', 'title_en' => 'Short intro video', 'hint_ar' => 'فيديو توضيحي من 45 ثانية يفتتح الجلسة.', 'hint_en' => 'A 45-second explainer that opens the session.',
                'params' => ['topic' => $topic, 'audience' => $kit->audience, 'seconds' => 45]];
        }
        if (! $done['activities']) {
            $out[] = ['key' => 'activities', 'action' => 'generate_deck', 'title_ar' => 'أنشطة تفاعلية', 'title_en' => 'Interactive activities', 'hint_ar' => 'عرض قصير بأنشطة جماعية وحالات دراسية.', 'hint_en' => 'A short deck of group activities and case studies.',
                'params' => ['topic' => $topic, 'audience' => $kit->audience, 'objectives' => $objectives, 'slide_count' => 6, 'instructions' => 'Focus on interactive group activities and case studies.']];
        }

        return $out;
    }
}
