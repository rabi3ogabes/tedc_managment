<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasTranslations;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** An organization that supplies trainers: Qatar Foundation, Ministry of Public Health, a university ... */
#[Fillable(['name_ar', 'name_en', 'type', 'country', 'contact_name', 'email', 'phone', 'website', 'notes', 'status'])]
class PartnerOrganization extends Model
{
    use Auditable, HasTranslations, HasUuids;

    public const TYPES = ['ministry', 'foundation', 'university', 'health', 'private', 'ngo', 'institution'];

    public function trainers(): HasMany
    {
        return $this->hasMany(Trainer::class, 'partner_id');
    }
}
