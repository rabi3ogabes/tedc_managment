<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasTranslations;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['code', 'name_en', 'name_ar', 'office', 'building', 'location', 'floor', 'capacity', 'area_m2', 'layout', 'layouts', 'facilities', 'is_accessible', 'status', 'notes', 'latitude', 'longitude'])]
class TrainingRoom extends Model
{
    use Auditable, HasTranslations, HasUuids;

    protected $casts = ['layouts' => 'array', 'is_accessible' => 'boolean', 'area_m2' => 'float'];

    /** Equipment as [{key, qty}]; legacy plain keys are normalised. */
    protected function facilities(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => collect(json_decode($value ?? '[]', true) ?: [])
                ->map(fn ($f) => is_array($f) ? ['key' => $f['key'], 'qty' => (int) ($f['qty'] ?? 1)] : ['key' => (string) $f, 'qty' => 1])
                ->values()->all(),
            set: fn ($value) => json_encode(array_values($value ?? [])),
        );
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(ProgramSession::class, 'training_room_id');
    }

    public function equipmentKeys(): array
    {
        return array_column($this->facilities, 'key');
    }

    /** Capacity when arranged in the given layout (falls back to the room capacity). */
    public function capacityFor(?string $layout): int
    {
        return (int) (($layout ? ($this->layouts[$layout] ?? null) : null) ?? $this->capacity);
    }
}
