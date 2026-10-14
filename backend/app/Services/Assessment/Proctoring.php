<?php

namespace App\Services\Assessment;

use App\Models\Assessment;
use App\Services\FeatureSettings;

/**
 * An assessment's proctoring rules with their defaults (EXM-10). Face check is face *presence* only: the browser counts
 * faces and notices a face turned away; nothing identifies a person, and no image leaves the device for it.
 */
class Proctoring
{
    /** Conservative defaults: flags go to people, and nothing ends an attempt unless the assessment says so. */
    public const FACE_DEFAULTS = [
        'face_check' => false,
        'face_absent_seconds' => 10,      // how long without a face counts as one event
        'face_absent_limit' => 3,         // events before the flag
        'multiple_faces_limit' => 1,
        'looking_away_limit' => 5,
        'face_check_interval_ms' => 1000, // one frame a second (500–3000)
        'face_fallback' => 'centre',      // no camera or no consent: sit in the centre, or proceed flagged
    ];

    /** Events the browser may send about the face check. */
    public const FACE_EVENTS = ['face_absent', 'multiple_faces', 'looking_away', 'face_check_degraded', 'camera_off'];

    /** @return array<string, mixed> the stored rules over the defaults */
    public static function config(Assessment $a): array
    {
        return array_replace(self::FACE_DEFAULTS, (array) ($a->proctoring ?? []));
    }

    /** Whether this assessment asks for the face check now: proctoring and the switch are on, and the feature is enabled. */
    public static function faceCheck(Assessment $a): bool
    {
        $cfg = (array) ($a->proctoring ?? []);

        return ! empty($cfg['enabled']) && ! empty($cfg['face_check']) && app(FeatureSettings::class)->enabled('proctoring');
    }
}
