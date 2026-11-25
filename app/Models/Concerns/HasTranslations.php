<?php

namespace App\Models\Concerns;

use Illuminate\Support\Facades\App;

/**
 * Resolves bilingual `<attr>_ar` / `<attr>_en` columns according to the active locale.
 * Arabic is the primary language of the platform.
 */
trait HasTranslations
{
    public function translate(string $attribute, ?string $locale = null): ?string
    {
        $locale ??= App::getLocale();
        $primary = $this->getAttribute("{$attribute}_{$locale}");

        return $primary ?: $this->getAttribute("{$attribute}_ar") ?: $this->getAttribute("{$attribute}_en");
    }
}
