<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasTranslations;
use App\Services\CalendarService;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** A weekday that is off every week (on top of the configured weekend), with a label; events may still be allowed on it. */
#[Fillable(['weekday', 'title_ar', 'title_en', 'allow_events', 'created_by'])]
class CalendarWeeklyRule extends Model
{
    use Auditable, HasTranslations, HasUuids;

    protected $casts = ['weekday' => 'integer', 'allow_events' => 'boolean'];

    protected static function booted(): void
    {
        static::saved(fn () => CalendarService::forgetWeeklyRules());
        static::deleted(fn () => CalendarService::forgetWeeklyRules());
    }
}
