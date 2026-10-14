<?php

namespace Tests\Unit\Contracts;

use App\Integrations\Contracts\Mapper;
use PHPUnit\Framework\TestCase;

/** A mapping turns a system's record into TEDC's fields with a fixed set of transforms: a field map is data, never code. */
class MapperTest extends TestCase
{
    private function entity(): array
    {
        return [
            'fields' => [
                'employee_no' => '$.staff.no',
                'name' => ['path' => '$.full_name.en', 'transform' => ['trim']],
                'name_ar' => ['path' => '$.full_name.ar', 'transform' => ['trim']],
                'hire_date' => ['path' => '$.joined', 'transform' => ['date:d/m/Y>Y-m-d']],
                'status' => ['path' => '$.state', 'transform' => ['table:status'], 'default' => 'active'],
                'email' => ['paths' => ['$.work_email', '$.personal_email'], 'transform' => ['lower']],
                'grades' => ['path' => '$.grades', 'transform' => ['split:;']],
                'experience_years' => ['path' => '$.exp', 'transform' => ['int']],
                'source' => ['const' => 'mawared'],
                'label' => ['template' => '{{ staff.no }} — {{ full_name.en|trim }}'],
                'job_title_code' => '$.position.code',
            ],
            'tables' => ['status' => ['A' => 'active', 'T' => 'left', 'R' => 'left']],
        ];
    }

    public function test_it_maps_paths_transforms_tables_constants_and_templates(): void
    {
        $out = Mapper::apply($this->entity(), [
            'staff' => ['no' => 'E-77'], 'full_name' => ['en' => '  Sara Ali ', 'ar' => ' سارة علي '], 'joined' => '15/01/2020', 'state' => 'T',
            'work_email' => '', 'personal_email' => 'SARA@Example.COM', 'grades' => '4;5;6', 'exp' => '12', 'position' => ['code' => 'T-9'],
        ]);
        $this->assertSame('E-77', $out['employee_no']);
        $this->assertSame(['Sara Ali', 'سارة علي'], [$out['name'], $out['name_ar']]);
        $this->assertSame('2020-01-15', $out['hire_date']);
        $this->assertSame('left', $out['status']);
        $this->assertSame('sara@example.com', $out['email'], 'the first non-empty path wins');
        $this->assertSame(['4', '5', '6'], $out['grades']);
        $this->assertSame(12, $out['experience_years']);
        $this->assertSame(['mawared', 'E-77 — Sara Ali', 'T-9'], [$out['source'], $out['label'], $out['job_title_code']]);
    }

    public function test_missing_values_use_the_default_or_stay_out(): void
    {
        $out = Mapper::apply($this->entity(), ['staff' => ['no' => 'E-1']]);
        $this->assertSame('active', $out['status'], 'the default fills a gap');
        $this->assertArrayNotHasKey('hire_date', $out);
        $this->assertArrayNotHasKey('name', $out);
        $this->assertArrayNotHasKey('email', $out);
    }

    public function test_an_unknown_code_passes_through_the_table_unchanged_and_a_bad_date_stays_out(): void
    {
        $out = Mapper::apply($this->entity(), ['staff' => ['no' => 'E-2'], 'state' => 'Z', 'joined' => 'not a date']);
        $this->assertSame('Z', $out['status']);
        $this->assertArrayNotHasKey('hire_date', $out);
    }

    public function test_the_system_s_arabic_and_english_names_can_share_one_field(): void
    {
        $e = ['fields' => ['name_ar' => ['path' => '$.name', 'transform' => ['name_part:ar']], 'name' => ['path' => '$.name', 'transform' => ['name_part:en']]]];
        $out = Mapper::apply($e, ['name' => 'سارة علي / Sara Ali']);
        $this->assertSame(['سارة علي', 'Sara Ali'], [$out['name_ar'], $out['name']]);
        $this->assertSame(['name' => 'Solo'], Mapper::apply(['fields' => ['name' => ['path' => '$.n', 'transform' => ['name_part:en']]]], ['n' => 'Solo']));
    }

