<?php

namespace App\Services\Surveys;

use App\Models\EvaluationAssignment;
use App\Models\EvaluationResponse;
use App\Models\SurveyFile;
use App\Models\SurveySubmission;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * An evaluation answer stored by the survey engine, shaped like the legacy {@see EvaluationResponse} so the
 * results, the reports and the exports read both the same way: `answers`, `score`, `submitted_at`, `evidence` and the
 * `assignment`. Evidence is keyed by the question it belongs to, with files (from the engine's file store) and links.
 */
final class EvaluationAnswer
{
    /** @param  array<string, list<array<string, mixed>>>  $evidence */
    public function __construct(
        public readonly string $id,
        public readonly EvaluationAssignment $assignment,
        public readonly array $answers,
        public readonly ?float $score,
        public readonly Carbon $submitted_at,
        public readonly array $evidence,
        public readonly string $source = 'engine',
    ) {}

    /** @param  Collection<int, SurveyFile>  $files  the files bound to the submission */
    public static function fromSubmission(EvaluationAssignment $assignment, SurveySubmission $submission, Collection $files, array $questions): self
    {
        $evidence = [];
        foreach ($files as $f) {
            $parent = LegacyQuestions::parentOf($f->question_id, $questions) ?? $f->question_id;
            $evidence[$parent][] = ['type' => 'file', 'name' => $f->original_name, 'file_id' => $f->id, 'bucket' => $f->bucket, 'path' => $f->path, 'mime' => $f->mime, 'size' => $f->size];
        }
        foreach ($submission->answers as $qid => $value) {
            if (str_ends_with((string) $qid, LegacyQuestions::LINK_SUFFIX) && is_string($value) && $value !== '') {
                $parent = LegacyQuestions::parentOf((string) $qid, $questions) ?? (string) $qid;
                foreach (preg_split('/\s*\|\s*/', $value) as $url) {
                    if (preg_match('#^https?://#i', $url)) {
                        $evidence[$parent][] = ['type' => 'link', 'url' => $url];
                    }
                }
            }
        }

        return new self($submission->id, $assignment, $submission->answers, $assignment->score !== null ? (float) $assignment->score : null, $submission->submitted_at, $evidence);
    }
}
