<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['name_ar', 'name_en', 'type', 'cr_number', 'contacts', 'billing_address', 'partner_organization_id', 'status'])]
class EntityAccount extends Model
{
    use HasUuids;

    protected $table = 'entity_accounts';

    protected $casts = ['contacts' => 'array'];
}
