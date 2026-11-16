<?php

namespace App\Services\Cms;

use App\Exceptions\BusinessRuleException;
use App\Models\Announcement;
use App\Models\PageBlock;
use App\Models\PageVersion;
use App\Models\User;
use App\Services\Communication\AnnouncementLifecycle;
use App\Services\Communication\FeedBuilder;
use App\Services\FileStorage;
use Illuminate\Support\Facades\DB;

/**
 * Block-based pages (the homepage, About). The administrator edits a working copy, previews it, and publishes it as a numbered version;
 * visitors always see the last published version, filtered by each block's visibility, display window and audience. Any earlier version can be restored.
 */
class CmsService
{
    public const PAGES = ['home', 'about'];

    public const TYPES = ['hero_slider', 'stats', 'featured_programs', 'news', 'events', 'rich_text', 'cta', 'logos', 'faq', 'video', 'custom_html_safe'];

    public function __construct(private readonly PublicStatsService $stats, private readonly AnnouncementLifecycle $lifecycle, private readonly FeedBuilder $feeds) {}

    /** The working copy; a page nobody edited yet starts from the usual layout (not saved until the administrator saves). */
    public function draft(string $page): array
    {
        $rows = PageBlock::where('page', $page)->orderBy('sort_order')->get();
        if ($rows->isEmpty()) {
            return $this->defaults($page);
        }

        return $rows->map(fn (PageBlock $b) => $this->row($b))->all();
    }

    /** @param  list<array<string, mixed>>  $blocks in display order */
    public function saveDraft(string $page, array $blocks, ?User $by): array
    {
        return DB::transaction(function () use ($page, $blocks, $by) {
            $keep = [];
            foreach (array_values($blocks) as $i => $b) {
                $type = (string) ($b['type'] ?? '');
                if (! in_array($type, self::TYPES, true)) {
                    throw new BusinessRuleException("Unknown block type {$type}.", 'invalid_block');
                }
                $attrs = [
                    'page' => $page, 'type' => $type, 'config' => $this->cleanConfig($type, (array) ($b['config'] ?? [])), 'sort_order' => $i,
                    'is_visible' => (bool) ($b['is_visible'] ?? true), 'audience' => ($b['audience'] ?? 'public') === 'signed_in' ? 'signed_in' : 'public',
                    'starts_at' => $b['starts_at'] ?? null, 'ends_at' => $b['ends_at'] ?? null, 'updated_by' => $by?->id,
                ];
                $row = ! empty($b['id']) ? PageBlock::where('page', $page)->whereKey($b['id'])->first() : null;
                $row ? $row->update($attrs) : ($row = PageBlock::create($attrs));
                $keep[] = $row->id;
            }
            PageBlock::where('page', $page)->whereNotIn('id', $keep)->delete();

            return $this->draft($page);
        });
    }

    public function publish(string $page, ?string $note, ?User $by): PageVersion
    {
        $blocks = $this->draft($page);
        $version = PageVersion::create([
            'page' => $page, 'version' => (int) PageVersion::where('page', $page)->max('version') + 1, 'blocks' => $blocks, 'note' => $note, 'published_by' => $by?->id,
        ]);
        // A page published from the built-in layout now has a saved working copy too.
        if (! PageBlock::where('page', $page)->exists()) {
            $this->saveDraft($page, $blocks, $by);
        }

        return $version;
    }

    /** Puts an earlier version back as the working copy and publishes it again as a new version. */
    public function rollback(string $page, int $version, ?User $by): PageVersion
    {
        $old = PageVersion::where(['page' => $page, 'version' => $version])->firstOrFail();
        $this->saveDraft($page, $old->blocks, $by);

        return $this->publish($page, "Restored version {$version}", $by);
    }

    /** @return array{published: bool, version: ?int, blocks: list<array<string, mixed>>} */
    public function publicPage(string $page, ?User $viewer = null): array
    {
        $v = PageVersion::where('page', $page)->orderByDesc('version')->first();
        if (! $v) {
            return ['published' => false, 'version' => null, 'blocks' => []];
        }
        $now = now();
        $blocks = collect($v->blocks)
            ->filter(fn ($b) => ($b['is_visible'] ?? true)
                && (($b['audience'] ?? 'public') === 'public' || $viewer !== null)
                && (empty($b['starts_at']) || $now->gte($b['starts_at']))
                && (empty($b['ends_at']) || $now->lt($b['ends_at'])))
            ->map(fn ($b) => $this->resolve($b))->values()->all();

        return ['published' => true, 'version' => $v->version, 'blocks' => $blocks];
    }

