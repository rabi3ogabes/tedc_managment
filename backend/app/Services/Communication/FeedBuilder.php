<?php

namespace App\Services\Communication;

use App\Models\Announcement;
use App\Services\FileStorage;
use Illuminate\Support\Collection;

/**
 * News and events as the Ministry website consumes them: JSON, RSS 2.0, Atom and CSV. Only items flagged for export and currently live
 * appear; both languages are always present, images and media have absolute addresses.
 */
class FeedBuilder
{
    /** @return Collection<int, Announcement> */
    public function items(string $kind): Collection
    {
        $types = $kind === 'events' ? ['event', 'activity'] : ['news', 'announcement', 'circular'];

        return app(AnnouncementLifecycle::class)->live(Announcement::query())->where('export_to_ministry', true)->where('is_public', true)->whereIn('type', $types)
            ->orderByDesc('published_at')->limit(100)->get();
    }

    /** One item, the same shape in every format. @return array<string, mixed> */
    public function item(Announcement $a): array
    {
        $media = $a->media ?? [];
        $abs = fn (?string $p) => $this->absolute(FileStorage::publicUrl($p));

        return [
            'id' => $a->id,
            'type' => $a->type,
            'url' => rtrim((string) config('tedc.web_url'), '/').'/'.(in_array($a->type, ['event', 'activity'], true) ? 'events' : 'news').'/'.$a->id,
            'title' => ['ar' => $a->title_ar, 'en' => $a->title_en],
            'summary' => ['ar' => mb_substr(strip_tags((string) $a->body_ar), 0, 240), 'en' => mb_substr(strip_tags((string) $a->body_en), 0, 240)],
            'body' => ['ar' => (string) $a->body_ar, 'en' => (string) $a->body_en],
            'published_at' => $a->published_at?->toIso8601String(),
            'updated_at' => $a->updated_at?->toIso8601String(),
            'image' => $abs($a->cover_path),
            'images' => collect($media['images'] ?? [])->map(fn ($m) => $abs($m['path'] ?? null) ?? $this->absolute($m['url'] ?? null))->filter()->values()->all(),
            'video' => collect($media['video'] ?? [])->map(fn ($m) => $this->absolute($m['url'] ?? null) ?? $abs($m['path'] ?? null))->filter()->values()->all(),
            'audio' => collect($media['audio'] ?? [])->map(fn ($m) => $this->absolute($m['url'] ?? null) ?? $abs($m['path'] ?? null))->filter()->values()->all(),
            'event' => $a->event ? [
                'starts_at' => $a->event['starts_at'] ?? null, 'ends_at' => $a->event['ends_at'] ?? null,
                'venue' => ['ar' => $a->event['venue_ar'] ?? null, 'en' => $a->event['venue_en'] ?? null],
                'online_url' => $a->event['online_url'] ?? null, 'registration_url' => $a->event['registration_url'] ?? null,
            ] : null,
        ];
    }

    public function json(string $kind): array
    {
        return ['generated_at' => now()->toIso8601String(), 'kind' => $kind, 'items' => $this->items($kind)->map(fn ($a) => $this->item($a))->all()];
    }

    public function rss(string $kind, string $lang): string
    {
        $items = $this->items($kind)->map(fn ($a) => $this->item($a));
        $site = rtrim((string) config('tedc.web_url'), '/');
        $x = '<?xml version="1.0" encoding="UTF-8"?>'."\n".'<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom" xmlns:media="http://search.yahoo.com/mrss/"><channel>';
        $x .= '<title>'.$this->e($this->channelTitle($kind, $lang)).'</title><link>'.$this->e($site).'</link><description>'.$this->e($this->channelTitle($kind, $lang)).'</description><language>'.$lang.'</language>';
        $x .= '<atom:link href="'.$this->e($site).'" rel="self" type="application/rss+xml"/>';
        foreach ($items as $i) {
            $x .= '<item><title>'.$this->e($i['title'][$lang]).'</title><link>'.$this->e($i['url']).'</link><guid isPermaLink="false">'.$i['id'].'</guid>';
            $x .= '<pubDate>'.($i['published_at'] ? date(DATE_RSS, strtotime($i['published_at'])) : '').'</pubDate><description>'.$this->e($i['summary'][$lang]).'</description>';
            if ($i['image']) {
                $x .= '<enclosure url="'.$this->e($i['image']).'" type="image/jpeg" length="0"/><media:content url="'.$this->e($i['image']).'" medium="image"/>';
            }
            $x .= '</item>';
        }

        return $x.'</channel></rss>';
    }

    public function atom(string $kind, string $lang): string
    {
        $items = $this->items($kind)->map(fn ($a) => $this->item($a));
        $site = rtrim((string) config('tedc.web_url'), '/');
        $x = '<?xml version="1.0" encoding="UTF-8"?>'."\n".'<feed xmlns="http://www.w3.org/2005/Atom" xml:lang="'.$lang.'">';
        $x .= '<title>'.$this->e($this->channelTitle($kind, $lang)).'</title><id>'.$this->e($site.'/feeds/'.$kind).'</id><updated>'.now()->toIso8601String().'</updated><link href="'.$this->e($site).'"/>';
        foreach ($items as $i) {
            $x .= '<entry><title>'.$this->e($i['title'][$lang]).'</title><id>urn:uuid:'.$i['id'].'</id><link href="'.$this->e($i['url']).'"/>';
            $x .= '<updated>'.($i['updated_at'] ?? now()->toIso8601String()).'</updated><published>'.($i['published_at'] ?? now()->toIso8601String()).'</published><summary>'.$this->e($i['summary'][$lang]).'</summary>';
            if ($i['image']) {
                $x .= '<link rel="enclosure" href="'.$this->e($i['image']).'"/>';
            }
            $x .= '</entry>';
        }

        return $x.'</feed>';
    }

    public function csv(string $kind): string
    {
        $out = fopen('php://temp', 'r+');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, ['id', 'type', 'title_ar', 'title_en', 'summary_ar', 'summary_en', 'published_at', 'url', 'image', 'event_starts_at', 'event_venue_ar', 'event_venue_en']);
        foreach ($this->items($kind) as $a) {
            $i = $this->item($a);
            fputcsv($out, [$i['id'], $i['type'], $i['title']['ar'], $i['title']['en'], $i['summary']['ar'], $i['summary']['en'], $i['published_at'], $i['url'], $i['image'], $i['event']['starts_at'] ?? '', $i['event']['venue']['ar'] ?? '', $i['event']['venue']['en'] ?? '']);
        }
        rewind($out);

        return (string) stream_get_contents($out);
    }

    private function channelTitle(string $kind, string $lang): string
    {
        return ['ar' => ['news' => 'أخبار مركز التدريب', 'events' => 'فعاليات مركز التدريب'], 'en' => ['news' => 'Training centre news', 'events' => 'Training centre events']][$lang][$kind];
    }

    private function absolute(?string $url): ?string
    {
        if (! $url) {
            return null;
        }

        return str_starts_with($url, '/') ? rtrim((string) config('app.url'), '/').$url : $url;
    }

    private function e(?string $s): string
    {
        return htmlspecialchars((string) $s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
