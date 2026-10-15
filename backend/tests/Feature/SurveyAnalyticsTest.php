<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Skill;
use App\Models\Survey;
use App\Models\SurveyEvent;
use App\Models\TrainingNeed;
use App\Models\User;
use App\Services\FeatureSettings;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/** Survey Studio v2, Gate 6: results, segments under the threshold, identified answers, comparison, exports and needs on the engine. */
class SurveyAnalyticsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        app(FeatureSettings::class)->set('survey_studio_v2', true, 'test', $this->makeUser(Role::SUPER_ADMIN));
        Cache::flush();
    }

    private function needsQuestions(string $skillId): array
    {
        return [
            ['id' => 'm', 'type' => 'matrix', 'title' => 'مستوى التمكن', 'required' => true, 'scale' => ['min' => 1, 'max' => 5], 'rows' => [['id' => 'r1', 'label' => 'إدارة الصف', 'skill_id' => $skillId], ['id' => 'r2', 'label' => 'التقويم', 'skill_name' => 'التقويم']]],
            ['id' => 'fmt', 'type' => 'single', 'title' => 'الصيغة المفضلة', 'required' => true, 'options' => [['id' => 'onsite', 'label' => 'حضوري'], ['id' => 'online', 'label' => 'عن بعد']]],
            ['id' => 'sat', 'type' => 'rating', 'title' => 'الرضا', 'scale' => ['min' => 1, 'max' => 5]],
            ['id' => 'nps', 'type' => 'nps', 'title' => 'التوصية'],
            ['id' => 'lk', 'type' => 'likert', 'title' => 'أشعر بالدعم', 'options' => ['لا أوافق', 'محايد', 'أوافق']],
            ['id' => 'why', 'type' => 'long_text', 'title' => 'لماذا؟'],
        ];
    }

    private function publish(User $admin, array $data): Survey
    {
        $id = $this->asUser($admin)->postJson('/api/v1/admin/surveys', $data + ['audience' => []])->assertCreated()->json('data.id');
        $this->asUser($admin)->postJson("/api/v1/admin/surveys/{$id}/publish")->assertOk();

        return Survey::findOrFail($id);
    }

    public function test_results_respect_the_segment_threshold_and_identified_answers_need_the_permission(): void
    {
        $admin = $this->makeUser(Role::CENTER_ADMIN);
        $viewer = $this->makeUser(Role::TRAINER); // surveys.results, not surveys.results.identified
        $exporter = $this->makeUser(Role::PLANNING_HEAD); // surveys.export, not surveys.results.identified
        $a = $this->makeSchool();
        $b = $this->makeSchool();
        $skill = Skill::where('code', 'classroom_management')->firstOrFail();
        $people = collect([[$a, 3], [$b, 3]])->flatMap(fn ($x) => collect(range(1, $x[1]))->map(fn () => $this->makeEmployee(['school_id' => $x[0]->id, 'experience_years' => 4])));

        $survey = $this->publish($admin, ['title_ar' => 'احتياجات', 'kind' => 'needs', 'privacy_mode' => 'confidential', 'questions' => $this->needsQuestions($skill->id), 'settings' => ['segment_fields' => ['school_id', 'experience_band'], 'segment_threshold' => 5]]);
        $answers = [['m' => ['r1' => 1, 'r2' => 2], 'fmt' => 'onsite', 'sat' => 5, 'nps' => 9, 'lk' => 'o3', 'why' => 'ممتاز'], ['m' => ['r1' => 2, 'r2' => 3], 'fmt' => 'online', 'sat' => 4, 'nps' => 7, 'lk' => 'o2'], ['m' => ['r1' => 5, 'r2' => 5], 'fmt' => 'onsite', 'sat' => 3, 'nps' => 3, 'lk' => 'o1', 'why' => 'طويل']];
        foreach ($people as $i => $e) {
            $this->asUser($e->user)->postJson("/api/v1/me/studio-surveys/{$survey->id}", ['answers' => $answers[$i % 3], 'duration_seconds' => 60 + $i])->assertOk();
        }

        $r = $this->asUser($viewer)->getJson("/api/v1/admin/surveys/{$survey->id}/results")->assertOk()->json('data');
        $this->assertSame([6, 6, 6, 100.0, false, 'confidential'], [$r['summary']['participants'], $r['summary']['answered'], $r['summary']['submissions'], (float) $r['summary']['response_rate'], $r['summary']['hidden'], $r['summary']['privacy_mode']]);
        $q = collect($r['questions'])->keyBy('id');
        $this->assertSame(['m', 'fmt', 'sat', 'nps', 'lk', 'why'], $q->keys()->all());
        $this->assertSame(2.67, $q['m']['rows'][0]['average']);
        $this->assertSame(4, collect($q['fmt']['options'])->firstWhere('id', 'onsite')['count']);
        $this->assertSame(4.0, (float) $q['sat']['average']);
        $this->assertSame(0, $q['nps']['nps']); // 2 promoters, 2 detractors, 2 passives
        $this->assertSame(2, collect($q['lk']['options'])->firstWhere('id', 'o3')['count']);
        $this->assertCount(4, $q['why']['samples']);
        $this->assertSame($skill->id, $r['needs'][0]['skill_id']);
        $this->assertSame(4, $r['needs'][0]['in_need']);
        // Two schools with three answers each: under the threshold of five, so neither is offered as a filter; the experience band (6) is.
        $this->assertArrayNotHasKey('school_id', $r['segments']);
        $this->assertSame(['value' => '3-5', 'label' => '3-5', 'count' => 6], $r['segments']['experience_band'][0]);
        $h = $this->asUser($viewer)->getJson("/api/v1/admin/surveys/{$survey->id}/results?school_id={$a->id}")->assertOk()->json('data');
        $this->assertTrue($h['summary']['hidden']);
        $this->assertSame([3, []], [$h['summary']['filtered'], $h['questions']]);
        $this->asUser($viewer)->getJson("/api/v1/admin/surveys/{$survey->id}/answers?school_id={$a->id}")->assertStatus(422)->assertJsonPath('code', 'survey_segment_hidden');
        $this->asUser($admin)->getJson("/api/v1/admin/surveys/{$survey->id}/export?format=csv&school_id={$a->id}")->assertStatus(422)->assertJsonPath('code', 'survey_segment_hidden');

        // A lower threshold shows the segment; the engine's segment labels are the audience's bands.
        $this->asUser($admin)->putJson("/api/v1/admin/surveys/{$survey->id}", ['settings' => ['segment_threshold' => 2]])->assertOk();
        $s = $this->asUser($viewer)->getJson("/api/v1/admin/surveys/{$survey->id}/results?school_id={$a->id}")->assertOk()->json('data');
        $this->assertFalse($s['summary']['hidden']);
        $this->assertSame(3, $s['summary']['filtered']);
        $this->assertCount(2, $s['segments']['school_id']);

        // Individual answers of a confidential survey never carry the person, whoever looks.
        $rows = $this->asUser($admin)->getJson("/api/v1/admin/surveys/{$survey->id}/answers")->assertOk()->json('data');
        $this->assertSame([6, false, true], [$rows['total'], $rows['identified'], $rows['segments_shown']]);
        $this->assertNull($rows['rows'][0]['respondent']);
        $this->assertSame($a->id, collect($rows['rows'])->pluck('segment.school_id')->filter()->sort()->first());

        // Needs are generated from the engine survey, one per school.
        $created = $this->asUser($admin)->postJson("/api/v1/admin/surveys/{$survey->id}/generate-needs", ['items' => [['key' => $skill->id]], 'split' => 'school'])->assertOk()->json('created');
        $this->assertSame(2, $created);
        $this->assertSame(2, TrainingNeed::where('engine_survey_id', $survey->id)->where('skill_id', $skill->id)->count());
        $this->assertTrue($this->asUser($admin)->getJson("/api/v1/admin/surveys/{$survey->id}/results")->json('data.needs.0.generated'));
        $this->asUser($viewer)->postJson("/api/v1/admin/surveys/{$survey->id}/generate-needs", ['items' => [['key' => $skill->id]]])->assertForbidden();

        // An identified survey names people only for viewers with the identified permission.
        $named = $this->publish($admin, ['title_ar' => 'معرّفة', 'privacy_mode' => 'identified', 'questions' => [['id' => 'sat', 'type' => 'rating', 'title' => 'الرضا', 'scale' => ['min' => 1, 'max' => 5]], ['id' => 'nps', 'type' => 'nps', 'title' => 'التوصية']]]);
        foreach ($people->take(2) as $e) {
            $this->asUser($e->user)->postJson("/api/v1/me/studio-surveys/{$named->id}", ['answers' => ['sat' => 2, 'nps' => 10]])->assertOk();
        }
        $this->assertNull($this->asUser($viewer)->getJson("/api/v1/admin/surveys/{$named->id}/answers")->assertOk()->json('data.rows.0.respondent'));
        $names = collect($this->asUser($admin)->getJson("/api/v1/admin/surveys/{$named->id}/answers")->assertOk()->json('data.rows'))->pluck('respondent.name')->all();
        $this->assertContains($people[0]->user->displayName(), $names);
        $this->assertFalse($this->asUser($admin)->getJson("/api/v1/admin/surveys/{$named->id}/results?school_id={$a->id}")->json('data.summary.hidden'), 'identified answers open to the viewer are never hidden');

        // Comparison across the two surveys on the questions they share.
        $c = $this->asUser($viewer)->getJson("/api/v1/admin/surveys/compare?ids[]={$survey->id}&ids[]={$named->id}")->assertOk()->json('data');
        $this->assertCount(2, $c['surveys']);
        $this->assertSame(['sat', 'nps'], array_column($c['questions'], 'id'));
        $this->assertSame([4.0, 2.0], [(float) $c['questions'][0]['by_survey'][$survey->id]['average'], (float) $c['questions'][0]['by_survey'][$named->id]['average']]);
        $this->assertSame(100, $c['questions'][1]['by_survey'][$named->id]['nps']);

        // Exports in every format, audited; the respondent column exists only for identified surveys and permitted viewers.
        $this->assertStringStartsWith('PK', $this->asUser($admin)->get("/api/v1/admin/surveys/{$survey->id}/export?format=xlsx")->assertOk()->getContent());
        $this->assertStringStartsWith('%PDF', $this->asUser($admin)->get("/api/v1/admin/surveys/{$survey->id}/export?format=pdf")->assertOk()->getContent());
        $csv = $this->asUser($admin)->get("/api/v1/admin/surveys/{$named->id}/export?format=csv")->assertOk()->getContent();
        $this->assertStringContainsString($people[0]->user->displayName(), $csv);
        $this->assertStringNotContainsString($people[0]->user->displayName(), $this->asUser($exporter)->get("/api/v1/admin/surveys/{$named->id}/export?format=csv")->assertOk()->getContent());
        $this->assertSame(4, SurveyEvent::whereIn('survey_id', [$survey->id, $named->id])->where('event', 'exported')->count());
        $this->asUser($this->makeEmployee()->user)->get("/api/v1/admin/surveys/{$survey->id}/export?format=csv")->assertForbidden();

        // The overview counts the studio.
        $o = $this->asUser($viewer)->getJson('/api/v1/admin/surveys/overview')->assertOk()->json('data');
        $this->assertSame(2, $o['by_status']['published']);
        $this->assertSame(8, $o['submissions_30d']);
        $this->assertCount(2, $o['open']);
    }
}
