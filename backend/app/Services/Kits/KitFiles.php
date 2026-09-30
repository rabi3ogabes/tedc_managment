<?php

namespace App\Services\Kits;

use App\Exceptions\BusinessRuleException;
use App\Models\KitFile;
use App\Models\KitFileVersion;
use App\Models\TrainingKit;
use App\Models\User;
use App\Services\FileStorage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/** Files of a training kit: uploads, editable decks (with per-slide merging), versions and presence. */
class KitFiles
{
    public function __construct(private readonly FileStorage $storage) {}

    public function upload(TrainingKit $kit, UploadedFile $upload, User $user, ?string $category = null, ?string $name = null): KitFile
    {
        $extension = strtolower($upload->getClientOriginalExtension() ?: 'bin');
        $kind = KitFile::kindFor($extension, $upload->getMimeType());
        $path = $this->storage->upload($upload, 'documents', "kits/{$kit->id}/files");

        $file = KitFile::create([
            'kit_id' => $kit->id, 'name' => $name ?: pathinfo($upload->getClientOriginalName(), PATHINFO_FILENAME), 'original_name' => $upload->getClientOriginalName(),
            'kind' => $kind, 'category' => $category && in_array($category, KitFile::CATEGORIES, true) ? $category : KitFile::defaultCategory($kind), 'source' => 'upload',
            'mime' => $upload->getMimeType(), 'size' => $upload->getSize(), 'storage_path' => $path, 'uploaded_by' => $user->id, 'updated_by' => $user->id,
            'sort_order' => (int) KitFile::where('kit_id', $kit->id)->max('sort_order') + 1,
        ]);
        KitLog::record($kit, $user, 'file_uploaded', 'file', $file->id, ['name' => $file->name, 'kind' => $kind]);

        return $file;
    }

    /** A new editable deck. */
    public function createDeck(TrainingKit $kit, User $user, string $name, array $deck, string $source = 'created', ?string $category = null): KitFile
    {
        $deck = DeckModel::normalize($deck);
        DeckModel::ingestDataUris($deck, $kit, $user, $this->storage);
        $deck = $this->stamp($deck, [], $user, allNew: true);

        $file = KitFile::create([
            'kit_id' => $kit->id, 'name' => $name, 'kind' => 'presentation', 'category' => $category ?: 'presentation', 'source' => $source, 'mime' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'content' => $deck, 'revision' => 1, 'uploaded_by' => $user->id, 'updated_by' => $user->id, 'sort_order' => (int) KitFile::where('kit_id', $kit->id)->max('sort_order') + 1,
        ]);
        KitLog::record($kit, $user, $source === 'generated' ? 'deck_generated' : 'deck_created', 'file', $file->id, ['name' => $name, 'slides' => count($deck['slides'])]);

        return $file;
    }

    /**
     * Makes an uploaded PPTX editable by attaching the deck the browser extracted from it. The
     * original binary stays as version 1.
     */
    public function attachDeck(KitFile $file, array $deck, User $user): KitFile
    {
        if ($file->kind !== 'presentation') {
            throw new BusinessRuleException(__('messages.kit.not_a_presentation'), 'kit_invalid_file');
        }
        $kit = $file->kit;
        $deck = DeckModel::normalize($deck);
        DeckModel::ingestDataUris($deck, $kit, $user, $this->storage, $file->id);
        $deck = $this->stamp($deck, [], $user, allNew: true);

        DB::transaction(function () use ($file, $deck, $user) {
            $this->snapshot($file, $user, 'upload', 'Original upload');
            $file->update(['content' => $deck, 'revision' => 1, 'updated_by' => $user->id]);
        });
        KitLog::record($kit, $user, 'deck_imported', 'file', $file->id, ['name' => $file->name, 'slides' => count($deck['slides'])]);

        return $file->refresh();
    }

    /** Replaces the binary with a new upload, keeping the previous one as a version. */
    public function replaceBinary(KitFile $file, UploadedFile $upload, User $user, string $source = 'upload', ?string $note = null): KitFile
    {
        $path = $this->storage->upload($upload, 'documents', "kits/{$file->kit_id}/files");
        DB::transaction(function () use ($file, $upload, $user, $path, $source, $note) {
            $this->snapshot($file, $user, $source, $note);
            $file->update(['storage_path' => $path, 'size' => $upload->getSize(), 'mime' => $upload->getMimeType() ?: $file->mime, 'updated_by' => $user->id]);
        });
        KitLog::record($file->kit_id, $user, $source === 'export' ? 'file_exported' : 'file_replaced', 'file', $file->id, ['name' => $file->name]);

        return $file->refresh();
    }

    /** Freezes the current state of a file as an immutable version and moves on to the next number. */
    public function snapshot(KitFile $file, ?User $user, string $source = 'manual', ?string $note = null): KitFileVersion
    {
        $version = KitFileVersion::create([
            'file_id' => $file->id, 'version' => $file->version, 'storage_path' => $file->storage_path, 'snapshot' => $file->content ? DeckModel::stripUrls($file->content) : null,
            'size' => $file->size, 'source' => $source, 'note' => $note, 'created_by' => $user?->id,
        ]);
        $file->update(['version' => $file->version + 1]);

        return $version;
    }

    /** Autosaves a version when the last one is older than the configured window. */
    public function snapshotIfDue(KitFile $file, User $user): ?KitFileVersion
    {
        $last = $file->versions()->first();
        $window = (int) config('tedc.kits.autosnapshot_minutes');
        if ($last && $last->created_at->gt(now()->subMinutes($window))) {
            return null;
        }

        return $this->snapshot($file, $user, 'autosave');
    }

