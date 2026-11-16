<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** A yearly time-boxed window in which proposals, requests and needs are collected. */
#[Fillable(['year', 'title_ar', 'title_en', 'opens_at', 'closes_at', 'status', 'plan_id', 'settings', 'closing_reminded_at'])]
class NeedsCycle extends Model
{
    use Auditable, HasUuids;

    public const DRAFT = 'draft';

    public const OPEN = 'open';

    public const CLOSED = 'closed';

    public const ANALYSED = 'analysed';

    /** Open now: status open and inside the window. */
    public function isOpen(): bool
    {
        return $this->status === self::OPEN && (! $this->opens_at || $this->opens_at->lte(now())) && (! $this->closes_at || $this->closes_at->gte(now()));
    }

    protected function casts(): array
    {
        return ['opens_at' => 'datetime', 'closes_at' => 'datetime', 'settings' => 'array', 'closing_reminded_at' => 'datetime'];
    }
}
