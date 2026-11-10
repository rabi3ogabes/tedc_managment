<?php

namespace App\Services\Kits;

use App\Models\KitActivity;
use App\Models\TrainingKit;
use App\Models\User;

/** Writes the kit activity feed. */
class KitLog
{
    public static function record(TrainingKit|string $kit, ?User $user, string $action, ?string $subjectType = null, ?string $subjectId = null, array $meta = []): KitActivity
    {
        return KitActivity::create([
            'kit_id' => $kit instanceof TrainingKit ? $kit->id : $kit,
            'user_id' => $user?->id,
            'action' => $action,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'meta' => $meta ?: null,
        ]);
    }
}
