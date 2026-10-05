<?php

namespace App\Http\Resources;

use App\Models\TrainingRoom;
use App\Services\RoomService;
use App\Support\RoomCatalog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin TrainingRoom */
class RoomResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $locale = app()->getLocale() === 'en' ? 1 : 0;

        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->translate('name'),
            'name_ar' => $this->name_ar,
            'name_en' => $this->name_en,
            'office' => $this->office,
            'building' => $this->building,
            'location' => $this->location,
            'floor' => $this->floor,
            'capacity' => $this->capacity, 'effective_capacity' => app(RoomService::class)->effectiveCapacity($this->resource), 'place_id' => $this->place_id, 'building_id' => $this->building_id,
            'area_m2' => $this->area_m2,
            'layout' => $this->layout,
            'layouts' => collect($this->layouts ?? [])->map(fn ($cap, $key) => [
                'key' => $key, 'label' => RoomCatalog::LAYOUTS[$key][$locale] ?? $key, 'capacity' => (int) $cap,
            ])->values(),
            'equipment' => collect($this->facilities)->map(fn ($f) => [
                'key' => $f['key'], 'qty' => $f['qty'], 'label' => RoomCatalog::EQUIPMENT[$f['key']][$locale] ?? $f['key'],
            ])->values(),
            'is_accessible' => $this->is_accessible,
            'latitude' => $this->latitude !== null ? (float) $this->latitude : null,
            'longitude' => $this->longitude !== null ? (float) $this->longitude : null,
            'status' => $this->status,
            'activity' => $this->when(array_key_exists('activity', $this->resource->getAttributes()) || $this->resource->offsetExists('activity'), fn () => $this->resource->getAttribute('activity')),
            'notes' => $this->notes,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'sessions_count' => $this->whenCounted('sessions'),
            'upcoming_sessions_count' => $this->whenCounted('upcoming_sessions'),
        ];
    }
}
