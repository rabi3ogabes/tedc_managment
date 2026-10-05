<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** A reason a trainee can give when withdrawing. */
#[Fillable(['code', 'label_ar', 'label_en', 'requires_attachment', 'is_active', 'sort_order'])]
class WithdrawalReason extends Model
{
    use HasUuids;

    protected function casts(): array
    {
        return ['requires_attachment' => 'boolean', 'is_active' => 'boolean'];
    }
}
