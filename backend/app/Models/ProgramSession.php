<?php

namespace App\Models;

use App\Models\Concerns\HasTranslations;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

#[Fillable(['program_id', 'trainer_id', 'training_room_id', 'sequence', 'title_en', 'title_ar', 'description', 'starts_at', 'ends_at', 'location_text', 'online_url', 'activities', 'status', 'mode', 'online_platform', 'online_passcode', 'recording_url'])]
#[Hidden(['qr_secret'])]
class ProgramSession extends Model
{
    use HasTranslations, HasUuids;

    protected $casts = ['starts_at' => 'datetime', 'ends_at' => 'datetime', 'activities' => 'array'];

    protected static function booted(): void
    {
        static::creating(function (ProgramSession $session) {
            $session->qr_secret ??= Str::random(48);
        });
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }

    public function trainer(): BelongsTo
    {
        return $this->belongsTo(Trainer::class);
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(TrainingRoom::class, 'training_room_id');
    }

    public function attendance(): HasMany
    {
        return $this->hasMany(Attendance::class);
    }

    public function materials(): HasMany
    {
        return $this->hasMany(Material::class);
    }

    public function durationMinutes(): int
    {
        return (int) $this->starts_at->diffInMinutes($this->ends_at);
    }
}
