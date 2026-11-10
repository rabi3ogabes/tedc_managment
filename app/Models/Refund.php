<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['order_id', 'payment_id', 'items', 'amount', 'reason', 'status', 'gateway_ref', 'credit_note_no', 'credit_note_pdf_path', 'decision_note', 'requested_by', 'approved_by'])]
class Refund extends Model
{
    use HasUuids;

    protected $table = 'refunds';

    protected $casts = ['items' => 'array', 'amount' => 'float'];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