    /** The working copy as visitors would see it once published (live data included, windows and audience ignored so the editor can check every block). */
    public function previewDraft(string $page): array
    {
        return ['published' => false, 'version' => null, 'blocks' => array_map(fn ($b) => $this->resolve($b), $this->draft($page))];
    }

    /** Blocks that show live data carry it with them. */
    private function resolve(array $b): array
    {
        $limit = max(1, min(12, (int) ($b['config']['limit'] ?? 3)));
        $b['data'] = match ($b['type']) {
            'stats' => $this->stats->visible(),
            'news' => $this->lifecycle->live(Announcement::query())->where('is_public', true)->whereIn('type', ['news', 'announcement', 'circular'])
                ->orderByDesc('is_pinned')->orderBy('pin_order')->orderByDesc('published_at')->limit($limit)->get()->map(fn ($a) => $this->card($a))->all(),
            'events' => $this->lifecycle->live(Announcement::query())->where('is_public', true)->whereIn('type', ['event', 'activity'])
                ->orderByDesc('is_pinned')->orderByDesc('published_at')->limit($limit)->get()->map(fn ($a) => $this->card($a))->all(),
            default => null,
        };
        unset($b['id']);

        return $b;
    }

    private function card(Announcement $a): array
    {
        return [
            'id' => $a->id, 'type' => $a->type, 'title' => ['ar' => $a->title_ar, 'en' => $a->title_en],
            'excerpt' => ['ar' => mb_substr(strip_tags((string) $a->body_ar), 0, 160), 'en' => mb_substr(strip_tags((string) $a->body_en), 0, 160)],
            'cover_url' => FileStorage::publicUrl($a->cover_path), 'published_at' => $a->published_at?->toIso8601String(), 'event' => $a->event ? array_intersect_key($a->event, array_flip(['starts_at', 'ends_at', 'venue_ar', 'venue_en', 'online_url'])) : null,
        ];
    }

    private function row(PageBlock $b): array
    {
        return ['id' => $b->id, 'type' => $b->type, 'config' => $b->config ?? [], 'sort_order' => $b->sort_order, 'is_visible' => $b->is_visible, 'audience' => $b->audience,
            'starts_at' => $b->starts_at?->toIso8601String(), 'ends_at' => $b->ends_at?->toIso8601String()];
    }

    /** Strings stay strings, HTML is cleaned, links must be web addresses. */
    private function cleanConfig(string $type, array $config): array
    {
        $clean = function ($v, string $key = '') use (&$clean) {
            if (is_array($v)) {
                $out = [];
                foreach ($v as $k => $x) {
                    $out[$k] = $clean($x, is_int($k) || in_array($k, ['ar', 'en'], true) ? $key : (string) $k);   // {ar, en} and list items keep the field name above them
                }

                return $out;
            }
            if (! is_string($v)) {
                return $v;
            }
            if (in_array($key, ['html', 'body', 'answer'], true)) {
                return HtmlSanitizer::clean($v);
            }
            if (in_array($key, ['url', 'href', 'link', 'image', 'video_url', 'cta_url'], true) && $v !== '' && ! preg_match('#^(https?://|/|\#|mailto:)#i', $v)) {
                return '';
            }

            return mb_substr(strip_tags($v), 0, 2000);
        };
        $out = $clean($config);
        if (strlen((string) json_encode($out)) > 60000) {
            throw new BusinessRuleException('This block is too large.', 'block_too_large');
        }

        return $out;
    }

    /** @return list<array<string, mixed>> */
    private function defaults(string $page): array
    {
        $t = fn (string $ar, string $en) => ['ar' => $ar, 'en' => $en];
        if ($page === 'about') {
            return [['id' => null, 'type' => 'rich_text', 'config' => ['title' => $t('عن المركز', 'About the centre'), 'body' => $t('<p></p>', '<p></p>')], 'sort_order' => 0, 'is_visible' => true, 'audience' => 'public', 'starts_at' => null, 'ends_at' => null]];
        }
        $block = fn (string $type, array $config, int $i) => ['id' => null, 'type' => $type, 'config' => $config, 'sort_order' => $i, 'is_visible' => true, 'audience' => 'public', 'starts_at' => null, 'ends_at' => null];

        return [
            $block('hero_slider', ['slides' => []], 0),
            $block('stats', ['title' => $t('المركز بالأرقام', 'The centre in numbers')], 1),
            $block('featured_programs', ['title' => $t('برامج مميزة', 'Featured programs'), 'limit' => 6], 2),
            $block('news', ['title' => $t('آخر الأخبار', 'Latest news'), 'limit' => 3], 3),
            $block('events', ['title' => $t('فعاليات قادمة', 'Upcoming events'), 'limit' => 3], 4),
        ];
    }
}
