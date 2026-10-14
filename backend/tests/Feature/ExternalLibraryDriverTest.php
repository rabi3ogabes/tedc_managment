<?php

namespace Tests\Feature;

use App\Models\LibraryItem;
use App\Models\Role;
use App\Services\Library\ExternalLibraryService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The external libraries follow the interfaces their own sites document: Qatar National Library's Manara repository answers through the public
 * Figshare API; Maktabati and the QNL catalogue document no public interface, so they stay deep links. Imported items keep their source link and rights.
 */
class ExternalLibraryDriverTest extends TestCase
{
    private const PUBLIC_PAGE = 'https://manara.qnl.qa/articles/journal_contribution/Teacher_leadership_in_the_Arab_states_A_systematic_review/32132851';

    private function manager()
    {
        return $this->asUser($this->makeUser(Role::TRAINING_HEAD));
    }

    /** @return array<string, mixed> */
    private function listed(int $id, string $title, string $type = 'journal contribution', ?string $page = null): array
    {
        return ['id' => $id, 'title' => $title, 'doi' => '10.1177/'.$id, 'handle' => '', 'url' => "https://api.figshare.com/v2/articles/{$id}", 'url_public_html' => $page ?? "https://manara.qnl.qa/articles/{$id}", 'url_public_api' => "https://api.figshare.com/v2/articles/{$id}",
            'published_date' => '2026-10-12T07:25:18Z', 'defined_type_name' => $type, 'thumb' => '', 'group_id' => null];
    }

    /** @return array<string, mixed> */
    private function record(): array
    {
        return $this->listed(32132851, 'Teacher leadership in the Arab states: A systematic review', 'journal contribution', self::PUBLIC_PAGE) + [
            'description' => '<p dir="ltr">This systematic review examines the literature on <b>teacher leadership</b> in the Arab States.</p>',
            'license' => ['value' => 1, 'name' => 'CC BY 4.0', 'url' => 'https://creativecommons.org/licenses/by/4.0/'],
            'authors' => [['id' => 1, 'full_name' => 'Norma Ghamrawi', 'orcid_id' => ''], ['id' => 2, 'full_name' => 'Tarek Shal', 'orcid_id' => '']],
            'tags' => ['Teacher leadership', 'Arab states'], 'categories' => [['id' => 32816, 'title' => 'Education', 'parent_id' => 0], ['id' => 32819, 'title' => 'Curriculum and pedagogy', 'parent_id' => 32816]],
            'citation' => 'Ghamrawi, N.; Shal, T. (2025). Manara - Qatar Research Repository.', 'figshare_url' => self::PUBLIC_PAGE, 'is_public' => true,
        ];
    }

    private function configure(array $qnl = [], bool $on = true): void
    {
        $this->manager()->putJson('/api/v1/admin/settings/external-libraries', ['qnl' => ['enabled' => $on, 'driver' => 'figshare'] + $qnl])->assertOk();
    }

    /** @return Collection<int, Request> */
    private function sent(string $contains)
    {
        return collect(Http::recorded())->map(fn ($p) => $p[0])->filter(fn (Request $r) => str_contains($r->url(), $contains))->values();
    }

    public function test_manara_is_searched_through_the_public_figshare_api_for_its_own_institution_only(): void
    {
        Http::fake(['api.figshare.com/v2/articles/search' => Http::response([$this->listed(32132851, 'Teacher leadership in the Arab states', 'journal contribution'), $this->listed(32133430, 'A book on curriculum', 'book'), $this->listed(32133431, 'A dataset', 'dataset')])]);
        $this->configure();

        $res = $this->manager()->getJson('/api/v1/admin/external-libraries/search?q='.urlencode('تعليم المعلمين'))->assertOk()->json('data');
        $request = $this->sent('/articles/search')->first();
        $this->assertSame('POST', $request->method());
        $this->assertSame(['search_for' => 'تعليم المعلمين', 'institution' => 1009, 'page_size' => 20, 'order' => 'published_date', 'order_direction' => 'desc'], $request->data(), 'only QNL\'s own institution is asked, newest first');
        $this->assertFalse($request->hasHeader('Authorization'), 'public records need no key');
        $this->assertCount(1, Http::recorded(), 'one call, as Figshare asks (one a second at most)');

        $this->assertCount(3, $res);
        $this->assertSame(['qnl', '32132851', 'journal', 2026, 'https://manara.qnl.qa/articles/32132851', false, '10.1177/32132851'], [$res[0]['library'], $res[0]['external_id'], $res[0]['type'], $res[0]['year'], $res[0]['url'], $res[0]['deep_link'], $res[0]['doi']]);
        $this->assertSame(['book', 'link'], [$res[1]['type'], $res[2]['type']], 'what is not a book or an article is a link');
    }

