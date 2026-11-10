<?php

namespace App\Support;

use App\Services\FeatureSettings;

/** `Features::enabled('payments')` — the one place the rest of the code asks whether a feature is on. */
class Features
{
    public static function enabled(string $key): bool
    {
        return app(FeatureSettings::class)->enabled($key);
    }
}
