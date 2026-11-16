<?php

namespace App\Social;

use App\Models\Post;
use App\Models\Space;
use App\Models\SpaceMember;
use App\Services\FeatureSettings;
use App\Services\NotificationService;
use Illuminate\Support\Facades\Cache;

/** One short summary a day of what is new in the spaces a person follows — instead of a notification for every post. */
class DailyDigest
{
    public function __construct(private readonly NotificationService $notifications, private readonly SocialSettings $settings, private readonly FeatureSettings $features) {}

    /** Runs from the scheduler every few minutes; sends once per day, at the configured hour. */
    public function run(bool $force = false): int
    {
        $s = $this->settings->all();
        if (! ($this->features->enabled('plc') || $this->features->enabled('forums')) || ! $s['daily_digest']) {
            return 0;
        }
        if (! $force && (now()->hour !== (int) $s['digest_hour'] || ! Cache::add('social-digest:'.now()->toDateString(), 1, now()->addDay()))) {
            return 0;
        }
        $since = now()->subDay();
        $fresh = Post::where('status', 'published')->where('created_at', '>=', $since)->selectRaw('space_id, count(*) as n')->groupBy('space_id')->pluck('n', 'space_id');
        if ($fresh->isEmpty()) {
            return 0;
        }
        $spaces = Space::whereIn('id', $fresh->keys())->whereNull('archived_at')->get()->keyBy('id');
        $sent = 0;
        SpaceMember::where('status', 'active')->where('notify', 'all')->whereIn('space_id', $fresh->keys())->get()->groupBy('user_id')->each(function ($memberships, $userId) use ($fresh, $spaces, &$sent) {
            $total = 0;
            $names = [];
            foreach ($memberships as $m) {
                $space = $spaces[$m->space_id] ?? null;
                if ($space) {
                    $total += $fresh[$m->space_id];
                    $names[] = $space;
                }
            }
            if ($total === 0) {
                return;
            }
            $first = $names[0];
            $this->notifications->send($userId, 'space.digest', ['ar' => "ملخص اليوم: {$total} جديد في مجتمعاتك", 'en' => "Today's digest: {$total} new in your communities"], ['ar' => $first->title_ar.(count($names) > 1 ? ' وغيرها' : ''), 'en' => $first->title_en.(count($names) > 1 ? ' and more' : '')], ['route' => '/communities'], raw: true);
            $sent++;
        });

        return $sent;
    }
}
