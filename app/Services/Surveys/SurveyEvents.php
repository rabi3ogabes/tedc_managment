<?php

namespace App\Services\Surveys;

use App\Models\AuditLog;
use App\Models\Survey;
use App\Models\SurveyEvent;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;

/**
 * The survey's event trail. Administrative actions are written to `survey_events` and mirrored into the
 * audit log; respondent events are written without an actor and never reach the audit log, so that a
 * confidential or anonymous answer cannot be matched to a person through the system's own records.
 */
class SurveyEvents
{
    public const ADMIN = ['created', 'updated', 'submitted_for_review', 'approved', 'returned', 'published', 'closed', 'reopened', 'archived', 'reminded', 'exported', 'privacy_changed', 'duplicated', 'migrated', 'needs_generated', 'key_deleted', 'answers_purged', 'subject_erased', 'ai_summarised', 'ai_suggested'];

    public static function record(Survey $survey, string $event, array $data = [], ?User $actor = null): SurveyEvent
    {
        $actor ??= Auth::user();
        $row = SurveyEvent::create([
            'survey_id' => $survey->id, 'actor_id' => $actor?->id, 'event' => $event, 'data' => $data ?: null,
            'ip_address' => Request::ip(), 'created_at' => now(),
        ]);
        AuditLog::create([
            'user_id' => $actor?->id, 'action' => 'survey.'.$event, 'auditable_type' => Survey::class, 'auditable_id' => $survey->id,
            'old_values' => null, 'new_values' => ['title' => $survey->title_ar, 'status' => $survey->status] + $data,
            'ip_address' => Request::ip(), 'user_agent' => substr((string) Request::userAgent(), 0, 255), 'url' => substr((string) Request::fullUrl(), 0, 255),
        ]);

        return $row;
    }

    /** A respondent event: no actor, no IP, no audit row. `$data` must not identify the person. */
    public static function respondent(Survey $survey, string $event, array $data = []): SurveyEvent
    {
        return SurveyEvent::create(['survey_id' => $survey->id, 'actor_id' => null, 'event' => $event, 'data' => $data ?: null, 'ip_address' => null, 'created_at' => now()]);
    }
}
