<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasTranslations;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * A marked day on the training calendar.
 *
 * vacation - official or center holiday, everyone is off
 * exam     - school exam day, no training
 * normal   - regular working day that is closed for training unless it is approved
 */
#[Fillable(['date', 'type', 'title_ar', 'title_en', 'notes', 'created_by'])]
class CalendarDay extends Model
{
    use Auditable, HasTranslations, HasUuids;

    public const VACATION = 'vacation';

    public const EXAM = 'exam';

    public const NORMAL = 'normal';

    public const TYPES = [self::VACATION, self::EXAM, self::NORMAL];

    protected $casts = ['date' => 'date:Y-m-d'];
}
