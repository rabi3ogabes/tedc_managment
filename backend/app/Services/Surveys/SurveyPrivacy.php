<?php

namespace App\Services\Surveys;

use App\Models\Employee;
use App\Models\Survey;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * What a submission may carry about the person, by privacy mode.
 *
 *  identified   the user id and the segment fields the author chose (all of them by default).
 *  confidential an HMAC pseudonym made with a per-survey key (so the same person cannot answer twice) and only
 *               the segment fields the author chose; the key is deleted after close + grace, after which the
 *               pseudonyms can no longer be matched to anyone.
 *  anonymous    nothing about the person; one-answer-per-person is enforced by the participation registry,
 *               which is never joined to the answers.
 */
class SurveyPrivacy
{
    public const SEGMENT_FIELDS = ['school_id', 'region', 'school_type', 'job_title_id', 'education_stage', 'specialization', 'nationality', 'gender', 'experience_band', 'qualification'];

    public static function newKey(): string
    {
        return Str::random(64);
    }

    /** The key a draft session and a confidential submission are filed under. Never the user id outside identified mode. */
    public static function respondentKey(Survey $survey, User $user): string
    {
        return $survey->isIdentified() ? $user->id : self::hmac($survey, $user);
    }

    public static function pseudonym(Survey $survey, User $user): ?string
    {
        return $survey->isConfidential() ? self::hmac($survey, $user) : null;
    }

    private static function hmac(Survey $survey, User $user): string
    {
        $key = $survey->pseudonym_key ?: throw new \LogicException('The survey has no pseudonym key; publish it first.');

        return hash_hmac('sha256', $user->id, $key);
    }

    /** The respondent columns of a submission for this user. */
    public static function columns(Survey $survey, User $user): array
    {
        return [
            'respondent_user_id' => $survey->isIdentified() ? $user->id : null,
            'respondent_pseudonym' => self::pseudonym($survey, $user),
            'segment_snapshot' => self::segmentSnapshot($survey, $user->employee),
        ];
    }

    /** The organisational attributes kept with the answers: only the fields the mode allows. */
    public static function segmentSnapshot(Survey $survey, ?Employee $employee): ?array
    {
        if (! $employee) {
            return null;
        }
        $fields = $survey->setting('segment_fields') ?? [];
        if (! $fields && $survey->isIdentified()) {
            $fields = self::SEGMENT_FIELDS;
        }
        $fields = array_intersect(self::SEGMENT_FIELDS, $fields);
        if (! $fields) {
            return null;
        }
        $employee->loadMissing('school');
        $all = [
            'school_id' => $employee->school_id,
            'region' => $employee->school?->region,
            'school_type' => $employee->school?->type,
            'job_title_id' => $employee->job_title_id,
            'education_stage' => $employee->education_stage,
            'specialization' => $employee->specialization,
            'nationality' => $employee->nationality,
            'gender' => $employee->gender,
            'experience_band' => self::experienceBand($employee->experience_years),
            'qualification' => $employee->qualification,
        ];
        $out = array_filter(array_intersect_key($all, array_flip($fields)), fn ($v) => $v !== null && $v !== '');

        return $out ?: null;
    }

    public static function experienceBand(mixed $years): ?string
    {
        if ($years === null || ! is_numeric($years)) {
            return null;
        }
        $y = (float) $years;

        return match (true) {
            $y < 3 => '0-2',
            $y < 6 => '3-5',
            $y < 11 => '6-10',
            $y < 21 => '11-20',
            default => '21+',
        };
    }
}
