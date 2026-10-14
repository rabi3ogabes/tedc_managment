<?php

namespace Tests\Unit\Contracts;

use App\Integrations\Contracts\ContractDiff;
use PHPUnit\Framework\TestCase;

/** The difference between two versions of a contract or a mapping, path by path: what the administrator reviews before a rollback. */
class ContractDiffTest extends TestCase
{
    public function test_it_lists_what_changed_was_added_and_was_removed_by_path(): void
    {
        $before = ['version' => '1.0', 'operations' => ['x' => ['path' => '/a', 'query' => ['k' => '1']]], 'tags' => ['a', 'b']];
        $after = ['version' => '1.1', 'operations' => ['x' => ['path' => '/b'], 'y' => ['path' => '/y']], 'tags' => ['a', 'c', 'd']];

        $this->assertSame([
            ['path' => 'operations.x.path', 'change' => 'changed', 'before' => '/a', 'after' => '/b'],
            ['path' => 'operations.x.query.k', 'change' => 'removed', 'before' => '1', 'after' => null],
            ['path' => 'operations.y.path', 'change' => 'added', 'before' => null, 'after' => '/y'],
            ['path' => 'tags.1', 'change' => 'changed', 'before' => 'b', 'after' => 'c'],
            ['path' => 'tags.2', 'change' => 'added', 'before' => null, 'after' => 'd'],
            ['path' => 'version', 'change' => 'changed', 'before' => '1.0', 'after' => '1.1'],
        ], ContractDiff::between($before, $after));
    }

    public function test_identical_documents_have_no_difference_and_the_order_of_keys_does_not_matter(): void
    {
        $this->assertSame([], ContractDiff::between(['a' => 1, 'b' => ['c' => 2]], ['b' => ['c' => 2], 'a' => 1]));
        $this->assertSame([], ContractDiff::between([], []));
    }

    public function test_an_empty_object_or_list_that_appears_or_disappears_is_a_change(): void
    {
        $this->assertSame([['path' => 'tests', 'change' => 'removed', 'before' => [], 'after' => null]], ContractDiff::between(['tests' => []], []));
        $this->assertSame([['path' => 'tests', 'change' => 'added', 'before' => null, 'after' => []]], ContractDiff::between([], ['tests' => []]));
    }

    public function test_a_long_text_is_cut_for_the_screen_but_a_change_in_it_is_still_seen(): void
    {
        $diff = ContractDiff::between(['d' => str_repeat('a', 500).'1'], ['d' => str_repeat('a', 500).'2']);
        $this->assertCount(1, $diff);
        $this->assertLessThanOrEqual(301, mb_strlen($diff[0]['before']));
        $this->assertLessThanOrEqual(301, mb_strlen($diff[0]['after']));
    }
}
