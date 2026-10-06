<?php

namespace App\Ai\Adaptive;

use App\Ai\AiGuard;
use App\Ai\Rag\Retriever;
use App\Models\CourseLesson;
use App\Models\CourseModule;
use App\Models\Program;
use App\Models\Skill;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Drafts a short remedial lesson for a competency (a summary, key points and practice questions). It is created as a DRAFT lesson marked as AI-made;
 * the trainer reads, edits and publishes it through the normal lesson screen — it reaches learners only after that.
 */
class RemedialBuilder
{
    public function __construct(private readonly AiGuard $guard, private readonly Retriever $retriever) {}

    public function draft(Program $program, Skill $skill, User $by): CourseLesson
    {
        $module = CourseModule::where('program_id', $program->id)->orderBy('sort_order')->first()
            ?? CourseModule::create(['program_id' => $program->id, 'title_ar' => 'محتوى علاجي', 'title_en' => 'Remedial content', 'sort_order' => 999]);
        $material = $this->material($program, $skill);
        $ai = $this->guard->json('adaptive', 'You write short, clear remedial lessons for teachers. Write the lesson in Arabic and in English. Use only the material given; do not invent facts or sources.',
            "COMPETENCY: {$skill->name_ar} / {$skill->name_en}\nDESCRIPTORS: ".json_encode($skill->descriptors, JSON_UNESCAPED_UNICODE)."\nMATERIAL FROM THE COURSE:\n{$material}",
            ['type' => 'object', 'required' => ['summary_ar', 'summary_en', 'key_points_ar', 'key_points_en'], 'properties' => ['summary_ar' => ['type' => 'string'], 'summary_en' => ['type' => 'string'], 'key_points_ar' => ['type' => 'array', 'items' => ['type' => 'string']], 'key_points_en' => ['type' => 'array', 'items' => ['type' => 'string']], 'practice' => ['type' => 'array', 'items' => ['type' => 'string']]]], $by, false);
        $e = fn ($t) => htmlspecialchars((string) $t, ENT_QUOTES);
        $list = fn ($a) => '<ul>'.implode('', array_map(fn ($x) => '<li>'.$e($x).'</li>', (array) $a)).'</ul>';
        if ($ai && isset($ai['summary_ar'])) {
            $ar = '<p>'.$e($ai['summary_ar']).'</p>'.$list($ai['key_points_ar'] ?? []);
            $en = '<p>'.$e($ai['summary_en'] ?? '').'</p>'.$list($ai['key_points_en'] ?? []);
            if (! empty($ai['practice'])) {
                $en .= '<h4>Practice</h4>'.$list($ai['practice']);
            }
            $source = 'ai';
        } else {
            $ar = '<p>ملخص مبدئي للكفاية «'.$e($skill->name_ar).'» — يحتاج المدرب إلى مراجعته وإكماله.</p>'.($material ? '<p>'.$e(Str::limit($material, 600)).'</p>' : '');
            $en = '<p>Starting outline for the competency "'.$e($skill->name_en).'" — the trainer must review and complete it.</p>'.($material ? '<p>'.$e(Str::limit($material, 600)).'</p>' : '');
            $source = 'rules';
        }

        return CourseLesson::create([
            'program_id' => $program->id, 'module_id' => $module->id, 'type' => 'article', 'title_ar' => 'مراجعة: '.$skill->name_ar, 'title_en' => 'Review: '.$skill->name_en, 'body_ar' => $ar, 'body_en' => $en,
            'sort_order' => 900, 'is_required' => false, 'status' => 'draft', 'settings' => ['remedial' => true, 'ai_generated' => $source === 'ai', 'skill_id' => $skill->id, 'draft_by' => $by->id],
        ]);
    }

    /** Passages of the course that mention the competency, as grounding for the draft. */
    private function material(Program $program, Skill $skill): string
    {
        $hits = $this->retriever->searchProgram($program->id, trim($skill->name_ar.' '.$skill->name_en), 3);

        return implode("\n---\n", array_map(fn ($h) => $h['title'].': '.$h['content'], $hits));
    }
}
