<?php

/*
|--------------------------------------------------------------------------
| TEDC Training Management & Impact Platform
|--------------------------------------------------------------------------
*/

return [

    'name' => [
        'ar' => env('TEDC_NAME_AR', 'شاهين - مشروع بوابة التدريب'),
        'en' => env('TEDC_NAME_EN', 'Shaheen – Training Portal Project'),
    ],

    // Bearer token for /api/v1/system/* (Vercel Cron sends it automatically). Empty disables the endpoints.
    'cron_secret' => env('CRON_SECRET'),

    /*
    | Training calendar. Weekend days use Carbon's numbering (0 = Sunday ... 5 = Friday, 6 = Saturday);
    | Qatar's weekend is Friday and Saturday. Weekends are closed for training unless approved.
    */
    'calendar' => [
        'weekend' => array_map('intval', array_filter(explode(',', (string) env('TEDC_WEEKEND_DAYS', '5,6')), 'strlen')),
    ],

    // Share of API requests whose duration is recorded for the KPI dashboard (0 switches it off).
    'kpi' => ['sample_rate' => (float) env('TEDC_KPI_SAMPLE_RATE', 0.25)],

    // Reports with more rows than this are produced by the minute job instead of while the person waits.
    'reports' => ['inline_rows' => (int) env('TEDC_REPORT_INLINE_ROWS', 2000)],

    // Integrations: back-off between retries (ms, 0 in tests) and how long call logs are kept.
    'integrations' => ['backoff_ms' => (int) env('TEDC_INTEGRATION_BACKOFF_MS', 300), 'log_days' => 30],

    'web_url' => env('TEDC_WEB_URL') ?: env('APP_URL', 'http://localhost:5173'),

    // A production deployment that is a demonstration site (sample data, demo accounts on the sign-in screen). Set once in the
    // hosting environment, never from a screen; APP_ENV stays production so every other live-system guard still applies.
    'demo_site' => filter_var(env('TEDC_DEMO_SITE', false), FILTER_VALIDATE_BOOL),

    // Single sign-on in the app: the only addresses a finished sign-in may be sent to (comma separated). The app's own scheme works without
    // a domain; with a verified domain add its app-link address (docs/security/identity-methods.md). Nothing else is ever accepted.
    'mobile' => ['sso_redirects' => array_values(array_filter(array_map('trim', explode(',', (string) env('TEDC_MOBILE_SSO_REDIRECTS', 'qa.edu.tedc.app://sso')))))],

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
        // Optional CA certificate bundle (cacert.pem) for HTTPS calls to Supabase. Empty = system store,
        // or the bundled Mozilla CA list when PHP has none (fixes "cURL error 60" on Windows).
        'ca_bundle' => env('SUPABASE_CA_BUNDLE'),
        'jwks_cache_seconds' => 3600,
        'buckets' => [
            'materials' => env('SUPABASE_BUCKET_MATERIALS', 'materials'),
            'submissions' => env('SUPABASE_BUCKET_SUBMISSIONS', 'submissions'),
            'certificates' => env('SUPABASE_BUCKET_CERTIFICATES', 'certificates'),
            'documents' => env('SUPABASE_BUCKET_DOCUMENTS', 'documents'),
            'public' => env('SUPABASE_BUCKET_PUBLIC', 'public-assets'),
            // Evidence, exceptions and content packages live in the existing private "documents" bucket (no new bucket to create).
            'evidence' => env('SUPABASE_BUCKET_EVIDENCE', 'documents'),
            'exceptions' => env('SUPABASE_BUCKET_EXCEPTIONS', 'documents'),
            'packages' => env('SUPABASE_BUCKET_PACKAGES', 'documents'),
        ],
        'signed_url_ttl' => 600,
    ],

    // "azure" stores files in Azure Blob Storage (private containers, SAS links), "supabase" in Supabase Storage, "local" on the Laravel private disk.
    'storage_driver' => env('TEDC_STORAGE_DRIVER', 'local'),

    'realtime' => [
        // supabase | sse | polling — how clients learn about new notifications at once (see RealtimeController).
        'driver' => env('TEDC_REALTIME', 'polling'),
        'window' => (int) env('TEDC_REALTIME_WINDOW', 25),
    ],

    'azure' => [
        'account' => env('AZURE_STORAGE_ACCOUNT'),
        'key' => env('AZURE_STORAGE_KEY'),                          // development / Azurite only; production uses the managed identity
        'endpoint' => env('AZURE_STORAGE_ENDPOINT'),                // e.g. http://127.0.0.1:10000/devstoreaccount1 for Azurite
        'managed_identity' => (bool) env('AZURE_STORAGE_MANAGED_IDENTITY', false),
        'client_id' => env('AZURE_CLIENT_ID'),
        'api_version' => '2022-11-02',
        'sas_ttl' => 600,
    ],

    'attendance' => [
        // Dynamic QR codes rotate every N seconds; one previous window is accepted to absorb clock drift.
        'qr_rotation_seconds' => (int) env('TEDC_QR_ROTATION', 30),
        'check_in_opens_minutes_before' => 30,
        'late_after_minutes' => 15,
        // Location check: a participant must be near the room of the session to check in / out.
        'geofence' => [
            'enabled' => (bool) env('TEDC_GEOFENCE', true),
            'radius_m' => (int) env('TEDC_GEOFENCE_RADIUS', 150),
            'max_accuracy_m' => (int) env('TEDC_GEOFENCE_MAX_ACCURACY', 150),
        ],
    ],

    'certificates' => [
        'prefix' => env('TEDC_CERT_PREFIX', 'TEDC'),
        // Signatories printed at the foot of the certificates (name / title).
        'signers' => [
            ['name_ar' => env('TEDC_SIGNER1_NAME_AR', 'إيمان سلمان المهندي'), 'title_ar' => env('TEDC_SIGNER1_TITLE_AR', 'مدير مركز التدريب والتطوير')],
            ['name_ar' => env('TEDC_SIGNER2_NAME_AR', 'عبد الرحمن جاسم الباكر'), 'title_ar' => env('TEDC_SIGNER2_TITLE_AR', 'رئيس قسم التدريب التربوي')],
        ],
    ],

    // Weights (sum = 100) used for the Training Impact Score.
    'impact_weights' => [
        'attendance' => 15,
        'learning' => 25,
        'application' => 25,
        'supervisor' => 20,
        'follow_up' => 15,
    ],

    /*
    | Training Kit Studio. Image generation providers: "auto" (OpenAI when a key is set, else Claude-drawn
    | SVG, else an abstract placeholder), "openai", "svg" or "placeholder".
    */
    'kits' => [
        'image_provider' => env('TEDC_IMAGE_PROVIDER', 'auto'),
        'openai_key' => env('OPENAI_API_KEY'),
        'openai_image_model' => env('TEDC_OPENAI_IMAGE_MODEL', 'gpt-image-1'),
        'openai_tts_model' => env('TEDC_OPENAI_TTS_MODEL', 'tts-1'),
        'max_upload_mb' => (int) env('TEDC_KIT_MAX_UPLOAD_MB', 100),
        'asset_url_ttl' => 4 * 3600,
        'autosnapshot_minutes' => 10,
    ],

    'ai' => [
        'provider' => env('TEDC_AI_PROVIDER', 'anthropic'),
        'api_key' => env('ANTHROPIC_API_KEY'),
        'model' => env('TEDC_AI_MODEL', 'claude-opus-5'),
        'max_tokens' => (int) env('TEDC_AI_MAX_TOKENS', 16000),
        'timeout' => 120,
    ],

    // Where the Ministry's licensed Lusail typeface goes (UX-03; see docs/FONTS.md). The web reads WOFF2, PDFs and the app read TTF.
    'fonts' => [
        'web' => env('TEDC_FONTS_WEB_DIR', base_path('../web/public/fonts/lusail')),
        'pdf' => env('TEDC_FONTS_PDF_DIR', resource_path('fonts')),
        'app' => env('TEDC_FONTS_APP_DIR', base_path('../mobile/assets/fonts')),
        'pubspec' => env('TEDC_FONTS_PUBSPEC', base_path('../mobile/pubspec.yaml')),
    ],

    // Survey Studio v2 defaults (Gate 1 decisions, 2026-10-15). Each survey may override the threshold and retention.
    'surveys' => [
        'segment_threshold' => (int) env('TEDC_SURVEY_SEGMENT_THRESHOLD', 5),   // smallest group whose results are shown
        'retention_days' => (int) env('TEDC_SURVEY_RETENTION_DAYS', 1095),      // answers: 36 months
        'invitation_retention_days' => (int) env('TEDC_SURVEY_INVITATION_RETENTION_DAYS', 90), // after close
        'draft_ttl_days' => (int) env('TEDC_SURVEY_DRAFT_TTL_DAYS', 30),        // autosaved drafts
        'key_grace_days' => (int) env('TEDC_SURVEY_KEY_GRACE_DAYS', 30),        // pseudonym key kept after close
    ],

    // The help centre's support channels (Phase 18): the centre's own contact details; empty until supplied.
    'support' => [
        'phone' => env('TEDC_SUPPORT_PHONE', ''),
        'email' => env('TEDC_SUPPORT_EMAIL', ''),
        'saaed_url' => env('TEDC_SUPPORT_SAAED_URL', ''),
        'hours_ar' => env('TEDC_SUPPORT_HOURS_AR', 'الأحد – الخميس، 7:00 ص – 3:00 م'),
        'hours_en' => env('TEDC_SUPPORT_HOURS_EN', 'Sunday – Thursday, 7:00 – 15:00'),
    ],
];
