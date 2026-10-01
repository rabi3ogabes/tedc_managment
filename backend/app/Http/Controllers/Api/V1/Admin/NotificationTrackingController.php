<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\AppNotification;
use App\Models\NotificationCampaign;
use App\Models\NotificationTemplate;
use App\Models\Program;
use App\Services\Notifications\NotificationCampaigns;
use App\Services\Notifications\NotificationTemplates;
use App\Services\Notifications\ProgramSurvey;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Sending a notification to a program's trainees, and tracking who received / saw / read it. */
class NotificationTrackingController extends Controller
{
    public function __construct(private readonly NotificationCampaigns $campaigns, private readonly ProgramSurvey $survey, private readonly NotificationTemplates $templates) {}

    /** Sends a notification to the trainees of a program. */
    public function send(Request $request): JsonResponse
    {
        $data = $request->validate([
            'program_id' => ['required', 'uuid', 'exists:programs,id'],
            'template_id' => ['nullable', 'uuid', 'exists:notification_templates,id'],
            'audience' => ['sometimes', 'in:trainees,pending_survey'],
            'title_ar' => ['nullable', 'string', 'max:200'], 'title_en' => ['nullable', 'string', 'max:200'],
            'body_ar' => ['nullable', 'string', 'max:1000'], 'body_en' => ['nullable', 'string', 'max:1000'],
        ]);
        $program = Program::findOrFail($data['program_id']);
        $template = isset($data['template_id']) ? NotificationTemplate::find($data['template_id']) : null;
        $audience = $data['audience'] ?? 'trainees';
        $userIds = $this->survey->traineeUserIds($program, $audience === 'pending_survey');

        abort_if($userIds->isEmpty(), 422, __('There are no trainees in this selection.'));
        $event = $template?->event ?? 'custom.adhoc';
        $campaign = $this->campaigns->send($event, str_starts_with($event, 'survey.') ? 'survey' : 'custom', $program, $userIds, $request->user(), $data, $audience);

        return response()->json(['data' => $this->summarise($campaign)], 201);
    }

    /** How many people a selection reaches, and how the wording will read. */
    public function audience(Request $request): JsonResponse
    {
        $data = $request->validate(['program_id' => ['required', 'uuid'], 'audience' => ['sometimes', 'in:trainees,pending_survey']]);
        $program = Program::findOrFail($data['program_id']);
        $ids = $this->survey->traineeUserIds($program, ($data['audience'] ?? 'trainees') === 'pending_survey');

        return response()->json(['data' => ['count' => $ids->count()]]);
    }

    public function campaigns(Request $request): JsonResponse
    {
        $q = NotificationCampaign::with(['program:id,code,title_ar,title_en', 'creator:id,name,name_ar'])->latest()
            ->when($request->query('program_id'), fn ($w, $v) => $w->where('program_id', $v));

        return response()->json($q->paginate(15)->through(fn (NotificationCampaign $c) => $this->summarise($c)));
    }

    public function campaign(Request $request, NotificationCampaign $campaign): JsonResponse
    {
        $status = $request->query('status');
        $rows = $campaign->notifications()->with(['user:id,name,name_ar,email', 'user.employee.school:id,name_ar,name_en'])
            ->when($status === 'read', fn ($w) => $w->whereNotNull('read_at'))
            ->when($status === 'unread', fn ($w) => $w->whereNull('read_at'))
            ->when($status === 'unseen', fn ($w) => $w->whereNull('seen_at'))
            ->when($request->query('q'), fn ($w, $v) => $w->whereHas('user', fn ($u) => $u->where('name', 'like', "%{$v}%")->orWhere('name_ar', 'like', "%{$v}%")->orWhere('email', 'like', "%{$v}%")))
            ->orderBy('read_at')->orderBy('created_at')->paginate(30);

        return response()->json(['data' => $this->summarise($campaign), 'recipients' => $rows->through(fn (AppNotification $n) => $this->recipient($n))]);
    }

