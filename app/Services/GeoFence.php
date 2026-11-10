<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\ProgramSession;

/**
 * Presence check for attendance: the participant must be at the session's venue (the room's coordinates)
 * when they scan. The device position is compared with the venue with a small allowance for GPS accuracy;
 * positions that are too imprecise or come from a mock-location app are refused.
 */
class GeoFence
{
    private const EARTH_RADIUS_M = 6371000;

    /** Extra metres granted for GPS noise, never more than this. */
    private const MAX_TOLERANCE_M = 50;

    public function __construct(private readonly AttendanceSettings $settings) {}

    /** @return array{latitude: float, longitude: float, name: string}|null */
    public function venue(ProgramSession $session): ?array
    {
        $room = $session->room;
        if (! $room || $room->latitude === null || $room->longitude === null) {
            return null;
        }

        return ['latitude' => (float) $room->latitude, 'longitude' => (float) $room->longitude, 'name' => $room->translate('name')];
    }

    /**
     * @param  array{latitude?: float|string|null, longitude?: float|string|null, accuracy?: float|int|null, mocked?: bool|null}|null  $location
     * @return array{status: string, latitude: ?float, longitude: ?float, accuracy_m: ?int, distance_m: ?int}
     *
     * @throws BusinessRuleException
     */
    public function verify(ProgramSession $session, ?array $location): array
    {
        $rules = $this->settings->all();
        $empty = ['latitude' => null, 'longitude' => null, 'accuracy_m' => null, 'distance_m' => null];

        if (! $rules['geofence_enabled']) {
            return ['status' => 'disabled'] + $empty;
        }
        $venue = $this->venue($session);
        if (! $venue) {
            // Online sessions and rooms without coordinates cannot be checked.
            return ['status' => $session->online_url && ! $session->room ? 'online' : 'no_venue'] + $empty;
        }

        $lat = $location['latitude'] ?? null;
        $lng = $location['longitude'] ?? null;
        if (! is_numeric($lat) || ! is_numeric($lng)) {
            throw new BusinessRuleException(__('messages.attendance.location_required', ['venue' => $venue['name']]), 'location_required', ['venue' => $venue['name']]);
        }
        if (! empty($location['mocked'])) {
            throw new BusinessRuleException(__('messages.attendance.mock_location'), 'mock_location');
        }

        $accuracy = isset($location['accuracy']) && is_numeric($location['accuracy']) ? (int) round($location['accuracy']) : null;
        if ($accuracy !== null && $accuracy > $rules['max_accuracy_m']) {
            throw new BusinessRuleException(__('messages.attendance.low_accuracy', ['max' => $rules['max_accuracy_m']]), 'low_accuracy', ['accuracy' => $accuracy, 'max' => $rules['max_accuracy_m']]);
        }

        $distance = (int) round(self::distance((float) $lat, (float) $lng, $venue['latitude'], $venue['longitude']));
        $allowed = $rules['radius_m'] + min($accuracy ?? 0, self::MAX_TOLERANCE_M);
        if ($distance > $allowed) {
            throw new BusinessRuleException(
                __('messages.attendance.outside_venue', ['venue' => $venue['name'], 'distance' => $distance, 'radius' => $rules['radius_m']]),
                'outside_venue',
                ['distance' => $distance, 'radius' => $rules['radius_m'], 'venue' => $venue['name']],
            );
        }

        return ['status' => 'verified', 'latitude' => (float) $lat, 'longitude' => (float) $lng, 'accuracy_m' => $accuracy, 'distance_m' => $distance];
    }

    /** Great-circle distance in metres (haversine). */
    public static function distance(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return 2 * self::EARTH_RADIUS_M * asin(min(1, sqrt($a)));
    }
}
