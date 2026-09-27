<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your settings for cross-origin resource sharing
    | or "CORS". This determines what cross-origin operations may execute
    | in web browsers. You are free to adjust these settings as needed.
    |
    | To learn more: https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => array_filter(explode(',', env('CORS_ALLOWED_ORIGINS', 'http://localhost:5173,http://localhost:4173'))),

    // e.g. CORS_ALLOWED_ORIGIN_PATTERNS=#^https://tedc-[a-z0-9-]+\.vercel\.app$# for Vercel preview deployments.
    'allowed_origins_patterns' => array_filter(explode(',', (string) env('CORS_ALLOWED_ORIGIN_PATTERNS', ''))),

    'allowed_headers' => ['*'],

    'exposed_headers' => ['Content-Language', 'Content-Disposition'],

    'max_age' => 0,

    'supports_credentials' => false,

];
