<?php

namespace App\Console\Commands;

use App\Models\NotificationDelivery;
use App\Models\Role;
use App\Models\User;
use App\Services\Communication\AnnouncementLifecycle;
use App\Services\NotificationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class AnnouncementsTick extends Command
{
    protected $signature = 'tedc:announcements-tick';

    protected $description = 'Opens and closes announcement display windows, sends event reminders, and warns administrators about failed text messages';

    public function handle(AnnouncementLifecycle $lifecycle, NotificationService $notifications): int
    {
        $r = $lifecycle->tick();
        $this->info("Announcements: {$r['published']} published, {$r['expired']} expired, {$r['reminded']} reminders.");

        // One digest a day at most, when text messages failed in the last 24 hours.
        $failed = NotificationDelivery::where('channel', 'sms')->where('status', 'failed')->where('updated_at', '>=', now()->subDay())->count();
        if ($failed > 0 && Cache::add('sms.failed.digest', true, now()->addDay())) {
            $admins = User::where('status', 'active')->whereHas('roles', fn ($q) => $q->whereIn('slug', [Role::SUPER_ADMIN, Role::CENTER_ADMIN]))->pluck('id');
            $notifications->broadcast($admins, 'sms.delivery_failed', ['ar' => 'رسائل نصية لم تصل', 'en' => 'Text messages that did not arrive'],
                ['ar' => "فشل إرسال {$failed} رسالة نصية خلال آخر 24 ساعة.", 'en' => "{$failed} text messages failed in the last 24 hours."], ['route' => '/admin/communication?tab=deliveries&channel=sms&status=failed'], raw: true);
        }

        return self::SUCCESS;
    }
}
