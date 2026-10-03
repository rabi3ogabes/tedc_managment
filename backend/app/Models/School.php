<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasTranslations;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['code', 'name_en', 'name_ar', 'type', 'gender', 'stage', 'region', 'district', 'latitude', 'longitude', 'phone', 'email', 'logo_path', 'is_partner', 'status', 'moe_no', 'source', 'address', 'website', 'curriculum', 'synced_at'])]
class School extends Model
{
    use Auditable, HasFactory, HasTranslations, HasUuids;

    public const TYPES = ['government', 'private', 'community', 'international'];

    public const STAGES = ['kindergarten', 'primary', 'preparatory', 'secondary', 'multi'];

    public const REGIONS = ['doha', 'al_rayyan', 'al_wakrah', 'al_khor', 'al_shamal', 'umm_salal', 'al_daayen', 'al_shahaniya'];

    protected $casts = ['is_partner' => 'boolean', 'latitude' => 'float', 'longitude' => 'float', 'synced_at' => 'datetime'];

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }

    public function departments(): HasMany
    {
        return $this->hasMany(Department::class);
    }

    public function trainingNeeds(): HasMany
    {
        return $this->hasMany(TrainingNeed::class);
    }
}
