<?php

/*
|--------------------------------------------------------------------------
| TEDC Training Management & Impact Platform
|--------------------------------------------------------------------------
*/

return [

    'name' => [
        'ar' => env('TEDC_NAME_AR', 'مركز التدريب والتطوير التربوي'),
        'en' => env('TEDC_NAME_EN', 'Training & Educational Development Center'),
    ],

    'web_url' => env('TEDC_WEB_URL', 'http://localhost:5173'),

    /*
    | Authentication. "supabase" delegates credential checks to Supabase Auth (GoTrue)
    | and validates the Supabase-issued JWT on every request. "local" validates
    | passwords stored in `users` and signs HS256 tokens with the same secret, which
    | keeps local development and the test-suite independent from Supabase.
    */
    'auth' => [
        'driver' => env('TEDC_AUTH_DRIVER', 'local'),
        // Empty SUPABASE_JWT_SECRET falls back to APP_KEY (local driver).
        'jwt_secret' => env('SUPABASE_JWT_SECRET') ?: env('APP_KEY'),
        'jwt_audience' => env('TEDC_JWT_AUDIENCE', 'authenticated'),
        'token_ttl' => (int) env('TEDC_TOKEN_TTL', 3600),
        'refresh_ttl' => (int) env('TEDC_REFRESH_TTL', 60 * 60 * 24 * 14),
        'auto_provision' => (bool) env('TEDC_AUTO_PROVISION_USERS', true),
    ],

    'supabase' => [
        'url' => env('SUPABASE_URL'),
        // New key format (sb_publishable_ / sb_secret_) or legacy JWT keys (anon / service_role).
        'anon_key' => env('SUPABASE_PUBLISHABLE_KEY') ?: env('SUPABASE_ANON_KEY'),
        'service_role_key' => env('SUPABASE_SECRET_KEY') ?: env('SUPABASE_SERVICE_ROLE_KEY'),
        'jwks_url' => env('SUPABASE_JWKS_URL'),
        'jwks_cache_seconds' => 3600,
        'buckets' => [
            'materials' => env('SUPABASE_BUCKET_MATERIALS', 'materials'),
            'submissions' => env('SUPABASE_BUCKET_SUBMISSIONS', 'submissions'),
            'certificates' => env('SUPABASE_BUCKET_CERTIFICATES', 'certificates'),
            'documents' => env('SUPABASE_BUCKET_DOCUMENTS', 'documents'),
            'public' => env('SUPABASE_BUCKET_PUBLIC', 'public-assets'),
        ],
        'signed_url_ttl' => 600,
    ],

    // "supabase" stores files in Supabase Storage; "local" uses the Laravel private disk.
    'storage_driver' => env('TEDC_STORAGE_DRIVER', 'local'),

    'attendance' => [
        // Dynamic QR codes rotate every N seconds; one previous window is accepted to absorb clock drift.
        'qr_rotation_seconds' => (int) env('TEDC_QR_ROTATION', 30),
        'check_in_opens_minutes_before' => 30,
        'late_after_minutes' => 15,
    ],

    'certificates' => [
        'prefix' => env('TEDC_CERT_PREFIX', 'TEDC'),
    ],

    // Weights (sum = 100) used for the Training Impact Score.
    'impact_weights' => [
        'attendance' => 15,
        'learning' => 25,
        'application' => 25,
        'supervisor' => 20,
        'follow_up' => 15,
    ],

    'ai' => [
        'provider' => env('TEDC_AI_PROVIDER', 'anthropic'),
        'api_key' => env('ANTHROPIC_API_KEY'),
        'model' => env('TEDC_AI_MODEL', 'claude-opus-5'),
        'max_tokens' => (int) env('TEDC_AI_MAX_TOKENS', 16000),
        'timeout' => 120,
    ],
];
