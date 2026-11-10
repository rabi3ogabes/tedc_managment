<?php

namespace App\Services\Notifications;

use App\Models\NotificationCampaign;
use App\Models\NotificationTemplate;
use App\Models\Program;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Support\Collection;

/** A notification an administrator sends to a group (e.g. the trainees of a program), tracked per recipient. */
class NotificationCampaigns
{
    public function __construct(private readonly NotificationService $notifications, private readonly NotificationTemplates $templates) {}

    /**
     * @param  Collection<int, string>  $userIds
     * @param  array{title_ar?: ?string, title_en?: ?string, body_ar?: ?string, body_en?: ?string}  $override  wording typed by the administrator
     */
    public function send(string $event, string $kind, ?Program $program, Collection $userIds, ?User $by, array $override = [], string $audience = 'trainees'): NotificationCampaign
    {
        $tpl = NotificationTemplate::where('event', $event)->first();
        $vars = $program ? $this->templates->programVariables($program) : ['ar' => [], 'en' => []];
        $text = fn (string $field, string $lang) => $this->templates->render((string) (($override["{$field}_{$lang}"] ?? null) ?: ($tpl?->{"{$field}_{$lang}"} ?? '')), $vars[$lang]);

        $title = ['ar' => $text('title', 'ar'), 'en' => $text('title', 'en')];
        $body = ['ar' => $text('body', 'ar'), 'en' => $text('body', 'en')];

        $campaign = NotificationCampaign::create([
            'event' => $event, 'kind' => $kind, 'program_id' => $program?->id, 'audience' => $audience,
            'title_ar' => $title['ar'], 'title_en' => $title['en'], 'body_ar' => $body['ar'], 'body_en' => $body['en'], 'created_by' => $by?->id,
        ]);

        $count = $this->notifications->broadcast(
            $userIds->unique()->values(), $event, $title, $body,
            ['program_id' => $program?->id, 'route' => $program ? '/my-program/'.$program->id : '/notifications', 'campaign_id' => $campaign->id],
            $campaign->id, force: true, raw: true,
        );
        $campaign->update(['recipients' => $count]);

        return $campaign;
    }
}
