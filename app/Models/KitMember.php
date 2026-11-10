<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['kit_id', 'user_id', 'role'])]
class KitMember extends Model
{
    use HasUuids;

    /** developer = معد الحقيبة, qa = فريق ضمان الجودة. */
    public const DEVELOPER = 'developer';

    public const QA = 'qa';

    public const REVIEWER = 'reviewer';

    public const VIEWER = 'viewer';

    public const ROLES = [self::DEVELOPER, self::QA, self::REVIEWER, self::VIEWER];

    public function kit(): BelongsTo
    {
        return $this->belongsTo(TrainingKit::class, 'kit_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