    public function exportCampaign(NotificationCampaign $campaign): StreamedResponse
    {
        $rows = $campaign->notifications()->with(['user:id,name,name_ar,email', 'user.employee.school:id,name_ar'])->orderBy('created_at')->get();

        return $this->csv("notification-{$campaign->id}.csv", ['Name', 'Email', 'School', 'Sent', 'Seen', 'Read'], $rows->map(function (AppNotification $n) {
            $r = $this->recipient($n);

            return [$r['name'], $r['email'], $r['school'], $r['sent_at'], $r['seen_at'], $r['read_at']];
        }));
    }

    /** Every notification (not only campaigns) with its read state, for tracking one person or one program. */
    public function tracking(Request $request): JsonResponse
    {
        $q = AppNotification::with(['user:id,name,name_ar,email'])->latest()
            ->when($request->query('type'), fn ($w, $v) => $w->where('type', 'like', $v.'%'))
            ->when($request->query('program_id'), fn ($w, $v) => $w->where('data->program_id', $v))
            ->when($request->query('status') === 'read', fn ($w) => $w->whereNotNull('read_at'))
            ->when($request->query('status') === 'unread', fn ($w) => $w->whereNull('read_at'))
            ->when($request->query('q'), fn ($w, $v) => $w->whereHas('user', fn ($u) => $u->where('name', 'like', "%{$v}%")->orWhere('name_ar', 'like', "%{$v}%")->orWhere('email', 'like', "%{$v}%")));
        $totals = (clone $q)->toBase()->selectRaw('count(*) as sent_count, count(seen_at) as seen_count, count(read_at) as read_count')->reorder()->first();

        return response()->json([
            'summary' => ['sent' => (int) ($totals->sent_count ?? 0), 'seen' => (int) ($totals->seen_count ?? 0), 'read' => (int) ($totals->read_count ?? 0)],
            'data' => $q->paginate(30)->through(fn (AppNotification $n) => $this->recipient($n) + ['type' => $n->type, 'title' => app()->getLocale() === 'en' ? $n->title_en : $n->title_ar]),
        ]);
    }

    private function recipient(AppNotification $n): array
    {
        $tz = config('app.timezone');

        return [
            'id' => $n->id, 'user_id' => $n->user_id, 'name' => $n->user?->displayName() ?? '—', 'email' => $n->user?->email,
            'school' => $n->user?->employee?->school?->translate('name'),
            'sent_at' => $n->created_at->timezone($tz)->format('Y-m-d H:i'), 'seen_at' => $n->seen_at?->timezone($tz)->format('Y-m-d H:i'), 'read_at' => $n->read_at?->timezone($tz)->format('Y-m-d H:i'),
        ];
    }

    private function summarise(NotificationCampaign $c): array
    {
        $c->loadMissing(['program:id,code,title_ar,title_en', 'creator:id,name,name_ar']);
        $counts = DB::table('notifications')->where('campaign_id', $c->id)->selectRaw('count(*) as sent_count, count(seen_at) as seen_count, count(read_at) as read_count')->first();
        $sent = (int) ($counts->sent_count ?? 0);

        return [
            'id' => $c->id, 'event' => $c->event, 'kind' => $c->kind, 'audience' => $c->audience, 'title' => app()->getLocale() === 'en' ? $c->title_en : $c->title_ar, 'body' => app()->getLocale() === 'en' ? $c->body_en : $c->body_ar,
            'program' => $c->program ? ['id' => $c->program->id, 'code' => $c->program->code, 'title' => $c->program->translate('title')] : null,
            'by' => $c->creator?->displayName(), 'created_at' => $c->created_at->toIso8601String(), 'recipients' => $c->recipients,
            'sent' => $sent, 'seen' => (int) ($counts->seen_count ?? 0), 'read' => (int) ($counts->read_count ?? 0),
            'read_rate' => $sent ? round(((int) ($counts->read_count ?? 0)) / $sent * 100) : 0,
        ];
    }

    private function csv(string $name, array $head, $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($head, $rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $head);
            foreach ($rows as $row) {
                fputcsv($out, $row);
            }
            fclose($out);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
