<?php

namespace App\Services\Content;

use App\Models\CaliperEvent;
use App\Models\CourseLesson;
use App\Models\Registration;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/** IMS Caliper 1.2: events are queued as they happen and sent in batched envelopes to the configured endpoint. */
class CaliperService
{
    public const CONTEXT = 'http://purl.imsglobal.org/ctx/caliper/v1p2';

    public function __construct(private readonly StandardsSettings $settings) {}

    /**
     * @param  'NavigationEvent'|'AssessmentEvent'|'GradeEvent'|'ToolUseEvent'|'MediaEvent'|'SessionEvent'  $type
     * @param  array<string, mixed>  $extensions
     */
    public function emit(string $type, Registration $registration, ?CourseLesson $lesson, string $action, array $extensions = []): void
    {
        if (! $this->settings->all()['caliper']['enabled']) {
            return;
        }
        $registration->loadMissing('employee.user', 'program');
        $home = rtrim((string) config('tedc.web_url'), '/') ?: 'https://tedc.local';
        $event = ['@context' => self::CONTEXT, 'id' => 'urn:uuid:'.Str::uuid(), 'type' => $type, 'actor' => ['id' => $home.'/users/'.$registration->employee->user_id, 'type' => 'Person'], 'action' => $action,
            'object' => ['id' => $home.'/'.($lesson ? 'lessons/'.$lesson->id : 'programs/'.$registration->program_id), 'type' => $lesson ? 'Page' : 'Course', 'name' => $lesson?->title_en ?? $registration->program->title_en],
            'eventTime' => now()->utc()->format('Y-m-d\TH:i:s.v\Z'), 'edApp' => $home, 'group' => ['id' => $home.'/programs/'.$registration->program_id, 'type' => 'CourseSection']] + ($extensions ? ['extensions' => $extensions] : []);
        CaliperEvent::create(['event' => $event]);
    }

    /** The envelope that carries a batch. @param  list<array<string, mixed>>  $events */
    public function envelope(array $events): array
    {
        return ['sensor' => $this->settings->all()['caliper']['sensor_id'] ?: (rtrim((string) config('tedc.web_url'), '/').'/sensors/1'), 'sendTime' => now()->utc()->format('Y-m-d\TH:i:s.v\Z'), 'dataVersion' => self::CONTEXT, 'data' => $events];
    }

    /** Sends the queued events (up to 100 per envelope); a failure is counted and retried up to 5 times. @return array{sent: int, failed: int} */
    public function flush(): array
    {
        $c = $this->settings->all()['caliper'];
        if (! $c['enabled'] || $c['endpoint'] === '') {
            return ['sent' => 0, 'failed' => 0];
        }
        $rows = CaliperEvent::where('status', 'pending')->where('attempts', '<', 5)->orderBy('created_at')->limit(100)->get();
        if ($rows->isEmpty()) {
            return ['sent' => 0, 'failed' => 0];
        }
        try {
            $res = Http::withToken($c['api_key'])->acceptJson()->timeout(10)->post($c['endpoint'], $this->envelope($rows->pluck('event')->all()));
            if ($res->successful()) {
                CaliperEvent::whereIn('id', $rows->pluck('id'))->update(['status' => 'sent', 'sent_at' => now(), 'error' => null]);

                return ['sent' => $rows->count(), 'failed' => 0];
            }
            $error = 'HTTP '.$res->status();
        } catch (\Throwable $e) {
            $error = mb_substr($e->getMessage(), 0, 300);
        }
        foreach ($rows as $r) {
            $r->update(['attempts' => $r->attempts + 1, 'error' => $error, 'status' => $r->attempts + 1 >= 5 ? 'failed' : 'pending']);
        }

        return ['sent' => 0, 'failed' => $rows->count()];
    }
}
