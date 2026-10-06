<?php

namespace App\Services\Communication;

use App\Models\Announcement;
use App\Models\MinistryExport;
use App\Models\Role;
use App\Models\User;
use App\Services\NotificationService;
use App\Support\Supabase;
use Illuminate\Support\Facades\Http;
use Throwable;

/** Pushes news and events flagged for the Ministry website to its endpoint, with a few retries and a log; failures reach the administrators once. */
class MinistryExporter
{
    private const RETRY_MINUTES = [15, 60, 360];

    public function __construct(private readonly MinistrySiteSettings $settings, private readonly FeedBuilder $feeds, private readonly NotificationService $notifications) {}

    /** Puts an item in the queue; it goes out on the next run. */
    public function queue(Announcement $a): ?MinistryExport
    {
        if (! $this->settings->all()['auto_export'] || ! $this->settings->all()['enabled']) {
            return null;
        }

        return $this->enqueue($a);
    }

    /** The manual "export now": queued regardless of the automatic switch. */
    public function enqueue(Announcement $a): MinistryExport
    {
        $a->update(['export_to_ministry' => true]);

        return MinistryExport::updateOrCreate(['announcement_id' => $a->id], ['status' => 'queued', 'attempts' => 0, 'next_attempt_at' => now(), 'last_error' => null]);
    }

    /** @return array{sent: int, failed: int} */
    public function run(): array
    {
        $out = ['sent' => 0, 'failed' => 0];
        if (! $this->settings->ready()) {
            return $out;
        }
        MinistryExport::where('status', 'queued')->where(fn ($q) => $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))->with('announcementRow')->limit(50)->get()
            ->each(function (MinistryExport $e) use (&$out) {
                $a = $e->announcementRow;
                if (! $a) {
                    $e->delete();

                    return;
                }
                $error = $this->push($a);
                $attempts = $e->attempts + 1;
                if ($error === null) {
                    $e->update(['status' => 'sent', 'attempts' => $attempts, 'sent_at' => now(), 'last_error' => null, 'next_attempt_at' => null]);
                    $a->update(['exported_at' => now()]);
                    $out['sent']++;

                    return;
                }
                if ($attempts > count(self::RETRY_MINUTES)) {
                    $e->update(['status' => 'failed', 'attempts' => $attempts, 'last_error' => $error, 'next_attempt_at' => null]);
                    $this->alert($a, $error);
                    $out['failed']++;

                    return;
                }
                $e->update(['attempts' => $attempts, 'last_error' => $error, 'next_attempt_at' => now()->addMinutes(self::RETRY_MINUTES[$attempts - 1])]);
            });

        return $out;
    }

    /** null when the Ministry site accepted the item, else the reason. */
    public function push(Announcement $a): ?string
    {
        $s = $this->settings->all();
        try {
            $request = Http::withOptions(['verify' => Supabase::caBundle()])->timeout(20)->acceptJson();
            if ($key = $this->settings->apiKey()) {
                $request = $request->withHeaders([$s['auth_header'] ?: 'X-Api-Key' => $key]);
            }
            $response = $request->post($s['endpoint'], ['source' => $s['site_name'] ?: 'tedc', 'item' => $this->feeds->item($a)]);

            return $response->successful() ? null : 'HTTP '.$response->status().' '.mb_substr(strip_tags((string) $response->body()), 0, 160);
        } catch (Throwable $e) {
            return mb_substr($e->getMessage(), 0, 240);
        }
    }

    private function alert(Announcement $a, string $error): void
    {
        $admins = User::where('status', 'active')->whereHas('roles', fn ($r) => $r->whereIn('slug', [Role::SUPER_ADMIN, Role::CENTER_ADMIN]))->pluck('id');
        $this->notifications->broadcast($admins, 'ministry_export.failed',
            ['ar' => 'تعذّر تصدير الخبر إلى موقع الوزارة', 'en' => 'Could not export an item to the Ministry website'],
            ['ar' => $a->title_ar.' — '.$error, 'en' => $a->title_en.' — '.$error], ['announcement_id' => $a->id, 'route' => '/admin/communication'], raw: true);
    }
}
