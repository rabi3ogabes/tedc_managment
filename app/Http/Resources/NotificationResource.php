<?php

namespace App\Http\Resources;

use App\Models\AppNotification;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin AppNotification */
class NotificationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $ar = app()->getLocale() === 'ar';

        return [
            'id' => $this->id,
            'type' => $this->type,
            'title' => $ar ? $this->title_ar : $this->title_en,
            'body' => $ar ? $this->body_ar : $this->body_en,
            'data' => $this->data ?? [],
            'read' => $this->read_at !== null,
            'read_at' => $this->read_at?->toIso8601String(),
            'seen' => $this->seen_at !== null,
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
