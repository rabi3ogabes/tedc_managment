<?php

namespace Tests\Unit;

use App\Services\Assessment\ArabicText;
use App\Services\Assessment\QuestionTypes;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class QuestionTypesTest extends TestCase
{
    private function grade(string $type, array $payload, mixed $answer): array
    {
        $t = QuestionTypes::get($type);
        $r = $t->grade($t->validate($payload), $answer);

        return [round($r['ratio'], 2), $r['manual']];
    }

    private function opts(array $correct, int $n = 4): array
    {
        return array_map(fn ($i) => ['id' => 'o'.$i, 'text_ar' => 'خ'.$i, 'text_en' => 'O'.$i, 'correct' => in_array('o'.$i, $correct, true)], range(1, $n));
    }

    public function test_the_registry_lists_every_rfp_type_and_can_be_extended(): void
    {
        foreach (['single_choice', 'multiple_select', 'true_false', 'dropdown', 'matrix', 'essay', 'short_answer', 'fill_blanks', 'matching', 'ordering', 'categorization', 'hotspot', 'numeric', 'h5p'] as $k) {
            $this->assertContains($k, QuestionTypes::keys());
        }
        QuestionTypes::register(new class extends \App\Services\Assessment\Types\TrueFalse
        {
            public function key(): string
            {
                return 'custom_tf';
            }
        });
        $this->assertContains('custom_tf', QuestionTypes::keys());
    }

    public function test_single_and_multiple_choice_with_partial_credit(): void
    {
        $this->assertSame([1.0, false], $this->grade('single_choice', ['options' => $this->opts(['o2'])], 'o2'));
        $this->assertSame([0.0, false], $this->grade('single_choice', ['options' => $this->opts(['o2'])], 'o1'));
        $m = ['options' => $this->opts(['o1', 'o2'])];
        $this->assertSame([1.0, false], $this->grade('multiple_select', $m, ['o1', 'o2']));
        $this->assertSame([0.5, false], $this->grade('multiple_select', $m, ['o1']));
        $this->assertSame([0.0, false], $this->grade('multiple_select', $m, ['o1', 'o3', 'o4']), 'wrong picks cancel the credit');
        $this->assertSame([0.0, false], $this->grade('multiple_select', $m + ['partial' => false], ['o1']));
    }

    public function test_choice_payloads_are_validated(): void
    {
        $this->expectException(ValidationException::class);
        QuestionTypes::get('single_choice')->validate(['options' => $this->opts(['o1', 'o2'])]);
    }

    public function test_true_false_dropdown_and_matrix(): void
    {
        $this->assertSame([1.0, false], $this->grade('true_false', ['correct' => true], 'true'));
        $this->assertSame([0.0, false], $this->grade('true_false', ['correct' => false], true));

        $dd = ['template_ar' => 'القط {{1}} والكلب {{2}}', 'blanks' => [['id' => 'b1', 'options' => [['id' => 'x', 'text' => 'ينام'], ['id' => 'y', 'text' => 'يجري']], 'correct' => 'x'], ['id' => 'b2', 'options' => [['id' => 'x', 'text' => 'ينبح'], ['id' => 'y', 'text' => 'يطير']], 'correct' => 'x']]];
        $this->assertSame([0.5, false], $this->grade('dropdown', $dd, ['b1' => 'x', 'b2' => 'y']));

        $mx = ['rows' => [['id' => 'r1', 'text' => 'A'], ['id' => 'r2', 'text' => 'B']], 'cols' => [['id' => 'c1', 'text' => '1'], ['id' => 'c2', 'text' => '2']], 'correct' => ['r1' => 'c1', 'r2' => 'c2']];
        $this->assertSame([0.5, false], $this->grade('matrix', $mx, ['r1' => 'c1', 'r2' => 'c1']));
        $likert = ['rows' => $mx['rows'], 'cols' => $mx['cols']];
        $this->assertSame([1.0, false], $this->grade('matrix', $likert, ['r1' => 'c2', 'r2' => 'c1']), 'a Likert grid without a key scores completeness');
        $this->assertSame([0.5, false], $this->grade('matrix', $likert, ['r1' => 'c2']));
    }

    public function test_short_answer_and_fill_blanks_ignore_arabic_diacritics_and_letter_variants(): void
    {
        $this->assertSame('احمد', ArabicText::normalize('  أَحْمَد '));
        $this->assertSame('مدرسه', ArabicText::normalize('مدرسة'));
        $this->assertSame('علي', ArabicText::normalize('علـــى'));

        $sa = ['accepted' => ['الإمارات', 'Emirates']];
        $this->assertSame([1.0, false], $this->grade('short_answer', $sa, 'الامارات'));
        $this->assertSame([1.0, false], $this->grade('short_answer', $sa, ' emirates '));
        $this->assertSame([0.0, false], $this->grade('short_answer', $sa, 'قطر'));
        $this->assertSame([0.0, false], $this->grade('short_answer', ['accepted' => ['Doha'], 'case_sensitive' => true], 'doha'));

        $fb = ['text_ar' => 'عاصمة {{1}} هي {{2}}', 'blanks' => [['id' => 'a', 'accepted' => ['قطر']], ['id' => 'b', 'accepted' => ['الدوحة', 'دوحة']]]];
        $this->assertSame([0.5, false], $this->grade('fill_blanks', $fb, ['a' => 'قطر', 'b' => 'لندن']));
        $this->assertSame([1.0, false], $this->grade('fill_blanks', $fb, ['a' => 'قطر', 'b' => 'الدّوحة']));
    }

    public function test_matching_ordering_and_categorization(): void
    {
        $mt = ['pairs' => [['id' => 'p1', 'left' => 'قطر', 'right' => 'الدوحة'], ['id' => 'p2', 'left' => 'مصر', 'right' => 'القاهرة'], ['id' => 'p3', 'left' => 'عمان', 'right' => 'مسقط']]];
        $this->assertSame([0.67, false], $this->grade('matching', $mt, ['p1' => 'p1', 'p2' => 'p2', 'p3' => 'p1']));

        $or = ['items' => [['id' => 'a', 'text' => '1'], ['id' => 'b', 'text' => '2'], ['id' => 'c', 'text' => '3'], ['id' => 'd', 'text' => '4']]];
        $this->assertSame([1.0, false], $this->grade('ordering', $or, ['a', 'b', 'c', 'd']));
        $this->assertSame([0.5, false], $this->grade('ordering', $or, ['a', 'b', 'd', 'c']));
        $this->assertSame([0.0, false], $this->grade('ordering', $or + ['strict' => true], ['a', 'b', 'd', 'c']));

        $ct = ['buckets' => [['id' => 'fruit', 'name' => 'فواكه'], ['id' => 'veg', 'name' => 'خضار']], 'items' => [['id' => 'i1', 'text' => 'تفاح', 'bucket' => 'fruit'], ['id' => 'i2', 'text' => 'جزر', 'bucket' => 'veg']]];
        $this->assertSame([0.5, false], $this->grade('categorization', $ct, ['i1' => 'fruit', 'i2' => 'fruit']));
    }

    public function test_hotspot_numeric_essay_and_h5p(): void
    {
        $hs = ['image' => 'x.png', 'areas' => [['id' => 'a', 'shape' => 'rect', 'x' => 10, 'y' => 10, 'w' => 20, 'h' => 20, 'correct' => true], ['id' => 'b', 'shape' => 'circle', 'x' => 80, 'y' => 80, 'r' => 5]]];
        $this->assertSame([1.0, false], $this->grade('hotspot', $hs, ['x' => 15, 'y' => 25]));
        $this->assertSame([0.0, false], $this->grade('hotspot', $hs, ['x' => 80, 'y' => 80]), 'only the areas marked correct score');
        $this->assertSame([0.0, false], $this->grade('hotspot', $hs, ['x' => 50, 'y' => 50]));

        $this->assertSame([1.0, false], $this->grade('numeric', ['value' => 9.81, 'tolerance' => 0.05], '9.8'));
        $this->assertSame([0.0, false], $this->grade('numeric', ['value' => 9.81, 'tolerance' => 0.05], 10));
        $this->assertSame([1.0, false], $this->grade('numeric', ['value' => 200, 'tolerance_percent' => 10], '210'));

        $this->assertSame([0.0, true], $this->grade('essay', ['max_words' => 300], ['text' => 'مقالي']));
        $this->assertSame([0.6, false], $this->grade('h5p', ['content_url' => 'https://h5p.example/1'], ['score' => 3, 'max' => 5]));
    }

    public function test_public_payloads_never_reveal_the_answers(): void
    {
        $sc = QuestionTypes::get('single_choice');
        $p = $sc->validate(['options' => $this->opts(['o1'])]);
        $this->assertStringNotContainsString('correct', json_encode($sc->publicPayload($p, false)));

        $mt = QuestionTypes::get('matching');
        $public = $mt->publicPayload($mt->validate(['pairs' => [['id' => 'p1', 'left' => 'a', 'right' => 'b'], ['id' => 'p2', 'left' => 'c', 'right' => 'd']]]), false);
        $this->assertArrayHasKey('lefts', $public);
        $this->assertArrayHasKey('rights', $public);
        $this->assertArrayNotHasKey('pairs', $public);

        $nu = QuestionTypes::get('numeric');
        $this->assertArrayNotHasKey('value', $nu->publicPayload($nu->validate(['value' => 3, 'tolerance' => 0]), false));
    }
}
