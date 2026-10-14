<?php

namespace App\Services\Library;

use App\Exceptions\BusinessRuleException;
use App\Models\LibraryItem;
use App\Models\SiteSetting;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Federated search over the Ministry library (Maktabati) and the Qatar National Library. A library that documents a public interface is
 * asked through it: Qatar National Library's Manara research repository answers through the public Figshare API (the `figshare` driver).
 * One that documents none is a deep link to the library's own search page (a search URL), or, when it was given one, a JSON API it publishes.
 * A `fake` driver gives deterministic results for development and tests. Imported items keep their source link and rights.
 */
class ExternalLibraryService
{
    public const LIBRARIES = ['maktabati', 'qnl'];

    /** The documented public interface of Figshare, which hosts QNL's Manara repository: https://docs.figshare.com/ */
    private const FIGSHARE = 'https://api.figshare.com/v2';

    /** How Figshare's article types become library item types; everything else is a link. */
    private const TYPES = ['journal contribution' => 'journal', 'book' => 'book', 'monograph' => 'book', 'chapter' => 'book'];

    /** @return array<string, array<string, mixed>> */
    public function config(): array
    {
        $saved = SiteSetting::find('external_libraries')?->value ?? [];

        return array_replace_recursive([
            'maktabati' => ['enabled' => false, 'driver' => 'link', 'name_ar' => 'مكتبتي', 'name_en' => 'Maktabati', 'search_url' => '', 'api_url' => '', 'api_key' => ''],
            // 1009 is Manara's institution number on Figshare, as its own portal page (manara.qnl.qa) states; the centre confirms it with QNL.
            'qnl' => ['enabled' => false, 'driver' => 'link', 'name_ar' => 'مكتبة قطر الوطنية', 'name_en' => 'Qatar National Library', 'search_url' => '', 'api_url' => '', 'api_key' => '', 'figshare_institution' => 1009, 'portal_host' => 'manara.qnl.qa'],
        ], $saved);
    }

    public function update(array $in, ?User $by = null): array
    {
        $cur = $this->config();
        foreach (self::LIBRARIES as $k) {
            foreach (['enabled', 'driver', 'search_url', 'api_url', 'api_key', 'figshare_institution', 'portal_host'] as $f) {
                if (isset($in[$k]) && array_key_exists($f, $in[$k]) && $in[$k][$f] !== '••••••••') {
                    $cur[$k][$f] = match ($f) {
                        'enabled' => (bool) $in[$k][$f],
                        'figshare_institution' => (int) $in[$k][$f],
                        'portal_host' => strtolower((string) $in[$k][$f]),
                        default => (string) $in[$k][$f],
                    };
                }
            }
        }
        SiteSetting::updateOrCreate(['key' => 'external_libraries'], ['value' => $cur, 'updated_by' => $by?->id]);

        return $this->masked();
    }

    public function masked(): array
    {
        $c = $this->config();
        foreach ($c as $k => $v) {
            $c[$k]['api_key'] = $v['api_key'] !== '' ? '••••••••' : '';
        }

        return $c;
    }

    /** @return list<array<string, mixed>> records: library, external_id, title, authors, year, type, url, deep_link */
    public function search(string $q): array
    {
        return $this->lookup($q)['results'];
    }