    public function test_a_record_that_is_not_on_manara_is_never_listed(): void
    {
        Http::fake(['api.figshare.com/v2/articles/search' => Http::response([$this->listed(1, 'On Manara'), $this->listed(2, 'Somewhere else', 'journal contribution', 'https://evil.example/articles/2'), $this->listed(3, 'A look-alike', 'journal contribution', 'https://manara.qnl.qa.evil.example/3')])]);
        $this->configure();
        $res = $this->manager()->getJson('/api/v1/admin/external-libraries/search?q=leadership')->assertOk()->json('data');
        $this->assertSame(['On Manara'], array_column($res, 'title'));
    }

    public function test_the_catalogue_deep_link_comes_after_manara_when_one_is_set(): void
    {
        Http::fake(['api.figshare.com/*' => Http::response([$this->listed(1, 'On Manara')])]);
        $this->configure(['search_url' => 'https://catalogue.example.test/search?q={q}']);
        $res = $this->manager()->getJson('/api/v1/admin/external-libraries/search?q='.urlencode('curriculum design'))->assertOk()->json('data');
        $this->assertSame([false, true], array_column($res, 'deep_link'));
        $this->assertSame('https://catalogue.example.test/search?q=curriculum%20design', $res[1]['url']);
    }

    public function test_when_manara_cannot_answer_the_search_falls_back_to_the_deep_link_and_says_why(): void
    {
        Http::fake(['api.figshare.com/*' => Http::response(['message' => 'down'], 500)]);
        $this->configure(['search_url' => 'https://catalogue.example.test/search?q={q}']);
        $body = $this->manager()->getJson('/api/v1/admin/external-libraries/search?q=leadership')->assertOk()->json();
        $this->assertSame([true], array_column($body['data'], 'deep_link'));
        $this->assertSame('qnl', $body['meta']['problems'][0]['library']);
        $this->assertNotEmpty($body['meta']['problems'][0]['message']);

        $this->configure(['search_url' => '']);
        $none = $this->manager()->getJson('/api/v1/admin/external-libraries/search?q=leadership')->assertOk()->json();
        $this->assertSame([], $none['data']);
        $this->assertCount(1, $none['meta']['problems']);
    }

    public function test_a_search_too_short_for_figshare_is_not_sent_and_the_person_is_told(): void
    {
        Http::fake();
        $this->configure(['search_url' => 'https://catalogue.example.test/search?q={q}']);
        $body = $this->manager()->getJson('/api/v1/admin/external-libraries/search?q=ab')->assertOk()->json();
        Http::assertNothingSent();
        $this->assertSame([true], array_column($body['data'], 'deep_link'));
        $this->assertStringContainsString('3', $body['meta']['problems'][0]['message']);
    }

    public function test_without_an_institution_there_is_no_search_of_all_of_figshare(): void
    {
        Http::fake();
        $this->configure(['figshare_institution' => 0, 'search_url' => 'https://catalogue.example.test/search?q={q}']);
        $body = $this->manager()->getJson('/api/v1/admin/external-libraries/search?q=leadership')->assertOk()->json();
        Http::assertNothingSent();
        $this->assertSame([true], array_column($body['data'], 'deep_link'));
        $this->assertNotEmpty($body['meta']['problems']);
    }