    public function test_an_amount_in_minor_units_becomes_a_decimal_amount(): void
    {
        $e = ['fields' => ['amount' => ['path' => '$.amount', 'transform' => ['minor:2']], 'big' => ['path' => '$.big', 'transform' => ['minor:3']]]];
        $this->assertSame(['amount' => 10.0, 'big' => 10.5], Mapper::apply($e, ['amount' => '1000', 'big' => 10500]));
        $this->assertSame(['amount' => 19.99], Mapper::apply($e, ['amount' => 1999]), 'no rounding error');
        $this->assertSame([], Mapper::apply($e, ['amount' => 'not a number']), 'a value that is not a number stays out');
        $this->assertSame([], Mapper::problems($e));
        $this->assertNotSame([], Mapper::problems(['fields' => ['a' => ['path' => '$.a', 'transform' => ['minor:x']]]]));
        $this->assertNotSame([], Mapper::problems(['fields' => ['a' => ['path' => '$.a', 'transform' => ['minor']]]]));
    }

    public function test_times_durations_and_markup_from_other_systems_become_ours(): void
    {
        $e = ['fields' => [
            'completed_at' => ['path' => '$.completedAt', 'transform' => ['epoch_ms']],
            'seen_at' => ['path' => '$.seen', 'transform' => ['epoch_s']],
            'hours' => ['path' => '$.minutes', 'transform' => ['hours:minutes']],
            'hours_from_seconds' => ['path' => '$.secs', 'transform' => ['hours:seconds']],
            'whole_hours' => ['path' => '$.h', 'transform' => ['hours:hours']],
            'about' => ['path' => '$.html', 'transform' => ['strip_tags']],
        ]];
        $out = Mapper::apply($e, ['completedAt' => 1791797400000, 'seen' => '1791797400', 'minutes' => 42, 'secs' => 5400, 'h' => '3', 'html' => "<p>Course on <b>Data</b>&nbsp;Engineering &amp; more</p>\n<ul><li>one</li></ul>"]);
        $this->assertSame('2026-10-12T09:30:00+00:00', $out['completed_at'], 'milliseconds since 1970, in UTC');
        $this->assertSame('2026-10-12T09:30:00+00:00', $out['seen_at']);
        $this->assertSame([0.7, 1.5, 3.0], [$out['hours'], $out['hours_from_seconds'], $out['whole_hours']]);
        $this->assertSame('Course on Data Engineering & more one', $out['about'], 'tags go, entities are read, white space is one space');
        $this->assertSame([], Mapper::apply($e, ['completedAt' => 'soon', 'minutes' => 'a while', 'html' => '']), 'what is not a number or a time stays out');
        $this->assertSame([], Mapper::problems($e));
        $this->assertNotSame([], Mapper::problems(['fields' => ['a' => ['path' => '$.a', 'transform' => ['hours:days']]]]));
        $this->assertNotSame([], Mapper::problems(['fields' => ['a' => ['path' => '$.a', 'transform' => ['hours']]]]));
    }

    public function test_a_count_becomes_a_yes_or_a_no(): void
    {
        $e = ['fields' => ['completed' => ['path' => '$.completions', 'transform' => ['positive']]]];
        $this->assertSame(['completed' => true], Mapper::apply($e, ['completions' => 1]));
        $this->assertSame(['completed' => true], Mapper::apply($e, ['completions' => '3']));
        $this->assertSame(['completed' => false], Mapper::apply($e, ['completions' => 0]), 'none is a no, and a no is kept');
        $this->assertSame([], Mapper::apply($e, []), 'nothing said is nothing said');
        $this->assertSame([], Mapper::apply($e, ['completions' => 'many']));
        $this->assertSame([], Mapper::problems($e));
    }

    public function test_a_mapping_is_checked_before_it_is_saved(): void
    {
        $this->assertSame([], Mapper::problems($this->entity()));
        $bad = Mapper::problems(['fields' => [
            'a' => '$.a..b', 'b' => ['path' => '$.b', 'transform' => ['eval:1']], 'c' => ['path' => '$.c', 'transform' => ['table:missing']], 'd' => ['template' => '{{ x|php }}'],
            'e' => ['nothing' => 1], 'f' => ['path' => '$.f', 'transform' => ['date:Y']], 'g' => ['const' => ['no' => 'arrays']],
        ], 'tables' => ['t' => 'not a map']]);
        $this->assertGreaterThanOrEqual(7, count($bad), implode('; ', $bad));
        $this->assertNotSame([], Mapper::problems(['nofields' => []]));
    }
}
