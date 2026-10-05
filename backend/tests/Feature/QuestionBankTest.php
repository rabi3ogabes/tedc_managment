<?php

namespace Tests\Feature;

use App\Models\Question;
use App\Models\Role;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class QuestionBankTest extends TestCase
{
    private function trainingHead()
    {
        return $this->makeUser(Role::TRAINING_HEAD);
    }

    private function sc(string $text = 'ما عاصمة قطر؟', array $extra = []): array
    {
        return $extra + ['type' => 'single_choice', 'stem_ar' => $text, 'stem_en' => 'Capital?', 'difficulty' => 'easy', 'points' => 2, 'payload' => ['options' => [['id' => 'a', 'text_ar' => 'الدوحة', 'correct' => true], ['id' => 'b', 'text_ar' => 'الريان']]]];
    }

    public function test_banks_categories_and_questions_are_managed_with_validation_per_type(): void
    {
        $head = $this->trainingHead();
        $bank = $this->asUser($head)->postJson('/api/v1/admin/question-banks', ['title_ar' => 'بنك', 'title_en' => 'Bank', 'visibility' => 'center'])->assertCreated()->json('data.id');
        $cat = $this->asUser($head)->postJson("/api/v1/admin/question-banks/{$bank}/categories", ['name_ar' => 'الوحدة 1', 'name_en' => 'Unit 1'])->assertCreated()->json('data.id');

        $q = $this->asUser($head)->postJson("/api/v1/admin/question-banks/{$bank}/questions", $this->sc('x', ['category_id' => $cat]))->assertCreated();
        $this->assertSame(1, $q->json('data.version'));
        $this->asUser($head)->postJson("/api/v1/admin/question-banks/{$bank}/questions", ['type' => 'single_choice', 'stem_ar' => 'x', 'payload' => ['options' => [['id' => 'a', 'text_ar' => 'A'], ['id' => 'b', 'text_ar' => 'B']]]])->assertStatus(422);
        $this->asUser($head)->postJson("/api/v1/admin/question-banks/{$bank}/questions", ['type' => 'nope', 'stem_ar' => 'x', 'payload' => []])->assertStatus(422);
        $this->asUser($head)->getJson("/api/v1/admin/question-banks/{$bank}/questions?type=single_choice&difficulty=easy&category_id={$cat}")->assertOk()->assertJsonCount(1, 'data');
        $this->asUser($this->makeUser(Role::EMPLOYEE))->postJson('/api/v1/admin/question-banks', ['title_ar' => 'x', 'title_en' => 'x'])->assertForbidden();
        $this->asUser($head)->getJson('/api/v1/admin/question-types')->assertOk()->assertJsonFragment(['key' => 'essay']);
    }

    public function test_editing_an_active_question_makes_a_new_version_and_retires_the_old_one(): void
    {
        $head = $this->trainingHead();
        $bank = $this->asUser($head)->postJson('/api/v1/admin/question-banks', ['title_ar' => 'بنك', 'title_en' => 'Bank'])->assertCreated()->json('data.id');
        $id = $this->asUser($head)->postJson("/api/v1/admin/question-banks/{$bank}/questions", $this->sc())->json('data.id');

        $res = $this->asUser($head)->putJson("/api/v1/admin/questions/{$id}", ['stem_ar' => 'ما عاصمة دولة قطر؟'] + $this->sc())->assertOk();

        $this->assertSame(2, $res->json('data.version'));
        $this->assertNotSame($id, $res->json('data.id'));
        $this->assertSame('retired', Question::find($id)->status);
        $this->assertSame(Question::find($id)->root_id ?? $id, Question::find($res->json('data.id'))->root_id);
        $this->asUser($head)->getJson("/api/v1/admin/question-banks/{$bank}/questions")->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_duplicates_are_detected_and_questions_can_be_bulk_tagged_and_moved(): void
    {
        $head = $this->trainingHead();
        $bank = $this->asUser($head)->postJson('/api/v1/admin/question-banks', ['title_ar' => 'بنك', 'title_en' => 'Bank'])->json('data.id');
        $cat = $this->asUser($head)->postJson("/api/v1/admin/question-banks/{$bank}/categories", ['name_ar' => 'ج', 'name_en' => 'C'])->json('data.id');
        $one = $this->asUser($head)->postJson("/api/v1/admin/question-banks/{$bank}/questions", $this->sc())->assertCreated();
        $dup = $this->asUser($head)->postJson("/api/v1/admin/question-banks/{$bank}/questions", $this->sc())->assertCreated();
        $this->assertSame($one->json('data.id'), $dup->json('data.duplicate_of'));

        $this->asUser($head)->postJson("/api/v1/admin/question-banks/{$bank}/questions/bulk", ['ids' => [$one->json('data.id'), $dup->json('data.id')], 'category_id' => $cat, 'tags' => ['unit1'], 'difficulty' => 'hard'])->assertOk()->assertJsonPath('data.updated', 2);
        $this->assertSame(['unit1'], Question::find($one->json('data.id'))->tags);
        $this->assertSame('hard', Question::find($dup->json('data.id'))->difficulty);
    }

    public function test_a_spreadsheet_round_trips_the_simple_types_with_a_validation_report(): void
    {
        $head = $this->trainingHead();
        $bank = $this->asUser($head)->postJson('/api/v1/admin/question-banks', ['title_ar' => 'بنك', 'title_en' => 'Bank'])->json('data.id');
        $csv = "type,stem_ar,stem_en,difficulty,points,option_a,option_b,option_c,option_d,correct,accepted,value,tolerance\n"
            ."single_choice,ما لون السماء؟,Sky colour?,easy,1,أزرق,أحمر,أخضر,,A,,,\n"
            ."true_false,الشمس نجم,The sun is a star,easy,1,,,,,true,,,\n"
            ."short_answer,عاصمة مصر؟,,medium,1,,,,,,القاهرة|Cairo,,\n"
            ."numeric,2+2,,easy,1,,,,,,,4,0\n"
            ."single_choice,بلا إجابة,,easy,1,نعم,لا,,,,,,\n"
            ."unknown_type,x,,easy,1,,,,,,,,\n";
        $file = UploadedFile::fake()->createWithContent('q.csv', $csv);

        $res = $this->asUser($head)->post("/api/v1/admin/question-banks/{$bank}/import", ['file' => $file], ['Accept' => 'application/json'])->assertOk();
        $this->assertSame(4, $res->json('data.created'));
        $this->assertCount(2, $res->json('data.errors'));
        $export = $this->asUser($head)->get("/api/v1/admin/question-banks/{$bank}/export?format=csv")->assertOk()->getContent();
        $this->assertStringContainsString('عاصمة مصر', $export);
    }
}
