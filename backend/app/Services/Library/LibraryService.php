<?php

namespace App\Services\Library;

use App\Exceptions\BusinessRuleException;
use App\Models\Employee;
use App\Models\LibraryItem;
use App\Models\LibraryReview;
use App\Models\LibraryShelf;
use App\Models\User;
use App\Services\Assessment\ArabicText;
use App\Services\Eligibility\EligibilityEngine;
use App\Services\Eligibility\EmployeeContext;
use App\Services\FileStorage;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** The digital library: rights and embargoes, audience rules, Arabic-friendly search, the reader and downloads per rights, shelves and reviews. */
class LibraryService
{
    public function __construct(private readonly EligibilityEngine $rules, private readonly FileStorage $storage) {}

    /** Normalised text the search runs on: titles, authors, subjects, publisher and ISBN with Arabic letter forms folded. */
    public function searchText(array $d): string
    {
        $parts = [$d['title_ar'] ?? '', $d['title_en'] ?? '', $d['description_ar'] ?? '', $d['description_en'] ?? '', implode(' ', $d['authors'] ?? []), implode(' ', $d['subjects'] ?? []), $d['publisher'] ?? '', $d['isbn'] ?? ''];

        return ArabicText::normalize(implode(' ', $parts));
    }

    public function save(array $d, User $by, ?LibraryItem $item = null): LibraryItem
    {
        $d['search_text'] = $this->searchText($d + ($item?->toArray() ?? []));

        return $item ? tap($item)->update($d) : LibraryItem::create($d + ['created_by' => $by->id, 'status' => $d['status'] ?? 'draft']);
    }

    /** Does the audience rule list let this employee see the item? An empty list is open to everyone. */
    public function audienceAllows(LibraryItem $item, ?Employee $employee): bool
    {
        $rules = $item->audience ?? [];
        if ($rules === []) {
            return true;
        }
        if (! $employee) {
            return false;
        }
        $ctx = EmployeeContext::fromEmployee($employee);
        foreach ($rules as $r) {
            if (($r['is_mandatory'] ?? true) && ! $this->rules->passes($r['field'], $r['operator'], $r['value'] ?? null, $ctx)) {
                return false;
            }
        }

        return true;
    }

    /** Published, inside its embargo window, and open to the viewer. Library managers see everything. */
    public function canView(LibraryItem $item, User $user): bool
    {
        if ($user->hasPermission('library.manage')) {
            return true;
        }
        $r = $item->rights ?? [];
        if ($item->status !== 'published' || (! empty($r['embargo_from']) && now()->lt($r['embargo_from'])) || (! empty($r['embargo_until']) && now()->gt($r['embargo_until']))) {
            return false;
        }

        return $this->audienceAllows($item, $user->employee);
    }

    /** @return Collection<int, LibraryItem> */
    public function search(User $user, array $q): Collection
    {
        $term = ArabicText::normalize((string) ($q['q'] ?? ''));
        $query = LibraryItem::query()->when(! $user->hasPermission('library.manage'), fn ($b) => $b->where('status', 'published'))->when($q['status'] ?? null, fn ($b, $s) => $b->where('status', $s))->when($q['type'] ?? null, fn ($b, $t) => $b->where('type', $t))
            ->when($q['language'] ?? null, fn ($b, $l) => $b->where('language', $l))->when($q['year'] ?? null, fn ($b, $y) => $b->where('year', $y))->when($q['source'] ?? null, fn ($b, $s) => $b->where('source', $s))
            ->when($q['collection'] ?? null, fn ($b, $c) => $b->whereIn('id', DB::table('library_collection_items')->where('collection_id', $c)->pluck('item_id')))
            ->when($q['subject'] ?? null, fn ($b, $s) => $b->whereLike('search_text', '%'.ArabicText::normalize($s).'%'));
        foreach (array_filter(preg_split('/\s+/u', $term) ?: []) as $word) {
            $query->whereLike('search_text', "%{$word}%");
        }
        $order = $q['sort'] ?? 'recent';
        $query->when($order === 'popular', fn ($b) => $b->orderByDesc('views'))->when($order === 'title', fn ($b) => $b->orderBy('title_ar'))->when($order === 'recent', fn ($b) => $b->latest());

        return $query->limit(300)->get()->filter(fn ($i) => $this->canView($i, $user))->values();
    }