    public function restore(KitFile $file, KitFileVersion $version, User $user): KitFile
    {
        DB::transaction(function () use ($file, $version, $user) {
            $this->snapshot($file, $user, 'restore', "Before restoring v{$version->version}");
            $file->update([
                'content' => $version->snapshot, 'storage_path' => $version->storage_path ?? $file->storage_path, 'size' => $version->size ?: $file->size,
                'revision' => $file->revision + 1, 'updated_by' => $user->id,
            ]);
        });
        KitLog::record($file->kit_id, $user, 'file_restored', 'file', $file->id, ['name' => $file->name, 'version' => $version->version]);

        return $file->refresh();
    }

    /**
     * Saves editor changes slide by slide so the developer and the QA team can work on one deck.
     * A slide changed by someone else since the editor loaded it is reported as a conflict and left
     * untouched, unless `$force` is set (the editor's "keep mine").
     *
     * @param  array{slides?: array, deleted?: array, order?: array, theme?: array}  $changes
     * @return array{deck: array, conflicts: list<array>, revision: int, changed: int}
     */
    public function saveDeck(KitFile $file, array $changes, User $user, bool $force = false): array
    {
        return DB::transaction(function () use ($file, $changes, $user, $force) {
            $file = KitFile::whereKey($file->id)->lockForUpdate()->firstOrFail();
            $current = $file->content ?? DeckModel::blank();
            $byId = collect($current['slides'])->keyBy('id')->all();
            $conflicts = [];
            $changed = 0;

            foreach ((array) ($changes['slides'] ?? []) as $incoming) {
                $slide = DeckModel::normalizeSlide((array) $incoming);
                $existing = $byId[$slide['id']] ?? null;
                if ($existing) {
                    if ($this->sameContent($existing, $slide)) {
                        continue;
                    }
                    if ($existing['rev'] > $slide['rev'] && ! $force) {
                        $conflicts[] = ['slide_id' => $slide['id'], 'server' => $existing];

                        continue;
                    }
                    $slide['rev'] = $existing['rev'] + 1;
                } else {
                    $slide['rev'] = 1;
                }
                $byId[$slide['id']] = $this->stampSlide($slide, $user);
                $changed++;
            }

            foreach ((array) ($changes['deleted'] ?? []) as $gone) {
                $id = is_array($gone) ? ($gone['id'] ?? null) : $gone;
                $rev = is_array($gone) ? (int) ($gone['rev'] ?? 0) : 0;
                if ($id && isset($byId[$id])) {
                    if (! $force && $rev && $byId[$id]['rev'] > $rev) {
                        $conflicts[] = ['slide_id' => $id, 'server' => $byId[$id]];

                        continue;
                    }
                    unset($byId[$id]);
                    $changed++;
                }
            }

            $order = array_values(array_filter((array) ($changes['order'] ?? []), fn ($id) => is_string($id) && isset($byId[$id])));
            $ordered = [];
            foreach ($order as $id) {
                $ordered[$id] = $byId[$id];
            }
            foreach ($byId as $id => $slide) {
                $ordered[$id] ??= $slide;
            }
            if ($order && array_keys($ordered) !== array_map(fn ($s) => $s['id'], $current['slides'])) {
                $changed++;
            }

            $deck = $current;
            $deck['slides'] = array_slice(array_values($ordered), 0, DeckModel::MAX_SLIDES);
            if (! empty($changes['theme'])) {
                $deck['theme'] = DeckModel::normalize(['theme' => $changes['theme']])['theme'];
            }
            DeckModel::ingestDataUris($deck, $file->kit, $user, $this->storage, $file->id);

            if ($changed > 0) {
                $file->update(['content' => $deck, 'revision' => $file->revision + 1, 'updated_by' => $user->id]);
                $this->snapshotIfDue($file, $user);
            }

            return ['deck' => DeckModel::decorate($file->content ?? $deck, $this->storage), 'conflicts' => $conflicts, 'revision' => $file->revision, 'changed' => $changed];
        });
    }

    private function sameContent(array $a, array $b): bool
    {
        $strip = fn (array $s) => array_diff_key($s, array_flip(['rev', 'by', 'by_name', 'at']));

        return json_encode($strip($a)) === json_encode($strip($b));
    }

    private function stampSlide(array $slide, User $user): array
    {
        return ['by' => $user->id, 'by_name' => $user->displayName(), 'at' => now()->toIso8601String()] + $slide;
    }

    private function stamp(array $deck, array $existing, User $user, bool $allNew = false): array
    {
        foreach ($deck['slides'] as $i => $slide) {
            $deck['slides'][$i] = array_merge($slide, ['by' => $user->id, 'by_name' => $user->displayName(), 'at' => now()->toIso8601String()]);
        }

        return $deck;
    }

    // Presence ---------------------------------------------------------------------------------

    /** @return list<array{user_id: string, name: string, slide_id: ?string, at: int}> */
    public function heartbeat(KitFile $file, User $user, ?string $slideId): array
    {
        $key = "kit:presence:{$file->id}";
        $now = time();
        $people = array_filter((array) Cache::get($key, []), fn ($p) => $now - $p['at'] < 45);
        $people[$user->id] = ['user_id' => $user->id, 'name' => $user->displayName(), 'slide_id' => $slideId, 'at' => $now];
        Cache::put($key, $people, 120);

        return array_values($people);
    }

    /** @return list<array{user_id: string, name: string, slide_id: ?string, at: int}> */
    public function presence(KitFile $file): array
    {
        $now = time();

        return array_values(array_filter((array) Cache::get("kit:presence:{$file->id}", []), fn ($p) => $now - $p['at'] < 45));
    }

    public function leave(KitFile $file, User $user): void
    {
        $key = "kit:presence:{$file->id}";
        $people = (array) Cache::get($key, []);
        unset($people[$user->id]);
        Cache::put($key, $people, 120);
    }
}
