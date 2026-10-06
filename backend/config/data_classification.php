<?php

/**
 * Data classification of every table and column (Public / Internal / Confidential / Restricted) and the control each class carries.
 * The register in docs/security/data-classification.md is generated from this file and the live schema (php artisan tedc:data-classification --write);
 * a test fails when a column is not covered, so a new column cannot slip in unclassified.
 */
return [
    'classes' => [
        'public' => ['ar' => 'عام', 'en' => 'Public', 'control' => 'May be published. Integrity controls only.'],
        'internal' => ['ar' => 'داخلي', 'en' => 'Internal', 'control' => 'Authenticated access, role and scope checks, audit of changes.'],
        'confidential' => ['ar' => 'سري', 'en' => 'Confidential', 'control' => 'Personal or sensitive business data: scope-limited access, masked in non-production copies and exports, retention limits, SIEM on bulk export.'],
        'restricted' => ['ar' => 'مقيّد', 'en' => 'Restricted', 'control' => 'Secrets and national identifiers: hashed or application-level encrypted, never logged or exported, readable only by the owner or the system, not selectable by support staff.'],
    ],

    /** Table-wide default, for tables where nearly every column is of one class (never Restricted: secrets are named below). */
    'tables' => [
        'migrations' => 'internal', 'cache' => 'internal', 'cache_locks' => 'internal', 'jobs' => 'internal', 'job_batches' => 'internal', 'failed_jobs' => 'internal',
        'site_settings' => 'internal', 'users' => 'confidential', 'employees' => 'confidential', 'schools' => 'public', 'programs' => 'public', 'program_categories' => 'public',
        'security_events' => 'confidential', 'siem_outbox' => 'confidential', 'audit_logs' => 'confidential', 'error_logs' => 'internal', 'ai_logs' => 'internal', 'assistant_messages' => 'confidential', 'assistant_conversations' => 'confidential',
        'orders' => 'confidential', 'payments' => 'confidential', 'refunds' => 'confidential', 'entity_accounts' => 'confidential', 'seat_vouchers' => 'confidential', 'data_subject_requests' => 'confidential', 'notifications' => 'confidential',
        'registrations' => 'confidential', 'attendance' => 'confidential', 'certificates' => 'confidential', 'evaluations' => 'confidential', 'assessment_attempts' => 'confidential', 'pd_activities' => 'confidential', 'trainers' => 'confidential',
        'user_identities' => 'confidential', 'auth_sessions' => 'confidential',
    ],

    /** Individual columns that are Restricted (or otherwise differ from what the patterns say): "table.column" => class. */
    'overrides' => [
        'sessions.payload' => 'restricted', 'integrations.config' => 'restricted', 'webhook_subscriptions.secret' => 'restricted', 'attendance_devices.api_config' => 'restricted', 'employees.national_id' => 'restricted',
        'users.password' => 'restricted', 'users.mfa_secret' => 'restricted', 'lti_tools.consumer_secret' => 'restricted', 'users.remember_token' => 'restricted', 'personal_access_tokens.token' => 'restricted', 'password_reset_tokens.token' => 'restricted',
    ],

    /** Column rules, first match wins (regex on the column name). They refine the table default. */
    'columns' => [
        ['restricted', '/^(password|remember_token|national_id|api_key|api_secret|secret|token|totp_secret|recovery_codes|private_key|client_secret|access_token|refresh_token|signature_secret|inbound_secret|credentials|api_config|auth_key|device_key|access_code|consumer_secret)$/'],
        ['restricted', '/(_secret|_token|_password)$/'],
        ['restricted', '/^(password|token|code|secret|key)_hash$/'],
        ['restricted', '/^(api|private|secret|auth|device|signing|encryption)_key$/'],
        ['confidential', '/^(email|phone|mobile|name(_ar|_en)?|full_name|first_name|last_name|birth_date|gender|nationality|address|billing_address|ip|ip_address|user_agent|latitude|longitude|photo.*|avatar.*|bio|national_no|iqama.*)$/'],
        ['confidential', '/(email|phone|ip_address)$/'],
        ['public', '/^(id|created_at|updated_at|deleted_at)$/'],
    ],

    /** Columns known to be encrypted at application level (Laravel `encrypted` casts). Restricted columns not listed here are hashes, secrets held in Key Vault, or tokens that expire. */
    'app_encrypted' => ['employees.national_id', 'attendance_devices.api_config', 'users.mfa_secret', 'lti_tools.consumer_secret', 'webhook_subscriptions.secret', 'integrations.config'],
];
