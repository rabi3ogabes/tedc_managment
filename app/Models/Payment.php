<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['order_id', 'gateway', 'gateway_ref', 'amount', 'status', 'raw', 'signature_valid', 'captured_at'])]
class Payment extends Model
{
    use HasUuids;

    protected $table = 'payments';

    protected $casts = ['raw' => 'array', 'amount' => 'float', 'signature_valid' => 'boolean', 'captured_at' => 'datetime'];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
