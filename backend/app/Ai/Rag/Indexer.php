<?php

namespace App\Ai\Rag;

use App\Models\CourseLesson;
use App\Models\Embedding;
use App\Models\LibraryItem;
use App\Models\Program;

/**
 * Keeps the retrieval index in step with the content. Only what a trainee may be told is indexed: published lessons of published programmes
 * (visible to the people registered in the programme), programme descriptions and approved library items (visible to everyone signed in).
 * Content that is unpublished, archived or removed is taken out.
 */
class Indexer
{
    public function __construct(private readonly LocalEmbedder $embedder) {}

    public function lesson(CourseLesson $l): int
    {
        $program = Program::find($l->program_id);
        if ($l->status !== 'published' || ! $program || in_array($program->status, [Program::STATUS_DRAFT, Program::STATUS_ARCHIVED, Program::STATUS_CANCELLED], true)) {
            return $this->forget('lesson', $l->id);
        }
        $ar = trim(($l->title_ar ?? '')."\n".($l->description_ar ?? '')."\n".($l->body_ar ?? ''));
        $en = trim(($l->title_en ?? '')."\n".($l->description_en ?? '')."\n".($l->body_en ?? ''));
        $text = trim($ar."\n".$en);

        return $this->store('lesson', $l->id, $l->program_id, 'program', $l->title_ar ?: (string) $l->title_en, "/portal/learn/{registration}/{$l->id}", $text);
    }

    public function program(Program $p): int
    {
        if (in_array($p->status, [Program::STATUS_DRAFT, Program::STATUS_ARCHIVED, Program::STATUS_CANCELLED], true)) {
            return $this->forget('program', $p->id);
        }
        $text = trim(implode("\n", array_filter([$p->title_ar, $p->title_en, $p->summary_ar, $p->summary_en, $p->description_ar, $p->description_en, implode(' ', (array) $p->objectives)])));

        return $this->store('program', $p->id, $p->id, 'public', $p->title_ar ?: (string) $p->title_en, "/portal/training?program={$p->id}", $text);
    }

    public function library(LibraryItem $i): int
    {
        if ($i->status !== 'published' || ! empty($i->audience)) {   // an item limited to an audience is not for everyone
            return $this->forget('library', $i->id);
        }
        $text = trim(implode("\n", array_filter([$i->title_ar, $i->title_en, $i->description_ar, $i->description_en, implode(' ', (array) $i->subjects)])));

        return $this->store('library', $i->id, null, 'public', $i->title_ar ?: (string) $i->title_en, '/portal/library', $text);
    }

    /** Re-indexes everything; returns the number of chunks stored. */
    public function all(): int
    {
        $n = 0;
        CourseLesson::query()->chunkById(200, function ($rows) use (&$n) {
            foreach ($rows as $l) {
                $n += $this->lesson($l);
            }
        });
        Program::query()->chunkById(200, function ($rows) use (&$n) {
            foreach ($rows as $p) {
                $n += $this->program($p);
            }
        });
        LibraryItem::query()->chunkById(200, function ($rows) use (&$n) {
            foreach ($rows as $i) {
                $n += $this->library($i);
            }
        });

        return $n;
    }

    public function forget(string $type, string $id): int
    {
        Embedding::where(['source_type' => $type, 'source_id' => $id])->delete();

        return 0;
    }

    private function store(string $type, string $id, ?string $programId, string $visibility, string $title, string $route, string $text): int
    {
        $chunks = Chunker::split($text);
        if (! $chunks) {
            return $this->forget($type, $id);
        }
        $existing = Embedding::where(['source_type' => $type, 'source_id' => $id])->get()->keyBy('chunk_no');
        foreach ($chunks as $no => $content) {
            $hash = sha1($content.$title.$visibility.$programId);
            $row = $existing->get($no);
            if ($row && $row->content_hash === $hash) {
                continue;
            }
            $lang = preg_match('/\p{Arabic}/u', $content) ? 'ar' : 'en';
            Embedding::updateOrCreate(['source_type' => $type, 'source_id' => $id, 'chunk_no' => $no], [
                'program_id' => $programId, 'visibility' => $visibility, 'lang' => $lang, 'title' => mb_substr($title, 0, 250), 'route' => $route, 'content' => $content,
                'vector' => $this->embedder->embed($title.' '.$content), 'content_hash' => $hash, 'updated_at' => now(),
            ]);
        }
        Embedding::where(['source_type' => $type, 'source_id' => $id])->where('chunk_no', '>=', count($chunks))->delete();

        return count($chunks);
    }
}
