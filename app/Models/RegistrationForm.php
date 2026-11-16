<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A shareable public form for people outside the Ministry. */
#[Fillable(['slug', 'title_ar', 'title_en', 'intro_ar', 'intro_en', 'audience', 'fields', 'conditions', 'opens_at', 'closes_at', 'is_active'])]
class RegistrationForm extends Model
{
    use Auditable, HasUuids;

    protected function casts(): array
    {
        return ['fields' => 'array', 'conditions' => 'array', 'opens_at' => 'datetime', 'closes_at' => 'datetime', 'is_active' => 'boolean'];
    }

    public function requests(): HasMany
    {
        return $this->hasMany(RegistrationRequest::class, 'form_id');
    }
}