    public function test_an_import_reads_the_whole_record_and_keeps_its_source_link_and_rights(): void
    {
        Http::fake(['api.figshare.com/v2/articles/32132851' => Http::response($this->record())]);
        $this->configure();
        // The client sends back what the search gave it; what it claims about rights is not what is kept.
        $sent = ['library' => 'qnl', 'external_id' => '32132851', 'title' => 'Whatever the browser says', 'authors' => [], 'year' => 1999, 'type' => 'book', 'url' => 'https://manara.qnl.qa/articles/32132851', 'rights' => ['licence' => 'public domain', 'download' => true]];
        $id = $this->manager()->postJson('/api/v1/admin/external-libraries/import', $sent)->assertCreated()->json('data.id');

        $item = LibraryItem::findOrFail($id);
        $this->assertSame(['qnl', '32132851', 'journal', 'published', self::PUBLIC_PAGE], [$item->source, $item->external_id, $item->type, $item->status, $item->url]);
        $this->assertSame('Teacher leadership in the Arab states: A systematic review', $item->title_en);
        $this->assertSame(['Norma Ghamrawi', 'Tarek Shal'], $item->authors);
        $this->assertSame(2026, $item->year);
        $this->assertStringContainsString('teacher leadership', (string) $item->description_en);
        $this->assertStringNotContainsString('<', (string) $item->description_en, 'markup is not kept');
        $this->assertContains('Education', $item->subjects);
        $this->assertContains('Teacher leadership', $item->subjects);
        $this->assertSame('CC BY 4.0', $item->rights['licence']);
        $this->assertSame('https://creativecommons.org/licenses/by/4.0/', $item->rights['licence_url']);
        $this->assertSame('10.1177/32132851', $item->rights['doi']);
        $this->assertSame(self::PUBLIC_PAGE, $item->rights['source_url']);
        $this->assertFalse($item->rights['download'], 'a link to Manara: the file stays there');
        $this->assertSame('Norma Ghamrawi, Tarek Shal', $item->rights['owner']);

        $this->manager()->postJson('/api/v1/admin/external-libraries/import', $sent)->assertCreated();
        $this->assertSame(1, LibraryItem::where('source', 'qnl')->count(), 'importing twice updates, not duplicates');
    }

    public function test_a_record_that_cannot_be_read_is_not_imported_without_its_rights(): void
    {
        Http::fake(['api.figshare.com/*' => Http::response(['message' => 'Not Found'], 404)]);
        $this->configure();
        $this->manager()->postJson('/api/v1/admin/external-libraries/import', ['library' => 'qnl', 'external_id' => '999', 'title' => 'x', 'url' => 'https://manara.qnl.qa/articles/999'])->assertStatus(422);
        $this->assertSame(0, LibraryItem::where('source', 'qnl')->count());
    }

    public function test_a_record_whose_page_is_not_on_manara_is_refused_at_import(): void
    {
        Http::fake(['api.figshare.com/*' => Http::response(array_merge($this->record(), ['url_public_html' => 'https://evil.example/x', 'figshare_url' => 'https://evil.example/x']))]);
        $this->configure();
        $this->manager()->postJson('/api/v1/admin/external-libraries/import', ['library' => 'qnl', 'external_id' => '32132851', 'title' => 'x', 'url' => 'https://manara.qnl.qa/articles/32132851'])->assertStatus(422);
        $this->assertSame(0, LibraryItem::where('source', 'qnl')->count());
    }

    public function test_only_the_library_that_has_a_documented_interface_may_use_it(): void
    {
        $this->manager()->putJson('/api/v1/admin/settings/external-libraries', ['maktabati' => ['enabled' => true, 'driver' => 'figshare']])->assertUnprocessable();
        $this->manager()->putJson('/api/v1/admin/settings/external-libraries', ['qnl' => ['driver' => 'figshare', 'figshare_institution' => 'abc']])->assertUnprocessable();
        $this->manager()->putJson('/api/v1/admin/settings/external-libraries', ['qnl' => ['driver' => 'figshare', 'portal_host' => 'https://evil.example/path']])->assertUnprocessable();
        $cfg = $this->manager()->putJson('/api/v1/admin/settings/external-libraries', ['qnl' => ['driver' => 'figshare', 'figshare_institution' => 1009, 'portal_host' => 'manara.qnl.qa']])->assertOk()->json('data');
        $this->assertSame([1009, 'manara.qnl.qa'], [$cfg['qnl']['figshare_institution'], $cfg['qnl']['portal_host']]);
        $this->assertSame([1009, 'manara.qnl.qa'], [app(ExternalLibraryService::class)->config()['qnl']['figshare_institution'], app(ExternalLibraryService::class)->config()['qnl']['portal_host']]);
    }

    public function test_the_deep_link_and_training_drivers_still_work_as_before(): void
    {
        Http::fake();
        $this->manager()->putJson('/api/v1/admin/settings/external-libraries', ['maktabati' => ['enabled' => true, 'driver' => 'fake'], 'qnl' => ['enabled' => true, 'driver' => 'link', 'search_url' => 'https://qnl.example/search?q={q}']])->assertOk();
        $res = $this->manager()->getJson('/api/v1/admin/external-libraries/search?q='.urlencode('تعليم'))->assertOk()->json('data');
        $this->assertSame(['maktabati', 'qnl'], array_column($res, 'library'));
        $this->assertSame([false, true], array_column($res, 'deep_link'));
        Http::assertNothingSent();
    }
}