    /**
     * What every library that is switched on has for a search, and what went wrong with the ones that could not answer (a library that
     * cannot answer through its interface still gives its deep link, when it has one).
     *
     * @return array{results: list<array<string, mixed>>, problems: list<array{library: string, message: string}>}
     */
    public function lookup(string $q): array
    {
        $out = [];
        $problems = [];
        foreach ($this->config() as $lib => $c) {
            if (! $c['enabled']) {
                continue;
            }
            if ($c['driver'] === 'fake') {
                $out[] = ['library' => $lib, 'external_id' => $lib.'-1', 'title' => "{$q} — {$c['name_en']} (sample)", 'authors' => ['Sample Author'], 'year' => 2024, 'type' => 'book', 'url' => 'https://example.org/'.$lib.'/1', 'deep_link' => false];

                continue;
            }
            if ($c['driver'] === 'figshare' && $lib === 'qnl') {
                try {
                    array_push($out, ...$this->manara($c, $q));
                } catch (Throwable $e) {
                    $problems[] = ['library' => $lib, 'message' => $e instanceof RuntimeException ? $e->getMessage() : __('messages.library.unavailable', ['library' => $c['name_en']])];
                }
                if ($c['search_url'] !== '') {   // Manara holds research outputs; the catalogue of books is the library's own page
                    $out[] = $this->deepLink($lib, $c, $q);
                }

                continue;
            }
            if ($c['api_url'] !== '') {
                try {
                    $res = Http::timeout(8)->acceptJson()->withHeaders($c['api_key'] !== '' ? ['Authorization' => 'Bearer '.$c['api_key']] : [])->get($c['api_url'], ['q' => $q])->throw()->json();
                    foreach (array_slice($res['results'] ?? $res['items'] ?? [], 0, 20) as $r) {
                        $out[] = ['library' => $lib, 'external_id' => (string) ($r['id'] ?? md5(json_encode($r))), 'title' => (string) ($r['title'] ?? ''), 'authors' => (array) ($r['authors'] ?? []), 'year' => $r['year'] ?? null, 'type' => $r['type'] ?? 'book', 'url' => (string) ($r['url'] ?? $c['search_url']), 'deep_link' => false];
                    }

                    continue;
                } catch (Throwable) {
                    // fall through to the deep link
                }
            }
            if ($c['search_url'] !== '') {
                $out[] = $this->deepLink($lib, $c, $q);
            }
        }

        return ['results' => $out, 'problems' => $problems];
    }

    /** @param  array<string, mixed>  $c @return array<string, mixed> */
    private function deepLink(string $lib, array $c, string $q): array
    {
        return ['library' => $lib, 'external_id' => null, 'title' => ($c['name_en']).': '.$q, 'authors' => [], 'year' => null, 'type' => 'link', 'url' => str_replace('{q}', rawurlencode($q), $c['search_url']), 'deep_link' => true];
    }

    /**
     * Manara through the public Figshare API, for QNL's own institution only: one call, newest first. A record whose page is not on Manara's
     * own host is never listed.
     *
     * @param  array<string, mixed>  $c
     * @return list<array<string, mixed>>
     *
     * @throws RuntimeException with the reason, in the person's language
     */
    private function manara(array $c, string $q): array
    {
        if ((int) ($c['figshare_institution'] ?? 0) < 1) {
            throw new RuntimeException(__('messages.library.no_institution'));
        }
        if (mb_strlen($q) < 3) {
            throw new RuntimeException(__('messages.library.too_short'));
        }
        $r = Http::timeout(10)->acceptJson()->asJson()->post(self::FIGSHARE.'/articles/search', ['search_for' => $q, 'institution' => (int) $c['figshare_institution'], 'page_size' => 20, 'order' => 'published_date', 'order_direction' => 'desc']);
        if (! $r->successful() || ! is_array($r->json())) {
            throw new RuntimeException(__('messages.library.unavailable', ['library' => $c['name_en']]));
        }
        $rows = [];
        foreach ($r->json() as $a) {
            if (! is_array($a) || ! $this->onPortal((string) ($a['url_public_html'] ?? ''), $c)) {
                continue;
            }
            $rows[] = ['library' => 'qnl', 'external_id' => (string) $a['id'], 'title' => (string) ($a['title'] ?? ''), 'authors' => [], 'year' => $this->year($a['published_date'] ?? null), 'type' => self::TYPES[strtolower((string) ($a['defined_type_name'] ?? ''))] ?? 'link',
                'url' => (string) $a['url_public_html'], 'deep_link' => false, 'doi' => filled($a['doi'] ?? null) ? (string) $a['doi'] : null];
        }

        return $rows;
    }

    /** Whether an address is a page on the library's own portal (https, and exactly that host). @param  array<string, mixed>  $c */
    private function onPortal(string $url, array $c): bool
    {
        $parts = parse_url($url);

        return ($parts['scheme'] ?? '') === 'https' && strtolower((string) ($parts['host'] ?? '')) === strtolower((string) ($c['portal_host'] ?? 'manara.qnl.qa'));
    }

    private function year(mixed $date): ?int
    {
        return is_string($date) && preg_match('/^(\d{4})-/', $date, $m) ? (int) $m[1] : null;
    }

