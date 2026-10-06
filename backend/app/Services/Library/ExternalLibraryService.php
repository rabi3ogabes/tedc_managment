<?php

namespace App\Services\Library;

use App\Models\LibraryItem;
use App\Models\SiteSetting;
use App\Models\User;
use Illuminate\Support\Facades\Http;

/**
 * Federated search over the Ministry library (Maktabati) and the Qatar National Library. Each is configured with a search URL;
 * when it also has a JSON API it is queried live, otherwise the result is a deep link to the library's own search page.
 * A `fake` driver (settings: driver = fake) gives deterministic results for development and tests.
 */
class ExternalLibraryService
{
    public const LIBRARIES = ['maktabati', 'qnl'];

    /** @return array<string, array<string, mixed>> */
    public function config(): array
    {
        $saved = SiteSetting::find('external_libraries')?->value ?? [];

        return array_replace_recursive(['maktabati' => ['enabled' => false, 'driver' => 'link', 'name_ar' => 'مكتبتي', 'name_en' => 'Maktabati', 'search_url' => '', 'api_url' => '', 'api_key' => ''], 'qnl' => ['enabled' => false, 'driver' => 'link', 'name_ar' => 'مكتبة قطر الوطنية', 'name_en' => 'Qatar National Library', 'search_url' => '', 'api_url' => '', 'api_key' => '']], $saved);
    }

    public function update(array $in, ?User $by = null): array
    {
        $cur = $this->config();
        foreach (self::LIBRARIES as $k) {
            foreach (['enabled', 'driver', 'search_url', 'api_url', 'api_key'] as $f) {
                if (isset($in[$k]) && array_key_exists($f, $in[$k]) && $in[$k][$f] !== '••••••••') {
                    $cur[$k][$f] = $f === 'enabled' ? (bool) $in[$k][$f] : (string) $in[$k][$f];
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
        $out = [];
        foreach ($this->config() as $lib => $c) {
            if (! $c['enabled']) {
                continue;
            }
            if ($c['driver'] === 'fake') {
                $out[] = ['library' => $lib, 'external_id' => $lib.'-1', 'title' => "{$q} — {$c['name_en']} (sample)", 'authors' => ['Sample Author'], 'year' => 2024, 'type' => 'book', 'url' => 'https://example.org/'.$lib.'/1', 'deep_link' => false];

                continue;
            }
            if ($c['api_url'] !== '') {
                try {
                    $res = Http::timeout(8)->acceptJson()->withHeaders($c['api_key'] !== '' ? ['Authorization' => 'Bearer '.$c['api_key']] : [])->get($c['api_url'], ['q' => $q])->throw()->json();
                    foreach (array_slice($res['results'] ?? $res['items'] ?? [], 0, 20) as $r) {
                        $out[] = ['library' => $lib, 'external_id' => (string) ($r['id'] ?? md5(json_encode($r))), 'title' => (string) ($r['title'] ?? ''), 'authors' => (array) ($r['authors'] ?? []), 'year' => $r['year'] ?? null, 'type' => $r['type'] ?? 'book', 'url' => (string) ($r['url'] ?? $c['search_url']), 'deep_link' => false];
                    }

                    continue;
                } catch (\Throwable) {
                    // fall through to the deep link
                }
            }
            if ($c['search_url'] !== '') {
                $out[] = ['library' => $lib, 'external_id' => null, 'title' => ($c['name_en']).': '.$q, 'authors' => [], 'year' => null, 'type' => 'link', 'url' => str_replace('{q}', rawurlencode($q), $c['search_url']), 'deep_link' => true];
            }
        }

        return $out;
    }

    /** Imports a catalogue record as a link item. */
    public function import(array $r, User $by): LibraryItem
    {
        abort_unless(in_array($r['library'] ?? '', self::LIBRARIES, true), 422);
        $library = app(LibraryService::class);
        $existing = ! empty($r['external_id']) ? LibraryItem::where('source', $r['library'])->where('external_id', $r['external_id'])->first() : null;

        return $library->save(['type' => in_array($r['type'] ?? '', ['book', 'journal', 'periodical', 'audio', 'video', 'elearning'], true) ? $r['type'] : 'link', 'title_ar' => $r['title'], 'title_en' => $r['title'], 'authors' => $r['authors'] ?? [], 'year' => $r['year'] ?? null, 'url' => $r['url'], 'source' => $r['library'], 'external_id' => $r['external_id'] ?? null, 'status' => 'published'], $by, $existing);
    }
}
