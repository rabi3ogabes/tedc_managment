<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['gateway', 'event_id', 'kind', 'signature_valid', 'outcome'])]
class PaymentEvent extends Model
{
    use HasUuids;

    protected $table = 'payment_events';

    protected $casts = ['signature_valid' => 'boolean'];
}