    /** Imports a catalogue record as a link item. */
    public function import(array $r, User $by): LibraryItem
    {
        abort_unless(in_array($r['library'] ?? '', self::LIBRARIES, true), 422);
        $c = $this->config()[$r['library']];
        if ($r['library'] === 'qnl' && $c['driver'] === 'figshare' && ctype_digit((string) ($r['external_id'] ?? ''))) {
            return $this->importFromManara((string) $r['external_id'], $c, $by);
        }
        $library = app(LibraryService::class);
        $existing = ! empty($r['external_id']) ? LibraryItem::where('source', $r['library'])->where('external_id', $r['external_id'])->first() : null;

        return $library->save(['type' => in_array($r['type'] ?? '', ['book', 'journal', 'periodical', 'audio', 'video', 'elearning'], true) ? $r['type'] : 'link', 'title_ar' => $r['title'], 'title_en' => $r['title'], 'authors' => $r['authors'] ?? [], 'year' => $r['year'] ?? null, 'url' => $r['url'], 'source' => $r['library'], 'external_id' => $r['external_id'] ?? null, 'status' => 'published'], $by, $existing);
    }

    /**
     * A record of Manara, read whole from the public Figshare API (what the browser sent back is not trusted): its page stays the link, and
     * its licence, authors and DOI are kept as the item's rights. The file stays on Manara, so the item is a link and cannot be downloaded.
     *
     * @param  array<string, mixed>  $c
     */
    private function importFromManara(string $id, array $c, User $by): LibraryItem
    {
        try {
            $res = Http::timeout(10)->acceptJson()->get(self::FIGSHARE.'/articles/'.$id);
        } catch (Throwable) {
            $res = null;
        }
        $a = $res?->successful() ? $res->json() : null;
        if (! is_array($a) || (string) ($a['id'] ?? '') !== $id) {
            throw new BusinessRuleException(__('messages.library.record_unavailable'), 'record_unavailable');
        }
        $page = (string) ($a['url_public_html'] ?? '');
        if (! $this->onPortal($page, $c) || ($a['is_public'] ?? true) !== true) {
            throw new BusinessRuleException(__('messages.library.foreign_record'), 'foreign_record');
        }
        $authors = array_values(array_filter(array_map(fn ($x) => is_array($x) ? trim((string) ($x['full_name'] ?? '')) : '', (array) ($a['authors'] ?? []))));
        $licence = (array) ($a['license'] ?? []);
        $title = trim((string) ($a['title'] ?? ''));
        $about = trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags((string) preg_replace('/<\/(p|div|li|h[1-6])>/i', ' ', (string) ($a['description'] ?? ''))), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
        $subjects = array_values(array_unique(array_filter(array_merge(
            array_map(fn ($x) => is_array($x) ? trim((string) ($x['title'] ?? '')) : '', (array) ($a['categories'] ?? [])),
            array_map(fn ($x) => is_string($x) ? trim($x) : '', array_slice((array) ($a['tags'] ?? []), 0, 12)),
        ))));
        $existing = LibraryItem::where('source', 'qnl')->where('external_id', $id)->first();

        return app(LibraryService::class)->save([
            'type' => self::TYPES[strtolower((string) ($a['defined_type_name'] ?? ''))] ?? 'link', 'title_ar' => mb_substr($title, 0, 255), 'title_en' => mb_substr($title, 0, 255), 'description_ar' => $about !== '' ? $about : null, 'description_en' => $about !== '' ? $about : null,
            'authors' => $authors, 'year' => $this->year($a['published_date'] ?? null), 'subjects' => $subjects, 'url' => $page, 'source' => 'qnl', 'external_id' => $id, 'status' => 'published',
            'rights' => array_filter([
                'owner' => implode(', ', $authors), 'licence' => $licence['name'] ?? null, 'licence_url' => $licence['url'] ?? null, 'doi' => filled($a['doi'] ?? null) ? (string) $a['doi'] : null, 'source_url' => $page,
                'citation' => filled($a['citation'] ?? null) ? mb_substr((string) $a['citation'], 0, 600) : null, 'download' => false, 'print' => false,
            ], fn ($v) => $v !== null && $v !== ''),
        ], $by, $existing);
    }
}
