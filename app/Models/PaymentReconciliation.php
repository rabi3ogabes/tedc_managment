<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['day', 'matched', 'fixed', 'mismatches'])]
class PaymentReconciliation extends Model
{
    use HasUuids;

    protected $table = 'payment_reconciliations';

    protected $casts = ['mismatches' => 'array', 'day' => 'date'];
}
