<?php

namespace App\Services;

use App\Models\Announcement;
use App\Models\Employee;
use App\Models\Registration;
use App\Models\Role;
use App\Models\User;
use App\Services\Channels\NotificationChannels;
use App\Services\Notifications\AudienceResolver;
use Illuminate\Support\Collection;

/**
 * Communication Center: publishes announcements (text, files, videos, audio, links) and events to all employees, specific schools,
 * specific programs or roles, or to an audience built from several filters. The in-app copy is always created; push and e-mail are chosen per announcement.
 */
class CommunicationService
{
    public function __construct(private readonly NotificationService $notifications, private readonly NotificationChannels $channels, private readonly AudienceResolver $audiences) {}

    public function publish(Announcement $announcement): int
    {
        $announcement->update(['published_at' => $announcement->published_at ?? now(), 'status' => 'published']);

        $wanted = array_values(array_filter([$announcement->notify_push ? 'push' : null, $announcement->notify_email ? 'email' : null]));
        $this->channels->choose($wanted);
        try {
            return $this->notifications->broadcast(
                $this->recipients($announcement),
                'announcement',
                ['ar' => $announcement->title_ar, 'en' => $announcement->title_en],
                ['ar' => mb_substr(strip_tags((string) $announcement->body_ar), 0, 180), 'en' => mb_substr(strip_tags((string) $announcement->body_en), 0, 180)],
                ['announcement_id' => $announcement->id, 'announcement_type' => $announcement->type],
            );
        } finally {
            $this->channels->choose(null);
        }
    }

    /** @return Collection<int, string> */
    public function recipients(Announcement $announcement): Collection
    {
        $targets = $announcement->target_ids ?? [];

        return match ($announcement->audience) {
            'filter' => $this->audiences->resolve($announcement->audience_filter, $announcement->author),
            'schools' => Employee::whereIn('school_id', $targets)->pluck('user_id'),
            'programs' => Employee::whereIn('id', Registration::whereIn('program_id', $targets)
                ->whereIn('status', [Registration::STATUS_APPROVED, Registration::STATUS_COMPLETED, Registration::STATUS_PENDING])
                ->select('employee_id'))->pluck('user_id'),
            'roles' => User::whereHas('roles', fn ($q) => $q->whereIn('slug', $targets))->where('status', 'active')->pluck('id'),
            default => User::where('status', 'active')->whereHas('roles', fn ($q) => $q->where('slug', '!=', Role::SUPER_ADMIN))->pluck('id'),
        };
    }
}
