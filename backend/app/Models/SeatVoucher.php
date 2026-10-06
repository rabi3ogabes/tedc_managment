<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['order_id', 'entity_account_id', 'group_id', 'code', 'assigned_employee_id', 'assigned_email', 'status', 'registration_id', 'expires_at', 'reminded_at'])]
class SeatVoucher extends Model
{
    use HasUuids;

    protected $table = 'seat_vouchers';

    protected $casts = ['expires_at' => 'datetime', 'reminded_at' => 'datetime'];

    public function group(): BelongsTo
    {
        return $this->belongsTo(TrainingGroup::class, 'group_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'assigned_employee_id');
    }
}
