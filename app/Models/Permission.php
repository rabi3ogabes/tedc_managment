<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable(['slug', 'name_en', 'name_ar', 'group'])]
class Permission extends Model
{
    use HasUuids;

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class);
    }
}
