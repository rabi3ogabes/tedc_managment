<?php

namespace App\Ai\Rag;

use App\Models\CourseLesson;
use App\Models\Embedding;
use App\Models\HelpArticle;
use App\Models\LibraryItem;
use App\Models\Program;

/**
 * Keeps the retrieval index in step with the content. Only what a trainee may be told is indexed: published lessons of published programmes
 * (visible to the people registered in the programme), programme descriptions, approved library items and the help articles written for
 * everyone or for trainees (visible to everyone signed in). Content that is unpublished, archived or removed is taken out.
 *
 * Each piece is embedded by every live embedder (the local one always; an embeddings model when one is active or being filled), and only
 * the pieces whose text changed are embedded again. A model that cannot be reached never stops the save: its vectors catch up on the
 * next re-embedding run.
 */
class Indexer
{
    public function __construct(private readonly Embedders $embedders, private readonly Stores $stores) {}

    /** @param  list<Embedder>|null  $only */
    public function lesson(CourseLesson $l, ?array $only = null): int
    {
        $program = Program::find($l->program_id);
        if ($l->status !== 'published' || ! $program || in_array($program->status, [Program::STATUS_DRAFT, Program::STATUS_ARCHIVED, Program::STATUS_CANCELLED], true)) {
            return $this->forget('lesson', $l->id);
        }
        $ar = trim(($l->title_ar ?? '')."\n".($l->description_ar ?? '')."\n".($l->body_ar ?? ''));
        $en = trim(($l->title_en ?? '')."\n".($l->description_en ?? '')."\n".($l->body_en ?? ''));
        $text = trim($ar."\n".$en);

        return $this->store('lesson', $l->id, $l->program_id, 'program', $l->title_ar ?: (string) $l->title_en, "/portal/learn/{registration}/{$l->id}", $text, $only);
    }

    /** @param  list<Embedder>|null  $only */
    public function program(Program $p, ?array $only = null): int
    {
        if (in_array($p->status, [Program::STATUS_DRAFT, Program::STATUS_ARCHIVED, Program::STATUS_CANCELLED], true)) {
            return $this->forget('program', $p->id);
        }
        $text = trim(implode("\n", array_filter([$p->title_ar, $p->title_en, $p->summary_ar, $p->summary_en, $p->description_ar, $p->description_en, implode(' ', (array) $p->objectives)])));

        return $this->store('program', $p->id, $p->id, 'public', $p->title_ar ?: (string) $p->title_en, "/portal/training?program={$p->id}", $text, $only);
    }

    /** @param  list<Embedder>|null  $only */
    public function library(LibraryItem $i, ?array $only = null): int
    {
        if ($i->status !== 'published' || ! empty($i->audience)) {   // an item limited to an audience is not for everyone
            return $this->forget('library', $i->id);
        }
        $text = trim(implode("\n", array_filter([$i->title_ar, $i->title_en, $i->description_ar, $i->description_en, implode(' ', (array) $i->subjects)])));

        return $this->store('library', $i->id, null, 'public', $i->title_ar ?: (string) $i->title_en, '/portal/library', $text, $only);
    }

    /** Help articles for everyone or for trainees; articles for staff roles stay out of the trainee assistant. @param  list<Embedder>|null  $only */
    public function help(HelpArticle $a, ?array $only = null): int
    {
        $roles = (array) ($a->roles ?? []);
        if ($a->status !== 'published' || ($roles !== [] && ! in_array('employee', $roles, true))) {
            return $this->forget('help', $a->id);
        }
        $text = trim(implode("\n", array_filter([$a->title_ar, strip_tags((string) $a->body_ar), $a->title_en, strip_tags((string) $a->body_en)])));

        return $this->store('help', $a->id, null, 'public', $a->title_ar ?: (string) $a->title_en, '/portal/help?article='.rawurlencode($a->slug), $text, $only);
    }

    /** Re-indexes everything; returns the number of chunks stored. @param  list<Embedder>|null  $only  limit to these embedders */
    public function all(?array $only = null): int
    {
        $n = 0;
        CourseLesson::query()->chunkById(200, function ($rows) use (&$n, $only) {
            foreach ($rows as $l) {
                $n += $this->lesson($l, $only);
            }
        });
        Program::query()->chunkById(200, function ($rows) use (&$n, $only) {
            foreach ($rows as $p) {
                $n += $this->program($p, $only);
            }
        });
        LibraryItem::query()->chunkById(200, function ($rows) use (&$n, $only) {
            foreach ($rows as $i) {
                $n += $this->library($i, $only);
            }
        });
        HelpArticle::query()->chunkById(200, function ($rows) use (&$n, $only) {
            foreach ($rows as $a) {
                $n += $this->help($a, $only);
            }
        });

        return $n;
    }

    public function forget(string $type, string $id): int
    {
        Embedding::where(['source_type' => $type, 'source_id' => $id])->delete();

        return 0;
    }

    /** @param  list<Embedder>|null  $only */
    private function store(string $type, string $id, ?string $programId, string $visibility, string $title, string $route, string $text, ?array $only): int
    {
        $chunks = Chunker::split($text);
        if (! $chunks) {
            return $this->forget($type, $id);
        }
        $meta = ['program_id' => $programId, 'visibility' => $visibility, 'title' => mb_substr($title, 0, 250), 'route' => $route];
        foreach ($only ?? $this->embedders->live() as $embedder) {
            try {
                $this->sync($embedder, $type, $id, $chunks, $meta);
            } catch (EmbedderUnavailable) {
                // the model's vectors of this piece catch up on the next re-embedding run
            }
        }

        return count($chunks);
    }

    /**
     * Writes one embedder's vectors of a piece of content, embedding only the chunks that changed.
     *
     * @param  list<string>  $chunks
     * @param  array{program_id: ?string, visibility: string, title: string, route: string}  $meta
     */
    public function sync(Embedder $embedder, string $type, string $id, array $chunks, array $meta): void
    {
        $model = $embedder->id();
        $existing = Embedding::where(['source_type' => $type, 'source_id' => $id, 'model' => $model])->get()->keyBy('chunk_no');
        $todo = [];
        foreach ($chunks as $no => $content) {
            $hash = self::hash($content, $meta);
            $row = $existing->get($no);
            if (! $row || $row->content_hash !== $hash) {
                $todo[$no] = [$content, $hash];
            }
        }
        if ($todo) {
            $vectors = $embedder->embedMany(array_map(fn ($t) => $meta['title'].' '.$t[0], array_values($todo)));
            foreach (array_keys($todo) as $k => $no) {
                [$content, $hash] = $todo[$no];
                $row = Embedding::updateOrCreate(['source_type' => $type, 'source_id' => $id, 'chunk_no' => $no, 'model' => $model], $meta + [
                    'lang' => preg_match('/\p{Arabic}/u', $content) ? 'ar' : 'en', 'content' => $content, 'vector' => $vectors[$k], 'dim' => count($vectors[$k]), 'content_hash' => $hash, 'updated_at' => now(),
                ]);
                foreach ($this->stores->writers() as $store) {
                    $store->write($row, $vectors[$k]);
                }
            }
        }
        Embedding::where(['source_type' => $type, 'source_id' => $id, 'model' => $model])->where('chunk_no', '>=', count($chunks))->delete();
    }

    /** @param  array{program_id: ?string, visibility: string, title: string}  $meta */
    public static function hash(string $content, array $meta): string
    {
        return sha1($content.$meta['title'].$meta['visibility'].$meta['program_id']);
    }
}
