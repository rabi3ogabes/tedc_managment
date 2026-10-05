<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['employee_id', 'path_id', 'level_no', 'licence_no', 'issued_at', 'expires_at', 'status', 'source', 'synced_at', 'reminded'])]
class ProfessionalLicence extends Model
{
    use Auditable, HasUuids;

    protected $casts = ['issued_at' => 'date', 'expires_at' => 'date', 'synced_at' => 'datetime', 'reminded' => 'array'];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function path(): BelongsTo
    {
        return $this->belongsTo(CareerPath::class, 'path_id');
    }
}
