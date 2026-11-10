<?php

namespace App\Services\Surveys;

use App\Exceptions\BusinessRuleException;
use App\Models\Survey;
use App\Models\SurveyFile;
use App\Models\SurveySubmission;
use App\Services\FileStorage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

/**
 * Files attached to file-upload answers. Checked by content type and size against the question's rules, kept in a
 * private bucket under the survey, bound to the respondent key that uploaded them, and linked to the submission on
 * submit. Served only through short-lived links to people allowed to see results.
 */
class SurveyFiles
{
    public const BUCKET = 'evidence'; // the existing private documents bucket

    /** Accepted content types per kind the author may tick. */
    public const MIMES = [
        'pdf' => ['application/pdf'],
        'image' => ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/heic'],
        'doc' => ['application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/rtf', 'text/plain'],
        'xls' => ['application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'text/csv'],
        'ppt' => ['application/vnd.ms-powerpoint', 'application/vnd.openxmlformats-officedocument.presentationml.presentation'],
        'zip' => ['application/zip', 'application/x-zip-compressed'],
        'audio' => ['audio/mpeg', 'audio/mp4', 'audio/wav', 'audio/x-wav', 'audio/ogg', 'audio/webm', 'audio/aac'],
        'video' => ['video/mp4', 'video/quicktime', 'video/webm'],
    ];

    public function __construct(private readonly FileStorage $storage) {}

    public function upload(Survey $survey, string $respondentKey, array $question, UploadedFile $file): SurveyFile
    {
        if (($question['type'] ?? null) !== 'file_upload') {
            throw new BusinessRuleException(__('messages.surveys.file_question'), 'survey_file_question');
        }
        $mime = (string) ($file->getMimeType() ?: 'application/octet-stream'); // sniffed from content, not the extension
        $allowed = array_merge(...array_values(array_intersect_key(self::MIMES, array_flip($question['accept'] ?? ['pdf', 'image']))));
        if (! in_array($mime, $allowed, true)) {
            throw new BusinessRuleException(__('messages.surveys.file_type', ['types' => implode(', ', $question['accept'] ?? [])]), 'survey_file_type', ['mime' => $mime]);
        }
        $maxBytes = (int) ($question['max_mb'] ?? 10) * 1024 * 1024;
        if ($file->getSize() > $maxBytes) {
            throw new BusinessRuleException(__('messages.surveys.file_size', ['mb' => $question['max_mb'] ?? 10]), 'survey_file_size');
        }
        $pending = SurveyFile::where('survey_id', $survey->id)->where('respondent_key', $respondentKey)->where('question_id', $question['id'])->whereNull('submission_id')->count();
        if ($pending >= (int) ($question['max_files'] ?? 1)) {
            throw new BusinessRuleException(__('messages.surveys.file_count', ['n' => $question['max_files'] ?? 1]), 'survey_file_count');
        }
        $path = $this->storage->upload($file, self::BUCKET, 'surveys/'.$survey->id);

        return SurveyFile::create([
            'survey_id' => $survey->id, 'respondent_key' => $respondentKey, 'question_id' => $question['id'], 'bucket' => self::BUCKET, 'path' => $path,
            'original_name' => Str::limit($file->getClientOriginalName() ?: 'file', 200, ''), 'mime' => $mime, 'size' => (int) $file->getSize(),
        ]);
    }

    /** Removes a file the respondent uploaded but has not submitted yet. */
    public function discard(Survey $survey, string $respondentKey, string $fileId): void
    {
        $f = SurveyFile::where('survey_id', $survey->id)->where('respondent_key', $respondentKey)->whereNull('submission_id')->find($fileId);
        if ($f) {
            rescue(fn () => $this->storage->delete($f->bucket, $f->path), report: false);
            $f->delete();
        }
    }

    /** Every file id in a file answer must be the respondent's own upload for that question. */
    public function assertAnswerFiles(Survey $survey, string $respondentKey, array $questions, array $answers): void
    {
        foreach ($questions as $q) {
            if ($q['type'] !== 'file_upload' || empty($answers[$q['id']])) {
                continue;
            }
            $ids = (array) $answers[$q['id']];
            $owned = SurveyFile::where('survey_id', $survey->id)->where('respondent_key', $respondentKey)->where('question_id', $q['id'])->whereIn('id', $ids)->count();
            if ($owned !== count(array_unique($ids))) {
                throw new BusinessRuleException(__('messages.surveys.file_unknown'), 'survey_file_unknown', ['question' => $q['id']]);
            }
        }
    }

    /** Binds the answer's files to the submission (and frees files that were replaced). */
    public function attach(Survey $survey, string $respondentKey, SurveySubmission $submission, array $answers): void
    {
        $ids = [];
        foreach ($answers as $value) {
            if (is_array($value) && array_is_list($value)) {
                foreach ($value as $v) {
                    if (is_string($v) && Str::isUuid($v)) {
                        $ids[] = $v;
                    }
                }
            }
        }
        SurveyFile::where('survey_id', $survey->id)->where('respondent_key', $respondentKey)->whereIn('id', $ids)->update(['submission_id' => $submission->id]);
        foreach (SurveyFile::where('survey_id', $survey->id)->where('respondent_key', $respondentKey)->whereNull('submission_id')->get() as $orphan) {
            rescue(fn () => $this->storage->delete($orphan->bucket, $orphan->path), report: false);
            $orphan->delete();
        }
    }

    /** A short-lived link for someone allowed to see results. */
    public function url(SurveyFile $file): string
    {
        return $this->storage->temporaryUrl($file->bucket, $file->path, 300);
    }

    public function present(SurveyFile $f): array
    {
        return ['id' => $f->id, 'question_id' => $f->question_id, 'name' => $f->original_name, 'mime' => $f->mime, 'size' => $f->size, 'uploaded_at' => $f->created_at];
    }
}
