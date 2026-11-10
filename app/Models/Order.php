<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['number', 'buyer_type', 'user_id', 'entity_account_id', 'items', 'subtotal', 'discount', 'vat', 'total', 'currency', 'discount_code', 'status', 'expires_at', 'paid_at', 'invoice_no', 'invoice_pdf_path'])]
class Order extends Model
{
    use HasUuids;

    protected $table = 'orders';

    protected $casts = ['items' => 'array', 'subtotal' => 'float', 'discount' => 'float', 'vat' => 'float', 'total' => 'float', 'expires_at' => 'datetime', 'paid_at' => 'datetime'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