    /** @return array<string, mixed> */
    public function present(LibraryItem $i, User $user): array
    {
        $r = $i->rights ?? [];
        $stars = LibraryReview::where('item_id', $i->id);

        return ['id' => $i->id, 'type' => $i->type, 'title_ar' => $i->title_ar, 'title_en' => $i->title_en, 'description_ar' => $i->description_ar, 'description_en' => $i->description_en, 'authors' => $i->authors ?? [], 'publisher' => $i->publisher, 'isbn' => $i->isbn, 'year' => $i->year, 'language' => $i->language, 'subjects' => $i->subjects ?? [],
            'cover_url' => $i->cover_path ? $this->storage->publicUrl($i->cover_path) : null, 'source' => $i->source, 'url' => $i->source !== 'local' || ! $i->file_path ? $i->url : null, 'has_file' => (bool) $i->file_path, 'status' => $i->status, 'views' => $i->views, 'downloads' => $i->downloads,
            'rating' => round((float) ($stars->avg('stars') ?? 0), 1), 'reviews' => $stars->count(), 'rights' => ['owner' => $r['owner'] ?? null, 'licence' => $r['licence'] ?? null, 'download' => (bool) ($r['download'] ?? true), 'print' => (bool) ($r['print'] ?? true), 'watermark' => (bool) ($r['watermark'] ?? false)],
            'on_shelf' => LibraryShelf::where('item_id', $i->id)->where('user_id', $user->id)->exists()];
    }

    /** The reader: a short-lived link, the watermark text and what the rights allow. @return array<string, mixed> */
    public function open(LibraryItem $i, User $user): array
    {
        abort_unless($this->canView($i, $user), 404);
        $r = $i->rights ?? [];
        $i->increment('views');
        $name = $user->name_ar ?: $user->name;

        return ['mime' => $i->file_mime, 'url' => $i->file_path ? $this->storage->temporaryUrl('materials', $i->file_path, 3600) : $i->url, 'external' => ! $i->file_path, 'watermark' => ($r['watermark'] ?? false) ? $name.' · '.($user->employee?->employee_no ?? $user->email).' · '.now()->format('Y-m-d') : null, 'allow_download' => (bool) ($r['download'] ?? true), 'allow_print' => (bool) ($r['print'] ?? true)];
    }

    /** @return array{url: string, name: string} */
    public function download(LibraryItem $i, User $user): array
    {
        abort_unless($this->canView($i, $user), 404);
        if (($i->rights['download'] ?? true) === false || ! $i->file_path) {
            throw new BusinessRuleException(__('messages.library.download_not_allowed'), 'download_not_allowed');
        }
        $i->increment('downloads');

        return ['url' => $this->storage->temporaryUrl('materials', $i->file_path, 600), 'name' => $i->title_en ?: $i->title_ar];
    }

    public function review(LibraryItem $i, User $user, int $stars, ?string $text): LibraryReview
    {
        abort_unless($this->canView($i, $user), 404);

        return LibraryReview::updateOrCreate(['item_id' => $i->id, 'user_id' => $user->id], ['stars' => $stars, 'review' => $text]);
    }

    public function shelf(LibraryItem $i, User $user, bool $on, ?float $progress = null, ?string $position = null): void
    {
        abort_unless($this->canView($i, $user), 404);
        if (! $on) {
            LibraryShelf::where('item_id', $i->id)->where('user_id', $user->id)->delete();

            return;
        }
        $row = LibraryShelf::firstOrNew(['item_id' => $i->id, 'user_id' => $user->id]);
        $row->fill(array_filter(['progress' => $progress, 'position' => $position], fn ($v) => $v !== null))->save();
    }
}
