<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * Where a request comes from, read from the headers the hosting edge adds (Vercel / Cloudflare) — no address is
 * stored and no outside service is called. Without such headers (local development) the place is unknown.
 */
class GeoLocator
{
    /** @return array{country: ?string, region: ?string, city: ?string, lat: ?float, lng: ?float} */
    public static function fromRequest(Request $request): array
    {
        $h = fn (string $name) => ($v = $request->header($name)) !== null && $v !== '' ? rawurldecode((string) $v) : null;

        $country = strtoupper((string) ($h('x-vercel-ip-country') ?? $h('cf-ipcountry') ?? $h('x-geo-country') ?? '')) ?: null;
        if ($country && (strlen($country) !== 2 || $country === 'XX' || $country === 'T1')) {
            $country = null;
        }
        $lat = $h('x-vercel-ip-latitude') ?? $h('cf-iplatitude') ?? $h('x-geo-lat');
        $lng = $h('x-vercel-ip-longitude') ?? $h('cf-iplongitude') ?? $h('x-geo-lng');

        return [
            'country' => $country,
            'region' => self::clip($h('x-vercel-ip-country-region') ?? $h('cf-region') ?? $h('x-geo-region')),
            'city' => self::clip($h('x-vercel-ip-city') ?? $h('cf-ipcity') ?? $h('x-geo-city')),
            'lat' => is_numeric($lat) && abs((float) $lat) <= 90 ? round((float) $lat, 4) : null,
            'lng' => is_numeric($lng) && abs((float) $lng) <= 180 ? round((float) $lng, 4) : null,
        ];
    }

    /** What the person uses: the mobile app, a desktop browser, a phone browser or a tablet. */
    public static function source(string $platform, ?string $userAgent): string
    {
        if ($platform === 'mobile') {
            return 'app';
        }
        $ua = (string) $userAgent;

        return match (true) {
            (bool) preg_match('/iPad|Tablet|Android(?!.*Mobile)/i', $ua) => 'tablet',
            (bool) preg_match('/iPhone|iPod|Android|Mobile/i', $ua) => 'mobile_web',
            default => 'desktop',
        };
    }

    private static function clip(?string $v): ?string
    {
        return $v === null ? null : mb_substr(trim($v), 0, 80);
    }
}
