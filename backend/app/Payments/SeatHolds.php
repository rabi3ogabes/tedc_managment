<?php

namespace App\Payments;

use App\Models\SeatHold;
use App\Models\TrainingGroup;
use Illuminate\Support\Facades\Cache;

/**
 * Seats set aside while someone pays (cart, order) or until an entity's voucher is used. They count against the group's free seats, so two buyers cannot take the same last seat.
 */
class SeatHolds
{
    /** Seats held on a group right now. */
    public static function active(string $groupId): int
    {
        if (! Cache::rememberForever('seat_holds.any', fn () => SeatHold::exists())) {
            return 0;
        }

        return (int) SeatHold::where('group_id', $groupId)->where('expires_at', '>', now())->sum('quantity');
    }

    public static function activeForProgram(string $programId): int
    {
        if (! Cache::rememberForever('seat_holds.any', fn () => SeatHold::exists())) {
            return 0;
        }

        return (int) SeatHold::whereIn('group_id', TrainingGroup::where('program_id', $programId)->select('id'))->where('expires_at', '>', now())->sum('quantity');
    }

    public function place(string $groupId, string $kind, string $ownerId, int $quantity, \DateTimeInterface $expires): SeatHold
    {
        Cache::forever('seat_holds.any', true);

        return SeatHold::updateOrCreate(['kind' => $kind, 'owner_id' => $ownerId, 'group_id' => $groupId], ['quantity' => $quantity, 'expires_at' => $expires]);
    }

    public function release(string $kind, string $ownerId): void
    {
        SeatHold::where(['kind' => $kind, 'owner_id' => $ownerId])->delete();
    }

    /** Seats held by one owner on a group (so a buyer's own hold is not counted against them). */
    public function held(string $groupId, string $kind, string $ownerId): int
    {
        return (int) SeatHold::where(['group_id' => $groupId, 'kind' => $kind, 'owner_id' => $ownerId])->where('expires_at', '>', now())->sum('quantity');
    }

    /** Expired holds are removed. @return int removed */
    public function prune(): int
    {
        $n = SeatHold::where('expires_at', '<=', now())->delete();
        Cache::forget('seat_holds.any');

        return $n;
    }
}
