<?php

namespace App\Services\Surveys;

use App\Services\NeedsSurveys\SurveySchema;
use Illuminate\Support\Str;

/**
 * Turns a legacy question list (needs surveys and evaluation forms, {@see SurveySchema})
 * into an engine definition. Ids, options, rows, scales, skills and `show_if` carry over unchanged; a question that
 * accepted evidence gets two optional companions right after it: a file-upload question and a link field, so the
 * evidence stays a first-class answer in the engine.
 */
class LegacyQuestions
{
    public const EVIDENCE_SUFFIX = '_ev';

    public const LINK_SUFFIX = '_evl';

    public const EVIDENCE_ACCEPT = ['pdf', 'image', 'doc', 'xls', 'video'];

    /** @param  array<string, mixed>  $settings  the legacy form settings ({evidence, max_files}) */
    public static function convert(array $questions, array $settings = []): array
    {
        $allowEvidence = (bool) ($settings['evidence'] ?? true);
        $maxFiles = max(1, min(5, (int) ($settings['max_files'] ?? 3)));
        $out = [];
        foreach ($questions as $q) {
            $item = $q;
            unset($item['evidence']);
            $out[] = $item;
            if (! $allowEvidence || empty($q['evidence']) || empty($q['id'])) {
                continue;
            }
            $base = self::base($q['id']);
            $visible = ! empty($q['show_if']['question']) ? ['match' => 'all', 'conditions' => [$q['show_if']]] : null;
            $out[] = array_filter([
                'id' => $base.self::EVIDENCE_SUFFIX, 'type' => 'file_upload', 'required' => false,
                'title' => Str::limit('دليل داعم: '.$q['title'], 500, ''), 'title_en' => Str::limit('Supporting evidence: '.($q['title_en'] ?? $q['title']), 500, ''),
                'max_files' => $maxFiles, 'max_mb' => 10, 'accept' => self::EVIDENCE_ACCEPT, 'visible_if' => $visible,
            ], fn ($v) => $v !== null);
            $out[] = array_filter([
                'id' => $base.self::LINK_SUFFIX, 'type' => 'short_text', 'required' => false,
                'title' => 'رابط دليل (اختياري)', 'title_en' => 'Evidence link (optional)', 'placeholder' => 'https://', 'max_length' => 500, 'visible_if' => $visible,
            ], fn ($v) => $v !== null);
        }

        return SurveyDefinition::normalize($out);
    }

    /** The prefix the companions of a question are named after (kept short enough for the id limit). */
    public static function base(string $questionId): string
    {
        return substr($questionId, 0, 34);
    }

    /** Whether an engine question is an evidence companion made by the conversion. */
    public static function isCompanion(array $question): bool
    {
        return str_ends_with($question['id'], self::EVIDENCE_SUFFIX) || str_ends_with($question['id'], self::LINK_SUFFIX);
    }

    /** The question an evidence companion belongs to, or null for an ordinary question. */
    public static function parentOf(string $questionId, array $questions): ?string
    {
        foreach ([self::LINK_SUFFIX, self::EVIDENCE_SUFFIX] as $suffix) {
            if (str_ends_with($questionId, $suffix)) {
                $base = substr($questionId, 0, -strlen($suffix));
                foreach ($questions as $q) {
                    if (self::base($q['id']) === $base && ! self::isCompanion($q)) {
                        return $q['id'];
                    }
                }

                return $base;
            }
        }

        return null;
    }

    /** The MIME type a legacy evidence file most likely has, from its name (the legacy table never stored one). */
    public static function mimeOf(string $name): string
    {
        return match (Str::lower(pathinfo($name, PATHINFO_EXTENSION))) {
            'pdf' => 'application/pdf',
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'doc' => 'application/msword',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'xls' => 'application/vnd.ms-excel',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'mp4' => 'video/mp4',
            default => 'application/octet-stream',
        };
    }
}
