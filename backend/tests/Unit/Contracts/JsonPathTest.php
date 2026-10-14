<?php

namespace Tests\Unit\Contracts;

use App\Integrations\Contracts\JsonPath;
use PHPUnit\Framework\TestCase;

/** The small, safe subset of JSONPath contracts use to pick values out of a response. */
class JsonPathTest extends TestCase
{
    private array $doc = ['data' => [['id' => 1, 'name' => ['ar' => 'سارة', 'en' => 'Sara'], 'tags' => ['a', 'b']], ['id' => 2, 'name' => ['ar' => 'علي', 'en' => 'Ali']]], 'meta' => ['next_page' => 2, 'a-b' => 'dash', 'zero' => 0, 'nothing' => null]];

    public function test_it_reads_keys_indexes_and_quoted_keys(): void
    {
        $this->assertSame(2, JsonPath::get($this->doc, '$.meta.next_page'));
        $this->assertSame('Sara', JsonPath::get($this->doc, '$.data[0].name.en'));
        $this->assertSame('b', JsonPath::get($this->doc, '$.data[0].tags[1]'));
        $this->assertSame('dash', JsonPath::get($this->doc, "$.meta['a-b']"));
        $this->assertSame('dash', JsonPath::get($this->doc, '$.meta["a-b"]'));
        $this->assertSame(0, JsonPath::get($this->doc, '$.meta.zero'), 'zero is a value');
        $this->assertSame($this->doc, JsonPath::get($this->doc, '$'));
    }

    public function test_a_missing_path_is_null_never_an_error(): void
    {
        foreach (['$.nope', '$.data[9]', '$.data[0].name.fr', '$.meta.nothing.deeper', '$.meta.next_page.deeper'] as $p) {
            $this->assertNull(JsonPath::get($this->doc, $p), $p);
        }
    }

    public function test_a_wildcard_collects_a_list(): void
    {
        $this->assertSame([1, 2], JsonPath::all($this->doc, '$.data[*].id'));
        $this->assertSame(['Sara', 'Ali'], JsonPath::all($this->doc, '$.data[*].name.en'));
        $this->assertSame($this->doc['data'], JsonPath::all($this->doc, '$.data[*]'));
        $this->assertSame([], JsonPath::all($this->doc, '$.nope[*].id'));
        $this->assertSame([1], JsonPath::all($this->doc, '$.data[0].id'), 'a path without a wildcard yields at most one value');
    }

    public function test_an_equality_filter_keeps_the_elements_whose_key_has_that_text(): void
    {
        $doc = ['activities' => [
            ['engagementType' => 'VIEWS', 'engagementValue' => 3],
            ['engagementType' => 'COMPLETIONS', 'engagementValue' => 1, 'lastEngagedAt' => 1760261400000],
            ['engagementType' => 'COMPLETIONS', 'engagementValue' => 0, 'lastEngagedAt' => 5],
        ]];
        $this->assertSame([1760261400000, 5], JsonPath::all($doc, "$.activities[?(@.engagementType=='COMPLETIONS')].lastEngagedAt"));
        $this->assertSame([1, 0], JsonPath::all($doc, '$.activities[?(@.engagementType=="COMPLETIONS")].engagementValue'), 'double quotes work too');
        $this->assertSame(1760261400000, JsonPath::get($doc, "$.activities[?(@.engagementType=='COMPLETIONS')].lastEngagedAt"), 'without a wildcard the first match is the value');
        $this->assertSame([], JsonPath::all($doc, "$.activities[?(@.engagementType=='SECONDS_VIEWED')].engagementValue"));
        $this->assertSame([], JsonPath::all(['activities' => 'text'], "$.activities[?(@.engagementType=='COMPLETIONS')]"), 'a filter over something that is not a list finds nothing');
        $this->assertCount(2, JsonPath::all($doc, "$.activities[?(@.engagementType=='COMPLETIONS')]"));
    }

    public function test_only_the_safe_subset_is_accepted(): void
    {
        $this->assertTrue(JsonPath::valid('$.a.b[0].c[*].d'));
        $this->assertTrue(JsonPath::valid("$['a b']['c']"));
        $this->assertTrue(JsonPath::valid("$.a[?(@.kind=='done')].at"));
        foreach (['a.b', '$..a', '$.a[?(@.x==1)]', '$.a[1:3]', '$.a[(@.length-1)]', '$.a[0,1]', '$.a.', '', '$.a[x]',
            "$.a[?(@.k=='v' && @.j=='w')]", "$.a[?(@['k']=='v')]", "$.a[?(@.k=~'v')]", "$.a[?(@.k!='v')]", "$.a[?(@.k=='it\\'s')]", "$.a[?(@.k=='v')", "$.a[?(@.k=='')]x", '$.a[?(@.k==$x)]'] as $bad) {
            $this->assertFalse(JsonPath::valid($bad), $bad);
        }
    }
}
