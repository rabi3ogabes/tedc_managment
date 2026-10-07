# Data classification register

> Generated from `config/data_classification.php` and the live schema by `php artisan tedc:data-classification --write`. Do not edit by hand: a test fails when this file and the schema disagree.

**ملخص:** يصنّف هذا السجل كل جدول وعمود في المنصة إلى عام / داخلي / سري / مقيّد، مع الضابط الذي يحكم كل فئة. الحقول المقيّدة (كلمات المرور، الأسرار، الهوية الوطنية) إما مجزّأة أو مشفّرة على مستوى التطبيق ولا تُسجَّل ولا تُصدَّر.

## Classes and controls

| Class | الفئة | Control |
|---|---|---|
| Public | عام | May be published. Integrity controls only. |
| Internal | داخلي | Authenticated access, role and scope checks, audit of changes. |
| Confidential | سري | Personal or sensitive business data: scope-limited access, masked in non-production copies and exports, retention limits, SIEM on bulk export. |
| Restricted | مقيّد | Secrets and national identifiers: hashed or application-level encrypted, never logged or exported, readable only by the owner or the system, not selectable by support staff. |

## Totals

- Public: 699 columns
- Internal: 1935 columns
- Confidential: 469 columns
- Restricted: 19 columns

## Application-level encryption

- `employees.national_id`
- `attendance_devices.api_config`
- `users.mfa_secret`
- `lti_tools.consumer_secret`
- `webhook_subscriptions.secret`
- `integrations.config`

Other Restricted columns hold password hashes, one-time or expiring tokens, or references to secrets kept in Key Vault; none is stored in clear text.

## Register

### `absence_alerts`

| Column | Class | Control |
|---|---|---|
| `id` | Public | — |
| `registration_id` | Internal | — |
| `level` | Internal | — |
| `absence_percent` | Internal | — |
| `supervisor_note` | Internal | — |
| `notified_at` | Internal | — |
| `created_at` | Public | — |
| `updated_at` | Public | — |

### `absence_excuses`

| Column | Class | Control |
|---|---|---|
| `id` | Public | — |
| `registration_id` | Internal | — |
| `session_id` | Internal | — |
| `employee_id` | Internal | — |
| `reason_code` | Internal | — |
| `reason_text` | Internal | — |
| `attachments` | Internal | — |
| `from_date` | Internal | — |
| `to_date` | Internal | — |
| `status` | Internal | — |
| `manager_id` | Internal | — |
| `decided_at` | Internal | — |
| `decision_note` | Internal | — |
| `created_at` | Public | — |
| `updated_at` | Public | — |

### `abuse_reports`

| Column | Class | Control |
|---|---|---|
| `id` | Public | — |
| `target_type` | Internal | — |
| `target_id` | Internal | — |
| `reporter_id` | Internal | — |
| `reason` | Internal | — |
| `note` | Internal | — |
| `status` | Internal | — |
| `action` | Internal | — |
| `handled_by` | Internal | — |
| `handled_at` | Internal | — |
| `created_at` | Public | — |
| `updated_at` | Public | — |

### `adaptive_rules`

| Column | Class | Control |
|---|---|---|
| `id` | Public | — |
| `program_id` | Internal | — |
| `skill_id` | Internal | — |
| `action` | Internal | — |
| `module_id` | Internal | — |
| `lesson_id` | Internal | — |
| `skip_at` | Internal | — |
| `remedial_below` | Internal | — |
| `is_active` | Internal | — |
| `created_at` | Public | — |
| `updated_at` | Public | — |

### `ai_feedback_drafts`

| Column | Class | Control |
|---|---|---|
| `id` | Public | — |
| `attempt_id` | Internal | — |
| `question_id` | Internal | — |
| `draft_text` | Internal | — |
| `suggested_score` | Internal | — |
| `max_points` | Internal | — |
| `rubric` | Internal | — |
| `source` | Internal | — |
| `status` | Internal | — |
| `final_comment` | Internal | — |
| `final_score` | Internal | — |
| `reviewed_by` | Internal | — |
| `reviewed_at` | Internal | — |
| `created_at` | Public | — |
| `updated_at` | Public | — |

### `ai_logs`

| Column | Class | Control |
|---|---|---|
| `id` | Internal | — |
| `feature` | Internal | — |
| `user_id` | Internal | — |
| `connection_id` | Internal | — |
| `model` | Internal | — |
| `status` | Internal | — |
| `reason` | Internal | — |
| `residency` | Internal | — |
| `prompt_chars` | Internal | — |
| `tokens_in` | Internal | — |
| `tokens_out` | Internal | — |
| `latency_ms` | Internal | — |
| `redactions` | Internal | — |
| `created_at` | Internal | — |

### `ai_recommendation_events`

| Column | Class | Control |
|---|---|---|
| `id` | Public | — |
| `user_id` | Internal | — |
| `item_type` | Internal | — |
| `item_id` | Internal | — |
| `event` | Internal | — |
| `variant` | Internal | — |
| `score` | Internal | — |
| `reasons` | Internal | — |
| `note` | Internal | — |
| `created_at` | Public | — |

### `announcement_rsvps`

| Column | Class | Control |
|---|---|---|
| `id` | Public | — |
| `announcement_id` | Internal | — |
| `user_id` | Internal | — |
| `status` | Internal | — |
| `created_at` | Public | — |
| `updated_at` | Public | — |

### `announcements`

| Column | Class | Control |
|---|---|---|
| `id` | Public | — |
| `type` | Internal | — |
| `title_en` | Internal | — |
| `title_ar` | Internal | — |
| `body_en` | Internal | — |
| `body_ar` | Internal | — |
| `audience` | Internal | — |
| `target_ids` | Internal | — |
| `attachments` | Internal | — |
| `cover_path` | Internal | — |
| `is_public` | Internal | — |
| `published_at` | Internal | — |
| `created_by` | Internal | — |
| `created_at` | Public | — |
| `updated_at` | Public | — |
| `starts_at` | Internal | — |
| `ends_at` | Internal | — |
| `is_pinned` | Internal | — |
| `pin_order` | Internal | — |
| `status` | Internal | — |
| `media` | Internal | — |
| `event` | Internal | — |
| `audience_filter` | Internal | — |
| `notify_push` | Internal | — |
| `notify_email` | Confidential | masked in exports |
| `export_to_ministry` | Internal | — |
| `exported_at` | Internal | — |
| `republished_from_id` | Internal | — |
| `archived_at` | Internal | — |

### `archive_items`

| Column | Class | Control |
|---|---|---|
| `id` | Public | — |
| `kind` | Internal | — |
| `subject_id` | Internal | — |
| `sijil_ref` | Internal | — |
| `status` | Internal | — |
| `attempts` | Internal | — |
| `error` | Internal | — |
| `archived_at` | Internal | — |
| `created_at` | Public | — |
| `updated_at` | Public | — |

### `assessment_access_codes`

| Column | Class | Control |
|---|---|---|
| `id` | Public | — |
| `assessment_id` | Internal | — |
| `group_id` | Internal | — |
| `code_hash` | Restricted | hash / secret / expiring token |
| `secret` | Restricted | hash / secret / expiring token |
| `valid_from` | Internal | — |
| `valid_to` | Internal | — |
| `room_id` | Internal | — |
| `created_by` | Internal | — |
| `created_at` | Public | — |
| `updated_at` | Public | — |

### `assessment_attempts`

| Column | Class | Control |
|---|---|---|
| `id` | Confidential | masked in exports |
| `assessment_id` | Confidential | masked in exports |
| `registration_id` | Confidential | masked in exports |
| `attempt_no` | Confidential | masked in exports |
| `started_at` | Confidential | masked in exports |
| `submitted_at` | Confidential | masked in exports |
| `expires_at` | Confidential | masked in exports |
| `ip` | Confidential | masked in exports |
| `device` | Confidential | masked in exports |
| `delivery` | Confidential | masked in exports |
| `questions` | Confidential | masked in exports |
| `answers` | Confidential | masked in exports |
| `auto_score` | Confidential | masked in exports |
| `manual_score` | Confidential | masked in exports |
| `max_score` | Confidential | masked in exports |
| `score_percent` | Confidential | masked in exports |
| `passed` | Confidential | masked in exports |
| `status` | Confidential | masked in exports |
| `integrity` | Confidential | masked in exports |
| `graded_by` | Confidential | masked in exports |
| `graded_at` | Confidential | masked in exports |
| `feedback` | Confidential | masked in exports |
| `extra_minutes` | Confidential | masked in exports |
| `void_reason` | Confidential | masked in exports |
| `created_at` | Confidential | masked in exports |
| `updated_at` | Confidential | masked in exports |

### `assessment_sections`

| Column | Class | Control |
|---|---|---|
| `id` | Public | — |
| `assessment_id` | Internal | — |
| `title` | Internal | — |
| `sort_order` | Internal | — |
| `selection` | Internal | — |
| `bank_id` | Internal | — |
| `category_ids` | Internal | — |
| `difficulty_mix` | Internal | — |
| `count` | Internal | — |
| `points_per_question` | Internal | — |
| `question_ids` | Internal | — |
| `created_at` | Public | — |
| `updated_at` | Public | — |

### `assessments`

| Column | Class | Control |
|---|---|---|
| `id` | Public | — |
| `program_id` | Internal | — |
| `group_id` | Internal | — |
| `lesson_id` | Internal | — |
| `kind` | Internal | — |
| `title_ar` | Internal | — |
| `title_en` | Internal | — |
| `instructions_ar` | Internal | — |
| `instructions_en` | Internal | — |
| `delivery` | Internal | — |
| `access_code_mode` | Internal | — |
| `time_limit_minutes` | Internal | — |
| `window_opens_at` | Internal | — |
| `window_closes_at` | Internal | — |
| `max_attempts` | Internal | — |
| `attempt_cooldown_hours` | Internal | — |
| `pass_percent` | Internal | — |
| `weight_in_course` | Internal | — |
| `shuffle_questions` | Internal | — |
| `shuffle_options` | Internal | — |
| `feedback_mode` | Internal | — |
| `show_score` | Internal | — |
| `show_correct_answers` | Internal | — |
| `require_restudy_on_fail` | Internal | — |
| `proctoring` | Internal | — |
| `status` | Internal | — |
| `released_at` | Internal | — |
| `created_at` | Public | — |
| `updated_at` | Public | — |
| `forms_url` | Internal | — |

### `assistant_conversations`

| Column | Class | Control |
|---|---|---|
| `id` | Confidential | masked in exports |
| `user_id` | Confidential | masked in exports |
| `title` | Confidential | masked in exports |
| `locale` | Confidential | masked in exports |
| `created_at` | Confidential | masked in exports |
| `updated_at` | Confidential | masked in exports |

### `assistant_messages`

| Column | Class | Control |
|---|---|---|
| `id` | Confidential | masked in exports |
| `conversation_id` | Confidential | masked in exports |
| `role` | Confidential | masked in exports |
| `content` | Confidential | masked in exports |
| `citations` | Confidential | masked in exports |
| `meta` | Confidential | masked in exports |
| `feedback` | Confidential | masked in exports |
| `feedback_reason` | Confidential | masked in exports |
| `created_at` | Confidential | masked in exports |
| `updated_at` | Confidential | masked in exports |

### `attempt_answer_grades`

| Column | Class | Control |
|---|---|---|
| `id` | Public | — |
| `attempt_id` | Internal | — |
| `question_id` | Internal | — |
| `points_awarded` | Internal | — |
| `max_points` | Internal | — |
| `grader_id` | Internal | — |
| `comment` | Internal | — |
| `created_at` | Public | — |
| `updated_at` | Public | — |

### `attendance`

| Column | Class | Control |
|---|---|---|
| `id` | Confidential | masked in exports |
| `program_session_id` | Confidential | masked in exports |
| `registration_id` | Confidential | masked in exports |
| `employee_id` | Confidential | masked in exports |
| `check_in_at` | Confidential | masked in exports |
| `check_out_at` | Confidential | masked in exports |
| `method` | Confidential | masked in exports |
| `status` | Confidential | masked in exports |
| `minutes_attended` | Confidential | masked in exports |
| `device_info` | Confidential | masked in exports |
| `ip_address` | Confidential | masked in exports |
| `recorded_by` | Confidential | masked in exports |
| `created_at` | Confidential | masked in exports |
| `updated_at` | Confidential | masked in exports |
| `latitude` | Confidential | masked in exports |
| `longitude` | Confidential | masked in exports |
| `accuracy_m` | Confidential | masked in exports |
| `distance_m` | Confidential | masked in exports |
| `location_status` | Confidential | masked in exports |
| `join_count` | Confidential | masked in exports |
| `last_join_at` | Confidential | masked in exports |
| `biometric_verified` | Confidential | masked in exports |
| `training_group_id` | Confidential | masked in exports |
| `signature_path` | Confidential | masked in exports |
| `device_id` | Confidential | masked in exports |
| `excuse_id` | Confidential | masked in exports |
| `leave_minutes` | Confidential | masked in exports |
| `left_early_at` | Confidential | masked in exports |
| `notes` | Confidential | masked in exports |
| `participated` | Confidential | masked in exports |

### `attendance_attempts`

| Column | Class | Control |
|---|---|---|
| `id` | Public | — |
| `employee_id` | Internal | — |
| `program_id` | Internal | — |
| `program_session_id` | Internal | — |
| `outcome` | Internal | — |
| `code` | Internal | — |
| `message` | Internal | — |
| `device_info` | Internal | — |
| `ip_address` | Confidential | masked in exports |
| `created_at` | Public | — |

### `attendance_devices`

| Column | Class | Control |
|---|---|---|
| `id` | Public | — |
| `name` | Confidential | masked in exports |
| `vendor` | Internal | — |
| `serial` | Internal | — |
| `location_room_id` | Internal | — |
| `api_config` | Restricted | application-level encryption |
| `last_sync_at` | Internal | — |
| `status` | Internal | — |
| `last_error` | Internal | — |
| `created_at` | Public | — |
| `updated_at` | Public | — |

### `attendance_leaves`

| Column | Class | Control |
|---|---|---|
| `id` | Public | — |
| `attendance_id` | Internal | — |
| `type` | Internal | — |
| `from_time` | Internal | — |
| `to_time` | Internal | — |
| `minutes` | Internal | — |
| `reason` | Internal | — |
| `attachments` | Internal | — |
| `entered_by` | Internal | — |
| `notified_at` | Internal | — |
| `created_at` | Public | — |
| `updated_at` | Public | — |

### `audit_logs`

| Column | Class | Control |
|---|---|---|
| `id` | Confidential | masked in exports |
| `user_id` | Confidential | masked in exports |
| `action` | Confidential | masked in exports |
| `auditable_type` | Confidential | masked in exports |
| `auditable_id` | Confidential | masked in exports |
| `old_values` | Confidential | masked in exports |
| `new_values` | Confidential | masked in exports |
| `ip_address` | Confidential | masked in exports |
| `user_agent` | Confidential | masked in exports |
| `url` | Confidential | masked in exports |
| `created_at` | Confidential | masked in exports |

### `auth_sessions`

| Column | Class | Control |
|---|---|---|
| `id` | Confidential | masked in exports |
| `external_id` | Confidential | masked in exports |
| `user_id` | Confidential | masked in exports |
| `method` | Confidential | masked in exports |
| `ip` | Confidential | masked in exports |
| `user_agent` | Confidential | masked in exports |
| `last_seen_at` | Confidential | masked in exports |
| `expires_at` | Confidential | masked in exports |
| `stepped_up_at` | Confidential | masked in exports |
| `revoked_at` | Confidential | masked in exports |
| `revoked_reason` | Confidential | masked in exports |
| `created_at` | Confidential | masked in exports |
| `updated_at` | Confidential | masked in exports |

### `badges`

| Column | Class | Control |
|---|---|---|
| `id` | Public | — |
| `code` | Internal | — |
| `name_ar` | Confidential | masked in exports |
| `name_en` | Confidential | masked in exports |
| `description_ar` | Internal | — |
| `description_en` | Internal | — |
| `icon_svg` | Internal | — |
| `tier` | Internal | — |
| `criteria` | Internal | — |
| `is_active` | Internal | — |
| `created_at` | Public | — |
| `updated_at` | Public | — |

### `bank_categories`

| Column | Class | Control |
|---|---|---|
| `id` | Public | — |
| `bank_id` | Internal | — |
| `parent_id` | Internal | — |
| `name_ar` | Confidential | masked in exports |
| `name_en` | Confidential | masked in exports |
| `sort_order` | Internal | — |
| `created_at` | Public | — |
| `updated_at` | Public | — |

### `buildings`

| Column | Class | Control |
|---|---|---|
| `id` | Public | — |
| `place_id` | Internal | — |
| `name_ar` | Confidential | masked in exports |
| `name_en` | Confidential | masked in exports |
| `capacity_limit` | Internal | — |
| `created_at` | Public | — |
| `updated_at` | Public | — |

### `cache`

| Column | Class | Control |
|---|---|---|
| `key` | Internal | — |
| `value` | Internal | — |
| `expiration` | Internal | — |

### `cache_locks`

| Column | Class | Control |
|---|---|---|
| `key` | Internal | — |
| `owner` | Internal | — |
| `expiration` | Internal | — |

### `calendar_approvals`

| Column | Class | Control |
|---|---|---|
| `id` | Public | — |
| `date` | Internal | — |
| `reason` | Internal | — |
| `approved_by` | Internal | — |
| `created_at` | Public | — |
| `updated_at` | Public | — |

### `calendar_days`

| Column | Class | Control |
|---|---|---|
| `id` | Public | — |
| `date` | Internal | — |
| `type` | Internal | — |
| `title_ar` | Internal | — |
| `title_en` | Internal | — |
| `notes` | Internal | — |
| `created_by` | Internal | — |
| `created_at` | Public | — |
| `updated_at` | Public | — |

### `caliper_events`

| Column | Class | Control |
|---|---|---|
| `id` | Public | — |
| `event` | Internal | — |
| `status` | Internal | — |
| `attempts` | Internal | — |
| `error` | Internal | — |
| `sent_at` | Internal | — |
| `created_at` | Public | — |
| `updated_at` | Public | — |

### `career_path_levels`

| Column | Class | Control |
|---|---|---|
| `id` | Public | — |
| `path_id` | Internal | — |
| `level_no` | Internal | — |
| `title_ar` | Internal | — |
| `title_en` | Internal | — |
| `conditions` | Internal | — |
| `required_programs` | Internal | — |
| `min_pd_hours` | Internal | — |
| `validity_months` | Internal | — |
| `renewal_conditions` | Internal | — |
| `created_at` | Public | — |
| `updated_at` | Public | — |

### `career_paths`

| Column | Class | Control |
|---|---|---|
| `id` | Public | — |
| `type` | Internal | — |
| `title_ar` | Internal | — |
| `title_en` | Internal | — |
| `description_ar` | Internal | — |
| `description_en` | Internal | — |
| `job_title_ids` | Internal | — |
| `is_active` | Internal | — |
| `created_at` | Public | — |
| `updated_at` | Public | — |

### `cart_items`

| Column | Class | Control |
|---|---|---|
| `id` | Public | — |
| `cart_id` | Internal | — |
| `group_id` | Internal | — |
| `quantity` | Internal | — |
| `expires_at` | Internal | — |
| `created_at` | Public | — |
| `updated_at` | Public | — |

### `carts`

| Column | Class | Control |
|---|---|---|
| `id` | Public | — |
| `user_id` | Internal | — |
| `entity_account_id` | Internal | — |
| `discount_code` | Internal | — |
| `created_at` | Public | — |
| `updated_at` | Public | — |

### `certificate_templates`

| Column | Class | Control |
|---|---|---|
| `id` | Public | — |
| `name_ar` | Confidential | masked in exports |
| `name_en` | Confidential | masked in exports |
| `kind` | Internal | — |
| `width_mm` | Internal | — |
| `height_mm` | Internal | — |
| `background_path` | Internal | — |
| `source_pdf_path` | Internal | — |
| `elements` | Internal | — |
| `is_default` | Internal | — |
| `status` | Internal | — |
| `created_by` | Internal | — |
| `created_at` | Public | — |
| `updated_at` | Public | — |
| `deleted_at` | Public | — |

### `certificates`

| Column | Class | Control |
|---|---|---|
| `id` | Confidential | masked in exports |
| `certificate_no` | Confidential | masked in exports |
| `verification_code` | Confidential | masked in exports |
| `registration_id` | Confidential | masked in exports |
| `employee_id` | Confidential | masked in exports |
| `program_id` | Confidential | masked in exports |
| `issued_at` | Confidential | masked in exports |
| `hours` | Confidential | masked in exports |
| `file_path` | Confidential | masked in exports |
| `status` | Confidential | masked in exports |
| `revoked_reason` | Confidential | masked in exports |
| `issued_by` | Confidential | masked in exports |
| `meta` | Confidential | masked in exports |
| `created_at` | Confidential | masked in exports |
| `updated_at` | Confidential | masked in exports |
| `sent_at` | Confidential | masked in exports |
| `sent_count` | Confidential | masked in exports |
| `sent_to` | Confidential | masked in exports |
| `sent_by` | Confidential | masked in exports |
| `send_error` | Confidential | masked in exports |
| `available_notified_at` | Confidential | masked in exports |
| `template_id` | Confidential | masked in exports |
| `training_group_id` | Confidential | masked in exports |
| `type` | Confidential | masked in exports |
| `hours_mode` | Confidential | masked in exports |
| `hours_total` | Confidential | masked in exports |
| `hours_actual` | Confidential | masked in exports |

### `challenge_participants`

| Column | Class | Control |
|---|---|---|
| `id` | Public | — |
| `challenge_id` | Internal | — |
| `user_id` | Internal | — |
| `progress` | Internal | — |
| `completed_at` | Internal | — |
| `created_at` | Public | — |
| `updated_at` | Public | — |

### `challenges`

| Column | Class | Control |
|---|---|---|
| `id` | Public | — |
| `title_ar` | Internal | — |
| `title_en` | Internal | — |
| `description_ar` | Internal | — |
| `description_en` | Internal | — |
| `starts_at` | Internal | — |
| `ends_at` | Internal | — |
| `audience` | Internal | — |
| `goal` | Internal | — |
| `reward` | Internal | — |
| `type` | Internal | — |
| `is_active` | Internal | — |
| `closed_at` | Internal | — |
| `created_at` | Public | — |
| `updated_at` | Public | — |

### `chat_conversations`

| Column | Class | Control |
|---|---|---|
| `id` | Public | — |
| `token_hash` | Restricted | hash / secret / expiring token |
| `user_id` | Internal | — |
| `visitor_name` | Internal | — |
| `visitor_email` | Confidential | masked in exports |
| `locale` | Internal | — |
| `mode` | Internal | — |
| `status` | Internal | — |
| `needs_human` | Internal | — |
| `admin_unread` | Internal | — |
| `visitor_unread` | Internal | — |
| `messages_count` | Internal | — |
| `user_agent` | Confidential | masked in exports |
| `last_message_at` | Internal | — |
| `created_at` | Public | — |
| `updated_at` | Public | — |

### `chat_messages`

| Column | Class | Control |
|---|---|---|
| `id` | Public | — |
| `conversation_id` | Internal | — |
| `sender` | Internal | — |
| `admin_id` | Internal | — |
| `body` | Internal | — |
| `meta` | Internal | — |
| `created_at` | Public | — |

### `classroom_observations`

| Column | Class | Control |
|---|---|---|
| `id` | Public | — |
| `employee_id` | Internal | — |
| `observer_user_id` | Internal | — |
| `observer_role` | Internal | — |
| `observed_on` | Internal | — |
| `subject` | Internal | — |
| `grade` | Internal | — |
| `scores` | Internal | — |
| `overall` | Internal | — |
| `notes` | Internal | — |
| `source` | Internal | — |
| `created_at` | Public | — |
| `updated_at` | Public | — |

### `cmi5_sessions`

| Column | Class | Control |
|---|---|---|
| `id` | Public | — |
| `registration_id` | Internal | — |
| `lesson_id` | Internal | — |
| `au_id` | Internal | — |
| `token_hash` | Restricted | hash / secret / expiring token |
| `state` | Internal | — |
| `expires_at` | Internal | — |
| `created_at` | Public | — |
| `updated_at` | Public | — |

### `comments`

| Column | Class | Control |
|---|---|---|
| `id` | Public | — |
| `post_id` | Internal | — |
| `parent_id` | Internal | — |
| `author_id` | Internal | — |
| `body` | Internal | — |
| `attachments` | Internal | — |
| `status` | Internal | — |
| `edited_at` | Internal | — |
| `created_at` | Public | — |
| `updated_at` | Public | — |

### `competency_domains`

| Column | Class | Control |
|---|---|---|
| `id` | Public | — |
| `code` | Internal | — |
| `name_ar` | Confidential | masked in exports |
| `name_en` | Confidential | masked in exports |
| `sort_order` | Internal | — |
| `created_at` | Public | — |
| `updated_at` | Public | — |

### `contact_messages`

| Column | Class | Control |
|---|---|---|
| `id` | Public | — |
| `name` | Confidential | masked in exports |
| `email` | Confidential | masked in exports |
| `phone` | Confidential | masked in exports |
| `subject` | Internal | — |
| `message` | Internal | — |
| `status` | Internal | — |
| `created_at` | Public | — |
| `updated_at` | Public | — |

### `content_imports`

| Column | Class | Control |
|---|---|---|
| `id` | Public | — |
| `kind` | Internal | — |
| `program_id` | Internal | — |
| `log` | Internal | — |
| `created_by` | Internal | — |
| `created_at` | Public | — |
| `updated_at` | Public | — |

### `content_packages`

| Column | Class | Control |
|---|---|---|
| `id` | Public | — |
| `standard` | Internal | — |
| `title` | Internal | — |
| `version` | Internal | — |
| `storage_root` | Internal | — |
| `manifest` | Internal | — |
| `entry_points` | Internal | — |
| `size` | Internal | — |
| `uploaded_by` | Internal | — |
| `status` | Internal | — |
| `error` | Internal | — |
| `created_at` | Public | — |
| `updated_at` | Public | — |

### `content_ratings`

| Column | Class | Control |
|---|---|---|
| `id` | Public | — |
| `subject_type` | Internal | — |
| `subject_id` | Internal | — |
| `user_id` | Internal | — |
| `stars` | Internal | — |
| `review` | Internal | — |
| `status` | Internal | — |
| `created_at` | Public | — |
| `updated_at` | Public | — |

### `course_lesson_versions`

| Column | Class | Control |
|---|---|---|
| `id` | Public | — |
| `lesson_id` | Internal | — |
| `version` | Internal | — |
| `snapshot` | Internal | — |
| `note` | Internal | — |
| `is_archived` | Internal | — |
| `created_by` | Internal | — |
| `created_at` | Public | — |
| `updated_at` | Public | — |

### `course_lessons`

| Column | Class | Control |
|---|---|---|
| `id` | Public | — |
| `program_id` | Internal | — |
| `module_id` | Internal | — |
| `type` | Internal | — |
| `title_ar` | Internal | — |
| `title_en` | Internal | — |
| `description_ar` | Internal | — |
| `description_en` | Internal | — |
| `body_ar` | Internal | — |
| `body_en` | Internal | — |
| `sort_order` | Internal | — |
| `is_required` | Internal | — |
| `status` | Internal | — |
| `duration_seconds` | Internal | — |
| `source` | Internal | — |
| `file_path` | Internal | — |
| `file_name` | Internal | — |
| `file_mime` | Internal | — |
| `file_size` | Internal | — |
| `external_url` | Internal | — |
| `slide_count` | Internal | — |
| `settings` | Internal | — |
| `created_at` | Public | — |
| `updated_at` | Public | — |
| `package_id` | Internal | — |
| `package_item_id` | Internal | — |
| `lti_tool_id` | Internal | — |
| `external_course_id` | Internal | — |
| `version` | Internal | — |

### `course_modules`

| Column | Class | Control |
|---|---|---|
| `id` | Public | — |
| `program_id` | Internal | — |
| `title_ar` | Internal | — |
| `title_en` | Internal | — |
| `description_ar` | Internal | — |
| `description_en` | Internal | — |
| `sort_order` | Internal | — |
| `created_at` | Public | — |
| `updated_at` | Public | — |

### `course_questions`

| Column | Class | Control |
|---|---|---|
| `id` | Public | — |
| `asker_id` | Internal | — |
| `program_id` | Internal | — |
| `group_id` | Internal | — |
| `lesson_id` | Internal | — |
| `visibility` | Internal | — |
| `subject` | Internal | — |
| `body` | Internal | — |
| `status` | Internal | — |
| `answer` | Internal | — |
| `answered_by` | Internal | — |
| `answered_at` | Internal | — |
| `due_at` | Internal | — |
| `post_id` | Internal | — |
| `created_at` | Public | — |
| `updated_at` | Public | — |

### `dashboard_presets`

| Column | Class | Control |
|---|---|---|
| `id` | Public | — |
| `role_slug` | Internal | — |
| `widgets` | Internal | — |
| `created_at` | Public | — |
| `updated_at` | Public | — |

### `data_subject_requests`

| Column | Class | Control |
|---|---|---|
| `id` | Confidential | masked in exports |
| `user_id` | Confidential | masked in exports |
| `type` | Confidential | masked in exports |
| `details` | Confidential | masked in exports |
| `status` | Confidential | masked in exports |
| `due_at` | Confidential | masked in exports |
| `handled_by` | Confidential | masked in exports |
| `resolution` | Confidential | masked in exports |
| `completed_at` | Confidential | masked in exports |
| `created_at` | Confidential | masked in exports |
| `updated_at` | Confidential | masked in exports |

### `departments`

| Column | Class | Control |
|---|---|---|
| `id` | Public | — |
| `school_id` | Internal | — |
| `code` | Internal | — |
| `name_en` | Confidential | masked in exports |
| `name_ar` | Confidential | masked in exports |
| `created_at` | Public | — |
| `updated_at` | Public | — |

### `device_punches`

| Column | Class | Control |
|---|---|---|
| `id` | Public | — |
| `device_id` | Internal | — |
| `person_ref` | Internal | — |
| `punched_at` | Internal | — |
| `direction` | Internal | — |
| `raw` | Internal | — |
| `processed_at` | Internal | — |
| `outcome` | Internal | — |
| `attendance_id` | Internal | — |
| `created_at` | Public | — |
| `updated_at` | Public | — |

### `device_tokens`

| Column | Class | Control |
|---|---|---|
| `id` | Public | — |
| `user_id` | Internal | — |
| `token` | Restricted | hash / secret / expiring token |
| `platform` | Internal | — |
| `locale` | Internal | — |
| `app_version` | Internal | — |
| `device_name` | Internal | — |
| `last_seen_at` | Internal | — |
| `created_at` | Public | — |
| `updated_at` | Public | — |

### `discount_codes`

| Column | Class | Control |
|---|---|---|
| `id` | Public | — |
| `code` | Internal | — |
| `type` | Internal | — |
| `value` | Internal | — |
| `scope` | Internal | — |
| `scope_id` | Internal | — |
| `usage_limit` | Internal | — |
| `per_user_limit` | Internal | — |
| `used` | Internal | — |
| `valid_from` | Internal | — |
| `valid_to` | Internal | — |
| `source` | Internal | — |
| `is_active` | Internal | — |
| `created_at` | Public | — |
| `updated_at` | Public | — |

### `eligibility_rules`

| Column | Class | Control |
|---|---|---|
| `id` | Public | — |
| `program_id` | Internal | — |
| `field` | Internal | — |
| `operator` | Internal | — |
| `value` | Internal | — |
| `message_en` | Internal | — |
| `message_ar` | Internal | — |
| `is_mandatory` | Internal | — |
| `sort_order` | Internal | — |
| `created_at` | Public | — |
| `updated_at` | Public | — |
| `is_generated` | Internal | — |

### `embeddings`

| Column | Class | Control |
|---|---|---|
| `id` | Public | — |
| `source_type` | Internal | — |
| `source_id` | Internal | — |
| `chunk_no` | Internal | — |
| `program_id` | Internal | — |
| `visibility` | Internal | — |
| `lang` | Internal | — |
| `title` | Internal | — |
| `route` | Internal | — |
| `content` | Internal | — |
| `vector` | Internal | — |
| `content_hash` | Internal | — |
| `updated_at` | Public | — |

### `employee_path_progress`

| Column | Class | Control |
|---|---|---|
| `id` | Public | — |
| `employee_id` | Internal | — |
| `path_id` | Internal | — |
| `current_level_no` | Internal | — |
| `target_level_no` | Internal | — |
| `status` | Internal | — |
| `explanation` | Internal | — |
| `evaluated_at` | Internal | — |
| `created_at` | Public | — |
| `updated_at` | Public | — |

### `employee_skills`

| Column | Class | Control |
|---|---|---|
| `id` | Public | — |
| `employee_id` | Internal | — |
| `skill_id` | Internal | — |
| `level` | Internal | — |
| `source` | Internal | — |
| `program_id` | Internal | — |
| `verified_at` | Internal | — |
| `created_at` | Public | — |
| `updated_at` | Public | — |

### `employees`

| Column | Class | Control |
|---|---|---|
| `id` | Confidential | masked in exports |
| `user_id` | Confidential | masked in exports |
| `school_id` | Confidential | masked in exports |
| `department_id` | Confidential | masked in exports |
| `job_title_id` | Confidential | masked in exports |
| `supervisor_id` | Confidential | masked in exports |
| `employee_no` | Confidential | masked in exports |
| `national_id` | Restricted | application-level encryption |
| `gender` | Confidential | masked in exports |
| `nationality` | Confidential | masked in exports |
| `hire_date` | Confidential | masked in exports |
| `experience_years` | Confidential | masked in exports |
| `education_stage` | Confidential | masked in exports |
| `qualification` | Confidential | masked in exports |
| `specialization` | Confidential | masked in exports |
| `status` | Confidential | masked in exports |
| `created_at` | Confidential | masked in exports |
| `updated_at` | Confidential | masked in exports |
| `birth_date` | Confidential | masked in exports |
| `experience_moe_years` | Confidential | masked in exports |
| `experience_outside_years` | Confidential | masked in exports |
| `current_title_since` | Confidential | masked in exports |
| `grade_level` | Confidential | masked in exports |
| `subjects` | Confidential | masked in exports |
| `grades_taught` | Confidential | masked in exports |

### `entity_account_users`

| Column | Class | Control |
|---|---|---|
| `entity_account_id` | Internal | — |
| `user_id` | Internal | — |
| `role` | Internal | — |

### `entity_accounts`

| Column | Class | Control |
|---|---|---|
| `id` | Confidential | masked in exports |
| `name_ar` | Confidential | masked in exports |
| `name_en` | Confidential | masked in exports |
| `type` | Confidential | masked in exports |
| `cr_number` | Confidential | masked in exports |
| `contacts` | Confidential | masked in exports |
| `billing_address` | Confidential | masked in exports |
| `partner_organization_id` | Confidential | masked in exports |
| `status` | Confidential | masked in exports |
| `created_at` | Confidential | masked in exports |
| `updated_at` | Confidential | masked in exports |

### `error_logs`

| Column | Class | Control |
|---|---|---|
| `id` | Internal | — |
| `fingerprint` | Internal | — |
| `source` | Internal | — |
| `level` | Internal | — |
| `message` | Internal | — |
| `exception` | Internal | — |
| `location` | Internal | — |
| `stack` | Internal | — |
| `method` | Internal | — |
| `url` | Internal | — |
| `status_code` | Internal | — |
| `user_id` | Internal | — |
| `user_email` | Confidential | masked in exports |
| `ip` | Confidential | masked in exports |
| `user_agent` | Confidential | masked in exports |
| `app_version` | Internal | — |
| `device` | Internal | — |
| `context` | Internal | — |
| `occurrences` | Internal | — |
| `users_count` | Internal | — |
| `first_seen_at` | Internal | — |
| `last_seen_at` | Internal | — |
| `status` | Internal | — |
| `resolved_at` | Internal | — |
| `resolved_by` | Internal | — |
| `note` | Internal | — |
| `auto_fixed` | Internal | — |
| `fix_attempts` | Internal | — |
| `last_fix_at` | Internal | — |
| `created_at` | Internal | — |
| `updated_at` | Internal | — |

### `evaluation_assignments`

| Column | Class | Control |
|---|---|---|
| `id` | Public | — |
| `form_id` | Internal | — |
| `program_id` | Internal | — |
| `group_id` | Internal | — |
| `respondent_type` | Internal | — |
| `respondent_user_id` | Internal | — |
| `subject_registration_id` | Internal | — |
| `due_at` | Internal | — |
| `sent_at` | Internal | — |
| `reminded_at` | Internal | — |
| `status` | Internal | — |
| `assigned_by` | Internal | — |
| `created_at` | Public | — |
| `updated_at` | Public | — |

### `evaluation_forms`

| Column | Class | Control |
|---|---|---|
| `id` | Public | — |
| `kind` | Internal | — |
| `title_ar` | Internal | — |
| `title_en` | Internal | — |
| `questions` | Internal | — |
| `version` | Internal | — |
| `approval_status` | Internal | — |
| `approval_note` | Internal | — |
| `approved_by` | Internal | — |
| `approved_at` | Internal | — |
| `is_default` | Internal | — |
| `is_system` | Internal | — |
| `settings` | Internal | — |
| `created_by` | Internal | — |
| `created_at` | Public | — |
| `updated_at` | Public | — |

### `evaluation_interviews`

| Column | Class | Control |
|---|---|---|
| `id` | Public | — |
| `program_id` | Internal | — |
| `group_id` | Internal | — |
| `interviewee_user_id` | Internal | — |
| `interviewee_name` | Internal | — |
| `interviewer_id` | Internal | — |
| `held_at` | Internal | — |
| `method` | Internal | — |
| `questions_answers` | Internal | — |
| `summary` | Internal | — |
| `attachments` | Internal | — |
| `sentiment` | Internal | — |
| `created_at` | Public | — |
| `updated_at` | Public | — |

### `evaluation_responses`

| Column | Class | Control |
|---|---|---|
| `id` | Public | — |
| `assignment_id` | Internal | — |
| `answers` | Internal | — |
| `evidence` | Internal | — |
| `form_version` | Internal | — |
| `score` | Internal | — |
| `submitted_at` | Internal | — |
| `created_at` | Public | — |
| `updated_at` | Public | — |

### `evaluations`

| Column | Class | Control |
|---|---|---|
| `id` | Confidential | masked in exports |
| `program_id` | Confidential | masked in exports |
| `registration_id` | Confidential | masked in exports |
| `employee_id` | Confidential | masked in exports |
| `ratings` | Confidential | masked in exports |
| `satisfaction_score` | Confidential | masked in exports |
| `pre_test_score` | Confidential | masked in exports |
| `post_test_score` | Confidential | masked in exports |
| `comments` | Confidential | masked in exports |
| `allow_testimonial` | Confidential | masked in exports |
| `submitted_at` | Confidential | masked in exports |
| `created_at` | Confidential | masked in exports |
| `updated_at` | Confidential | masked in exports |

### `external_completions`

| Column | Class | Control |
|---|---|---|
| `id` | Public | — |
| `registration_id` | Internal | — |
| `evidence` | Internal | — |
| `note` | Internal | — |
| `source` | Internal | — |
| `status` | Internal | — |
| `reviewer_id` | Internal | — |
| `review_note` | Internal | — |
| `decided_at` | Internal | — |
| `created_at` | Public | — |
| `updated_at` | Public | — |

### `external_courses`

| Column | Class | Control |
|---|---|---|
| `id` | Public | — |
| `provider` | Internal | — |
| `external_id` | Internal | — |
| `title` | Internal | — |
| `url` | Internal | — |
| `hours` | Internal | — |
| `meta` | Internal | — |
| `program_id` | Internal | — |
| `synced_at` | Internal | — |
| `created_at` | Public | — |
| `updated_at` | Public | — |

### `failed_jobs`

| Column | Class | Control |
|---|---|---|
| `id` | Internal | — |
| `uuid` | Internal | — |
| `connection` | Internal | — |
| `queue` | Internal | — |
| `payload` | Internal | — |
| `exception` | Internal | — |
| `failed_at` | Internal | — |

### `forecasts`

| Column | Class | Control |
|---|---|---|
| `id` | Public | — |
| `dimension` | Internal | — |
| `subject_id` | Internal | — |
| `label` | Internal | — |
| `year` | Internal | — |
| `value` | Internal | — |
| `low` | Internal | — |
| `high` | Internal | — |
| `model` | Internal | — |
| `explanation` | Internal | — |
| `history` | Internal | — |
| `created_at` | Public | — |
| `updated_at` | Public | — |

### `gamification_profiles`

| Column | Class | Control |
|---|---|---|
| `user_id` | Internal | — |
| `hidden` | Internal | — |
| `created_at` | Public | — |
| `updated_at` | Public | — |

### `gamification_rules`

| Column | Class | Control |
|---|---|---|
| `id` | Public | — |
| `event` | Internal | — |
| `points` | Internal | — |
| `caps` | Internal | — |
| `conditions` | Internal | — |
| `is_active` | Internal | — |
| `created_at` | Public | — |
| `updated_at` | Public | — |

### `group_seat_allocations`

| Column | Class | Control |
|---|---|---|
| `id` | Public | — |
| `group_id` | Internal | — |
| `entity_type` | Internal | — |
| `entity_id` | Internal | — |
| `seats` | Internal | — |
| `release_at` | Internal | — |
| `released_at` | Internal | — |
| `priority` | Internal | — |
| `created_at` | Public | — |
| `updated_at` | Public | — |

### `group_trainers`

| Column | Class | Control |
|---|---|---|
| `id` | Public | — |
| `group_id` | Internal | — |
| `trainer_id` | Internal | — |
| `role` | Internal | — |
| `hours` | Internal | — |
| `status` | Internal | — |
| `form` | Internal | — |
| `form_submitted_at` | Internal | — |
| `proposed_by` | Internal | — |
| `decided_by` | Internal | — |
| `decided_at` | Internal | — |
| `decision_note` | Internal | — |
| `external_approval_ref` | Internal | — |
| `external_approval_path` | Internal | — |
| `created_at` | Public | — |
| `updated_at` | Public | — |

### `help_article_versions`

| Column | Class | Control |
|---|---|---|
| `id` | Public | — |
| `article_id` | Internal | — |
| `version` | Internal | — |
| `snapshot` | Internal | — |
| `created_by` | Internal | — |
| `created_at` | Public | — |
| `updated_at` | Public | — |

### `help_articles`

| Column | Class | Control |
|---|---|---|
| `id` | Public | — |
| `slug` | Internal | — |
| `title_ar` | Internal | — |
| `title_en` | Internal | — |
| `body_ar` | Internal | — |
| `body_en` | Internal | — |
| `roles` | Internal | — |
| `module` | Internal | — |
| `related_routes` | Internal | — |
| `video_url` | Internal | — |
| `video_asset` | Internal | — |
| `screenshots` | Internal | — |
| `sort_order` | Internal | — |
| `status` | Internal | — |
| `version` | Internal | — |
| `updated_by` | Internal | — |
| `created_at` | Public | — |
| `updated_at` | Public | — |

### `help_feedback`

| Column | Class | Control |
|---|---|---|
| `id` | Public | — |
| `article_id` | Internal | — |
| `user_id` | Internal | — |
| `helpful` | Internal | — |
| `comment` | Internal | — |
| `article_version` | Internal | — |
| `created_at` | Public | — |
| `updated_at` | Public | — |

### `impact_surveys`

| Column | Class | Control |
|---|---|---|
| `id` | Public | — |
| `registration_id` | Internal | — |
| `program_id` | Internal | — |
| `employee_id` | Internal | — |
| `stage_days` | Internal | — |
| `scheduled_for` | Internal | — |
| `sent_at` | Internal | — |
| `completed_at` | Internal | — |
| `status` | Internal | — |
| `applied_learning` | Internal | — |
| `application_score` | Internal | — |
| `changes_observed` | Internal | — |
| `skills_improved` | Internal | — |
| `needs_support` | Internal | — |
| `support_details` | Internal | — |
| `created_at` | Public | — |
| `updated_at` | Public | — |
| `evidence` | Internal | — |

### `inbound_events`

| Column | Class | Control |
|---|---|---|
| `id` | Public | — |
| `source` | Internal | — |
| `idempotency_key` | Internal | — |
| `payload` | Internal | — |
| `result` | Internal | — |
| `processed_at` | Internal | — |
| `created_at` | Public | — |
| `updated_at` | Public | — |

### `individual_needs`

| Column | Class | Control |
|---|---|---|
| `id` | Public | — |
| `employee_id` | Internal | — |
| `skill_id` | Internal | — |
| `source` | Internal | — |
| `source_ref` | Internal | — |
| `current_level` | Internal | — |
| `required_level` | Internal | — |
| `gap` | Internal | — |
| `priority_score` | Internal | — |
| `explanation_ar` | Internal | — |
| `explanation_en` | Internal | — |
| `status` | Internal | — |
| `manager_id` | Internal | — |
| `manager_note` | Internal | — |
| `decided_at` | Internal | — |
| `cycle_id` | Internal | — |
| `created_at` | Public | — |
| `updated_at` | Public | — |

### `institutional_requests`

| Column | Class | Control |
|---|---|---|
| `id` | Public | — |
| `cycle_id` | Internal | — |
| `requested_by` | Internal | — |
| `entity_id` | Internal | — |
| `entity_name` | Internal | — |
| `program_id` | Internal | — |
| `title` | Internal | — |
| `need_degree` | Internal | — |
| `objectives` | Internal | — |
| `employee_ids` | Internal | — |
| `preferred_window` | Internal | — |
| `status` | Internal | — |
| `review_note` | Internal | — |
| `reviewer_id` | Internal | — |
| `plan_item_id` | Internal | — |
| `created_at` | Public | — |
| `updated_at` | Public | — |
| `legacy_need_id` | Internal | — |
| `employees_count` | Internal | — |

### `integration_logs`

| Column | Class | Control |
|---|---|---|
| `id` | Public | — |
| `integration_key` | Internal | — |
| `direction` | Internal | — |
| `operation` | Internal | — |
| `status` | Internal | — |
| `duration_ms` | Internal | — |
| `request_summary` | Internal | — |
| `response_summary` | Internal | — |
| `error` | Internal | — |
| `correlation_id` | Internal | — |
| `created_at` | Public | — |

### `integrations`

| Column | Class | Control |
|---|---|---|
| `key` | Internal | — |
| `driver` | Internal | — |
| `config` | Restricted | application-level encryption |
| `enabled` | Internal | — |
| `health` | Internal | — |
| `latency_ms` | Internal | — |
| `last_check_at` | Internal | — |
| `last_sync_at` | Internal | — |
| `last_error` | Internal | — |
| `failures` | Internal | — |
| `open_until` | Internal | — |
| `created_at` | Public | — |
| `updated_at` | Public | — |

### `invoice_counters`

| Column | Class | Control |
|---|---|---|
| `key` | Internal | — |
| `last` | Internal | — |

### `item_similarity`

| Column | Class | Control |
|---|---|---|
| `id` | Public | — |
| `item_type` | Internal | — |
| `item_id` | Internal | — |
| `other_id` | Internal | — |
| `score` | Internal | — |
| `support` | Internal | — |
| `computed_at` | Internal | — |

### `job_batches`

| Column | Class | Control |
|---|---|---|
| `id` | Internal | — |
| `name` | Confidential | masked in exports |
| `total_jobs` | Internal | — |
| `pending_jobs` | Internal | — |
| `failed_jobs` | Internal | — |
| `failed_job_ids` | Internal | — |
| `options` | Internal | — |
| `cancelled_at` | Internal | — |
| `created_at` | Internal | — |
| `finished_at` | Internal | — |

### `job_competency_requirements`

| Column | Class | Control |
|---|---|---|
| `id` | Public | — |
| `job_title_id` | Internal | — |
| `skill_id` | Internal | — |
| `education_stage` | Internal | — |
| `subject` | Internal | — |
| `required_level` | Internal | — |
| `weight` | Internal | — |
| `created_at` | Public | — |
| `updated_at` | Public | — |

### `job_groups`

| Column | Class | Control |
|---|---|---|
| `id` | Public | — |
| `name_ar` | Confidential | masked in exports |
| `name_en` | Confidential | masked in exports |
| `rule` | Internal | — |
| `created_at` | Public | — |
| `updated_at` | Public | — |

### `job_titles`

| Column | Class | Control |
|---|---|---|
| `id` | Public | — |
| `code` | Internal | — |
| `name_en` | Confidential | masked in exports |
| `name_ar` | Confidential | masked in exports |
| `category` | Internal | — |
| `created_at` | Public | — |
| `updated_at` | Public | — |

### `jobs`

| Column | Class | Control |
|---|---|---|
| `id` | Internal | — |
| `queue` | Internal | — |
| `payload` | Internal | — |
| `attempts` | Internal | — |
| `reserved_at` | Internal | — |
| `available_at` | Internal | — |
| `created_at` | Internal | — |

### `kit_activity`

| Column | Class | Control |
|---|---|---|
| `id` | Public | — |
| `kit_id` | Internal | — |
| `user_id` | Internal | — |
| `action` | Internal | — |
| `subject_type` | Internal | — |
| `subject_id` | Internal | — |
| `meta` | Internal | — |
| `created_at` | Public | — |

### `kit_assets`

| Column | Class | Control |
|---|---|---|
| `id` | Public | — |
| `kit_id` | Internal | — |
| `file_id` | Internal | — |
| `name` | Confidential | masked in exports |
| `mime` | Internal | — |
| `size` | Internal | — |
| `storage_path` | Internal | — |
| `prompt` | Internal | — |
| `source` | Internal | — |
| `width` | Internal | — |
| `height` | Internal | — |
| `meta` | Internal | — |
| `created_by` | Internal | — |
| `created_at` | Public | — |
| `updated_at` | Public | — |

### `kit_comments`

| Column | Class | Control |
|---|---|---|
| `id` | Public | — |
| `kit_id` | Internal | — |
| `file_id` | Internal | — |
| `parent_id` | Internal | — |
| `author_id` | Internal | — |
| `body` | Internal | — |
| `anchor` | Internal | — |
| `file_version` | Internal | — |
| `category` | Internal | — |
| `severity` | Internal | — |
| `status` | Internal | — |
| `assignee_id` | Internal | — |
| `resolved_by` | Internal | — |
| `resolved_at` | Internal | — |
| `review_round` | Internal | — |
| `mentions` | Internal | — |
| `created_at` | Public | — |
| `updated_at` | Public | — |

### `kit_file_versions`

| Column | Class | Control |
|---|---|---|
| `id` | Public | — |
| `file_id` | Internal | — |
| `version` | Internal | — |
| `storage_path` | Internal | — |
| `snapshot` | Internal | — |
| `size` | Internal | — |
| `source` | Internal | — |
| `note` | Internal | — |
| `created_by` | Internal | — |
| `created_at` | Public | — |

### `kit_files`

| Column | Class | Control |
|---|---|---|
| `id` | Public | — |
| `kit_id` | Internal | — |
| `name` | Confidential | masked in exports |
| `original_name` | Internal | — |
| `kind` | Internal | — |
| `category` | Internal | — |
| `source` | Internal | — |
| `mime` | Internal | — |
| `size` | Internal | — |
| `storage_path` | Internal | — |
| `content` | Internal | — |
| `revision` | Internal | — |
| `version` | Internal | — |
| `notes` | Internal | — |
| `sort_order` | Internal | — |
| `uploaded_by` | Internal | — |
| `updated_by` | Internal | — |
| `created_at` | Public | — |
| `updated_at` | Public | — |
| `deleted_at` | Public | — |

### `kit_generations`

| Column | Class | Control |
|---|---|---|
| `id` | Public | — |
| `kit_id` | Internal | — |
| `user_id` | Internal | — |
| `type` | Internal | — |
| `prompt` | Internal | — |
| `params` | Internal | — |
| `result` | Internal | — |
| `provider` | Internal | — |
| `status` | Internal | — |
| `error` | Internal | — |
| `created_at` | Public | — |
| `updated_at` | Public | — |

### `kit_members`

| Column | Class | Control |
|---|---|---|
| `id` | Public | — |
| `kit_id` | Internal | — |
| `user_id` | Internal | — |
| `role` | Internal | — |
| `created_at` | Public | — |
| `updated_at` | Public | — |

### `kit_program`

| Column | Class | Control |
|---|---|---|
| `id` | Public | — |
| `kit_id` | Internal | — |
| `program_id` | Internal | — |
| `pinned_version` | Internal | — |
| `created_at` | Public | — |
| `updated_at` | Public | — |

### `kit_reviews`

| Column | Class | Control |
|---|---|---|
| `id` | Public | — |
| `kit_id` | Internal | — |
| `round` | Internal | — |
| `status` | Internal | — |
| `submitted_by` | Internal | — |
| `decided_by` | Internal | — |
| `submitted_at` | Internal | — |
| `decided_at` | Internal | — |
| `note` | Internal | — |
| `summary` | Internal | — |
| `created_at` | Public | — |
| `updated_at` | Public | — |

### `knowledge_transfers`

| Column | Class | Control |
|---|---|---|
| `id` | Public | — |
| `registration_id` | Internal | — |
| `employee_id` | Internal | — |
| `due_on` | Internal | — |
| `delivered_on` | Internal | — |
| `hours` | Internal | — |
| `beneficiary_count` | Internal | — |
| `beneficiaries` | Internal | — |
| `method` | Internal | — |
| `evidence` | Internal | — |
| `status` | Internal | — |
| `reviewer_id` | Internal | — |
| `note` | Internal | — |
| `reminded_at` | Internal | — |
| `created_at` | Public | — |
| `updated_at` | Public | — |

### `kpi_samples`

| Column | Class | Control |
|---|---|---|
| `id` | Public | — |
| `metric` | Internal | — |
| `value` | Internal | — |
| `window` | Internal | — |
| `meta` | Internal | — |
| `measured_at` | Internal | — |

### `kpi_targets`

| Column | Class | Control |
|---|---|---|
| `metric` | Internal | — |
| `target` | Internal | — |
| `comparator` | Internal | — |
| `editable` | Internal | — |
| `created_at` | Public | — |
| `updated_at` | Public | — |

### `leaderboard_snapshots`

| Column | Class | Control |
|---|---|---|
| `id` | Public | — |
| `period` | Internal | — |
| `scope` | Internal | — |
| `scope_id` | Internal | — |
| `period_start` | Internal | — |
| `ranks` | Internal | — |
| `created_at` | Public | — |
| `updated_at` | Public | — |

### `learner_mastery`

| Column | Class | Control |
|---|---|---|
| `id` | Public | — |
| `registration_id` | Internal | — |
| `skill_id` | Internal | — |
| `mastery` | Internal | — |
| `evidence_count` | Internal | — |
| `created_at` | Public | — |
| `updated_at` | Public | — |

### `lesson_notes`

| Column | Class | Control |
|---|---|---|
| `id` | Public | — |
| `user_id` | Internal | — |
| `lesson_id` | Internal | — |
| `body` | Internal | — |
| `created_at` | Public | — |
| `updated_at` | Public | — |

### `lesson_progress`

| Column | Class | Control |
|---|---|---|
| `id` | Public | — |
| `lesson_id` | Internal | — |
| `registration_id` | Internal | — |
| `employee_id` | Internal | — |
| `status` | Internal | — |
| `percent` | Internal | — |
| `last_position` | Internal | — |
| `furthest_position` | Internal | — |
| `watched_seconds` | Internal | — |
| `segments` | Internal | — |
| `sessions` | Internal | — |
| `best_score` | Internal | — |
| `attempts` | Internal | — |
| `first_opened_at` | Internal | — |
| `last_activity_at` | Internal | — |
| `completed_at` | Internal | — |
| `created_at` | Public | — |
| `updated_at` | Public | — |
| `lesson_version` | Internal | — |

### `levels`

| Column | Class | Control |
|---|---|---|
| `level_no` | Internal | — |
| `name_ar` | Confidential | masked in exports |
| `name_en` | Confidential | masked in exports |
| `min_points` | Internal | — |
| `icon` | Internal | — |
| `created_at` | Public | — |
| `updated_at` | Public | — |

### `library_collection_items`

| Column | Class | Control |
|---|---|---|
| `collection_id` | Internal | — |
| `item_id` | Internal | — |
| `sort_order` | Internal | — |

### `library_collections`

| Column | Class | Control |
|---|---|---|
| `id` | Public | — |
| `name_ar` | Confidential | masked in exports |
| `name_en` | Confidential | masked in exports |
| `is_featured` | Internal | — |
| `sort_order` | Internal | — |
| `created_at` | Public | — |
| `updated_at` | Public | — |

### `library_items`

| Column | Class | Control |
|---|---|---|
| `id` | Public | — |
| `type` | Internal | — |
| `title_ar` | Internal | — |
| `title_en` | Internal | — |
| `description_ar` | Internal | — |
| `description_en` | Internal | — |
| `authors` | Internal | — |
| `publisher` | Internal | — |
| `isbn` | Internal | — |
| `year` | Internal | — |
| `language` | Internal | — |
| `subjects` | Internal | — |
| `skill_ids` | Internal | — |
| `cover_path` | Internal | — |
| `file_path` | Internal | — |
| `file_mime` | Internal | — |
| `url` | Internal | — |
| `source` | Internal | — |
| `external_id` | Internal | — |
| `rights` | Internal | — |
| `audience` | Internal | — |
| `status` | Internal | — |
| `search_text` | Internal | — |
| `views` | Internal | — |
| `downloads` | Internal | — |
| `created_by` | Internal | — |
| `created_at` | Public | — |
| `updated_at` | Public | — |

### `library_reviews`

| Column | Class | Control |
|---|---|---|
| `id` | Public | — |
| `item_id` | Internal | — |
| `user_id` | Internal | — |
| `stars` | Internal | — |
| `review` | Internal | — |
| `created_at` | Public | — |
| `updated_at` | Public | — |

### `library_shelf`

| Column | Class | Control |
|---|---|---|
| `id` | Public | — |
| `item_id` | Internal | — |
| `user_id` | Internal | — |
| `progress` | Internal | — |
| `position` | Internal | — |
| `created_at` | Public | — |
| `updated_at` | Public | — |

### `logistics_requests`

| Column | Class | Control |
|---|---|---|
| `id` | Public | — |
| `session_id` | Internal | — |
| `group_id` | Internal | — |
| `booking_id` | Internal | — |
| `requested_by` | Internal | — |
| `items` | Internal | — |
| `notes` | Internal | — |
| `needed_by` | Internal | — |
| `status` | Internal | — |
| `assignee_id` | Internal | — |
| `completed_at` | Internal | — |
| `comment` | Internal | — |
| `overdue_notified_at` | Internal | — |
| `created_at` | Public | — |
| `updated_at` | Public | — |

### `lti_scores`

| Column | Class | Control |
|---|---|---|
| `id` | Public | — |
| `tool_id` | Internal | — |
| `lesson_id` | Internal | — |
| `user_id` | Internal | — |
| `score_given` | Internal | — |
| `score_max` | Internal | — |
| `activity_progress` | Internal | — |
| `grading_progress` | Internal | — |
| `created_at` | Public | — |
| `updated_at` | Public | — |

### `lti_states`

| Column | Class | Control |
|---|---|---|
| `id` | Public | — |
| `state` | Internal | — |
| `nonce` | Internal | — |
| `tool_id` | Internal | — |
| `user_id` | Internal | — |
| `lesson_id` | Internal | — |
| `purpose` | Internal | — |
| `context` | Internal | — |
| `expires_at` | Internal | — |
| `used_at` | Internal | — |
| `created_at` | Public | — |
| `updated_at` | Public | — |

### `lti_tools`

| Column | Class | Control |
|---|---|---|
| `id` | Public | — |
| `name` | Confidential | masked in exports |
| `version` | Internal | — |
| `client_id` | Internal | — |
| `deployment_id` | Internal | — |
| `login_url` | Internal | — |
| `launch_url` | Internal | — |
| `jwks_url` | Internal | — |
| `public_key` | Internal | — |
| `deep_link_url` | Internal | — |
| `consumer_key` | Internal | — |
| `consumer_secret` | Restricted | application-level encryption |
| `custom` | Internal | — |
| `privacy` | Internal | — |
| `supports_ags` | Internal | — |
| `is_active` | Internal | — |
| `created_at` | Public | — |
| `updated_at` | Public | — |

### `materials`

| Column | Class | Control |
|---|---|---|
| `id` | Public | — |
| `program_id` | Internal | — |
| `program_session_id` | Internal | — |
| `title_en` | Internal | — |
| `title_ar` | Internal | — |
| `type` | Internal | — |
| `storage_path` | Internal | — |
| `url` | Internal | — |
| `mime` | Internal | — |
| `size` | Internal | — |
| `visibility` | Internal | — |
| `uploaded_by` | Internal | — |
| `created_at` | Public | — |
| `updated_at` | Public | — |
| `training_group_id` | Internal | — |

### `mfa_challenges`

| Column | Class | Control |
|---|---|---|
| `id` | Public | — |
| `user_id` | Internal | — |
| `method` | Internal | — |
| `code_hash` | Restricted | hash / secret / expiring token |
| `attempts` | Internal | — |
| `expires_at` | Internal | — |
| `created_at` | Public | — |
| `updated_at` | Public | — |

### `mfa_devices`

| Column | Class | Control |
|---|---|---|
| `id` | Public | — |
| `user_id` | Internal | — |
| `token_hash` | Restricted | hash / secret / expiring token |
| `label` | Internal | — |
| `expires_at` | Internal | — |
| `last_used_at` | Internal | — |
| `created_at` | Public | — |
| `updated_at` | Public | — |

### `migration_batches`

| Column | Class | Control |
|---|---|---|
| `id` | Public | — |
| `kind` | Internal | — |
| `filename` | Internal | — |
| `checksum` | Internal | — |
| `status` | Internal | — |
| `mapping` | Internal | — |
| `value_maps` | Internal | — |
| `defaults` | Internal | — |
| `total_rows` | Internal | — |
| `valid_rows` | Internal | — |
| `invalid_rows` | Internal | — |
| `duplicate_rows` | Internal | — |
| `created_rows` | Internal | — |
| `updated_rows` | Internal | — |
| `report` | Internal | — |
| `created_by` | Internal | — |
| `imported_at` | Internal | — |
| `rolled_back_at` | Internal | — |
| `expires_at` | Internal | — |
| `created_at` | Public | — |
| `updated_at` | Public | — |

### `migration_rows`

| Column | Class | Control |
|---|---|---|
| `id` | Public | — |
| `batch_id` | Internal | — |
| `row_no` | Internal | — |
| `data` | Internal | — |
| `mapped` | Internal | — |
| `row_key` | Internal | — |
| `status` | Internal | — |
| `errors` | Internal | — |
| `action` | Internal | — |
| `target_id` | Internal | — |
| `before` | Internal | — |
| `created_ids` | Internal | — |
| `created_at` | Public | — |
| `updated_at` | Public | — |

### `migrations`

| Column | Class | Control |
|---|---|---|
| `id` | Internal | — |
| `migration` | Internal | — |
| `batch` | Internal | — |

### `ministry_exports`

| Column | Class | Control |
|---|---|---|
| `id` | Public | — |
| `announcement_id` | Internal | — |
| `status` | Internal | — |
| `attempts` | Internal | — |
| `last_error` | Internal | — |
| `next_attempt_at` | Internal | — |
| `sent_at` | Internal | — |
| `created_at` | Public | — |
| `updated_at` | Public | — |

### `needs_cycles`

| Column | Class | Control |
|---|---|---|
| `id` | Public | — |
| `year` | Internal | — |
| `title_ar` | Internal | — |
| `title_en` | Internal | — |
| `opens_at` | Internal | — |
| `closes_at` | Internal | — |
| `status` | Internal | — |
| `plan_id` | Internal | — |
| `settings` | Internal | — |
| `closing_reminded_at` | Internal | — |
| `created_at` | Public | — |
| `updated_at` | Public | — |

### `needs_rules`

| Column | Class | Control |
|---|---|---|
| `id` | Public | — |
| `name_ar` | Confidential | masked in exports |
| `name_en` | Confidential | masked in exports |
| `trigger` | Internal | — |
| `conditions` | Internal | — |
| `action` | Internal | — |
| `is_active` | Internal | — |
| `sort_order` | Internal | — |
| `last_run_at` | Internal | — |
| `created_at` | Public | — |
| `updated_at` | Public | — |

### `needs_survey_recipients`

| Column | Class | Control |
|---|---|---|
| `id` | Public | — |
| `survey_id` | Internal | — |
| `user_id` | Internal | — |
| `employee_id` | Internal | — |
| `notified_at` | Internal | — |
| `reminded_at` | Internal | — |
| `responded_at` | Internal | — |
| `created_at` | Public | — |
| `updated_at` | Public | — |

### `needs_survey_responses`

| Column | Class | Control |
|---|---|---|
| `id` | Public | — |
| `survey_id` | Internal | — |
| `user_id` | Internal | — |
| `school_id` | Internal | — |
| `job_title_id` | Internal | — |
| `experience_years` | Internal | — |
| `specialization` | Internal | — |
| `nationality` | Confidential | masked in exports |
| `gender` | Confidential | masked in exports |
| `education_stage` | Internal | — |
| `answers` | Internal | — |
| `duration_seconds` | Internal | — |
| `submitted_at` | Internal | — |
| `created_at` | Public | — |
| `updated_at` | Public | — |

### `needs_surveys`

| Column | Class | Control |
|---|---|---|
| `id` | Public | — |
| `title` | Internal | — |
| `description` | Internal | — |
| `status` | Internal | — |
| `source` | Internal | — |
| `template_key` | Internal | — |
| `questions` | Internal | — |
| `audience` | Internal | — |
| `settings` | Internal | — |
| `created_by` | Internal | — |
| `published_at` | Internal | — |
| `closes_at` | Internal | — |
| `closed_at` | Internal | — |
| `created_at` | Public | — |
| `updated_at` | Public | — |
| `approval_status` | Internal | — |
| `approved_by` | Internal | — |
| `approved_at` | Internal | — |
| `approval_note` | Internal | — |

### `nominations`

| Column | Class | Control |
|---|---|---|
| `id` | Public | — |
| `program_id` | Internal | — |
| `employee_id` | Internal | — |
| `school_id` | Internal | — |
| `nominated_by` | Internal | — |
| `nominator_type` | Internal | — |
| `justification` | Internal | — |
| `status` | Internal | — |
| `decided_at` | Internal | — |
| `created_at` | Public | — |
| `updated_at` | Public | — |
| `training_group_id` | Internal | — |

### `notification_campaigns`

| Column | Class | Control |
|---|---|---|
| `id` | Public | — |
| `event` | Internal | — |
| `kind` | Internal | — |
| `program_id` | Internal | — |
| `audience` | Internal | — |
| `title_ar` | Internal | — |
| `title_en` | Internal | — |
| `body_ar` | Internal | — |
| `body_en` | Internal | — |
| `recipients` | Internal | — |
| `created_by` | Internal | — |
| `created_at` | Public | — |
| `updated_at` | Public | — |

### `notification_deliveries`

| Column | Class | Control |
|---|---|---|
| `id` | Public | — |
| `notification_id` | Internal | — |
| `user_id` | Internal | — |
| `channel` | Internal | — |
| `type` | Internal | — |
| `status` | Internal | — |
| `reason` | Internal | — |
| `to` | Internal | — |
| `attempts` | Internal | — |
| `sent_at` | Internal | — |
| `created_at` | Public | — |
| `updated_at` | Public | — |
| `read_at` | Internal | — |
| `delivered_at` | Internal | — |
| `failed_reason` | Internal | — |
| `provider_message_id` | Internal | — |
| `campaign_id` | Internal | — |
| `not_before` | Internal | — |

### `notification_rules`

| Column | Class | Control |
|---|---|---|
| `id` | Public | — |
| `event` | Internal | — |
| `name` | Confidential | masked in exports |
| `audience_filter` | Internal | — |
| `channels` | Internal | — |
| `enabled` | Internal | — |
| `quiet_hours` | Internal | — |
| `delay_minutes` | Internal | — |
| `program_id` | Internal | — |
| `category_id` | Internal | — |
| `priority` | Internal | — |
| `created_at` | Public | — |
| `updated_at` | Public | — |

### `notification_templates`

| Column | Class | Control |
|---|---|---|
| `id` | Public | — |
| `event` | Internal | — |
| `is_system` | Internal | — |
| `name_ar` | Confidential | masked in exports |
| `name_en` | Confidential | masked in exports |
| `title_ar` | Internal | — |
| `title_en` | Internal | — |
| `body_ar` | Internal | — |
| `body_en` | Internal | — |
| `enabled` | Internal | — |
| `push` | Internal | — |
| `updated_by` | Internal | — |
| `created_at` | Public | — |
| `updated_at` | Public | — |
| `email` | Confidential | masked in exports |
| `sms` | Internal | — |

### `notifications`

| Column | Class | Control |
|---|---|---|
| `id` | Confidential | masked in exports |
| `user_id` | Confidential | masked in exports |
| `type` | Confidential | masked in exports |
| `title_en` | Confidential | masked in exports |
| `title_ar` | Confidential | masked in exports |
| `body_en` | Confidential | masked in exports |
| `body_ar` | Confidential | masked in exports |
| `data` | Confidential | masked in exports |
| `read_at` | Confidential | masked in exports |
| `created_at` | Confidential | masked in exports |
| `updated_at` | Confidential | masked in exports |
| `campaign_id` | Confidential | masked in exports |
| `seen_at` | Confidential | masked in exports |

### `offline_sync_log`

| Column | Class | Control |
|---|---|---|
| `id` | Public | — |
| `idempotency_key` | Internal | — |
| `user_id` | Internal | — |
| `result` | Internal | — |
| `created_at` | Public | — |
| `updated_at` | Public | — |

### `orders`

| Column | Class | Control |
|---|---|---|
| `id` | Confidential | masked in exports |
| `number` | Confidential | masked in exports |
| `buyer_type` | Confidential | masked in exports |
| `user_id` | Confidential | masked in exports |
| `entity_account_id` | Confidential | masked in exports |
| `items` | Confidential | masked in exports |
| `subtotal` | Confidential | masked in exports |
| `discount` | Confidential | masked in exports |
| `vat` | Confidential | masked in exports |
| `total` | Confidential | masked in exports |
| `currency` | Confidential | masked in exports |
| `discount_code` | Confidential | masked in exports |
| `status` | Confidential | masked in exports |
| `expires_at` | Confidential | masked in exports |
| `paid_at` | Confidential | masked in exports |
| `invoice_no` | Confidential | masked in exports |
| `invoice_pdf_path` | Confidential | masked in exports |
| `created_at` | Confidential | masked in exports |
| `updated_at` | Confidential | masked in exports |

### `outbox_events`

| Column | Class | Control |
|---|---|---|
| `id` | Public | — |
| `type` | Internal | — |
| `payload` | Internal | — |
| `correlation_id` | Internal | — |
| `occurred_at` | Internal | — |

### `page_blocks`

| Column | Class | Control |
|---|---|---|
| `id` | Public | — |
| `page` | Internal | — |
| `type` | Internal | — |
| `config` | Internal | — |
| `sort_order` | Internal | — |
| `is_visible` | Internal | — |
| `audience` | Internal | — |
| `starts_at` | Internal | — |
| `ends_at` | Internal | — |
| `updated_by` | Internal | — |
| `created_at` | Public | — |
| `updated_at` | Public | — |

### `page_versions`

| Column | Class | Control |
|---|---|---|
| `id` | Public | — |
| `page` | Internal | — |
| `version` | Internal | — |
| `blocks` | Internal | — |
| `note` | Internal | — |
| `published_by` | Internal | — |
| `created_at` | Public | — |
| `updated_at` | Public | — |

### `partner_organizations`

| Column | Class | Control |
|---|---|---|
| `id` | Public | — |
| `name_ar` | Confidential | masked in exports |
| `name_en` | Confidential | masked in exports |
| `type` | Internal | — |
| `country` | Internal | — |
| `contact_name` | Internal | — |
| `email` | Confidential | masked in exports |
| `phone` | Confidential | masked in exports |
| `website` | Internal | — |
| `notes` | Internal | — |
| `status` | Internal | — |
| `created_at` | Public | — |
| `updated_at` | Public | — |

### `pass_exceptions`

| Column | Class | Control |
|---|---|---|
| `id` | Public | — |
| `registration_id` | Internal | — |
| `criterion` | Internal | — |
| `reason` | Internal | — |
| `attachment_path` | Internal | — |
| `attachment_name` | Internal | — |
| `granted_by` | Internal | — |
| `granted_at` | Internal | — |
| `revoked_at` | Internal | — |
| `revoked_by` | Internal | — |
| `created_at` | Public | — |
| `updated_at` | Public | — |

### `passing_policies`

| Column | Class | Control |
|---|---|---|
| `id` | Public | — |
| `scope` | Internal | — |
| `scope_id` | Internal | — |
| `mode` | Internal | — |
| `criteria` | Internal | — |
| `pass_threshold` | Internal | — |
| `assessment_ids` | Internal | — |
| `participation_rules` | Internal | — |
| `allow_test_out` | Internal | — |
| `test_out_assessment_id` | Internal | — |
| `hours_mode` | Internal | — |
| `certificate_types` | Internal | — |
| `attendance_certificate_min` | Internal | — |
| `survey_required_for_download` | Internal | — |
| `task_approval` | Internal | — |
| `certificate_templates` | Internal | — |
| `updated_by` | Internal | — |
| `created_at` | Public | — |
| `updated_at` | Public | — |

### `password_history`

| Column | Class | Control |
|---|---|---|
| `id` | Public | — |
| `user_id` | Internal | — |
| `hash` | Internal | — |
| `created_at` | Public | — |

### `password_reset_tokens`

| Column | Class | Control |
|---|---|---|
| `email` | Confidential | masked in exports |
| `token` | Restricted | hash / secret / expiring token |
| `created_at` | Public | — |

### `payment_events`

| Column | Class | Control |
|---|---|---|
| `id` | Public | — |
| `gateway` | Internal | — |
| `event_id` | Internal | — |
| `kind` | Internal | — |
| `signature_valid` | Internal | — |
| `outcome` | Internal | — |
| `created_at` | Public | — |
| `updated_at` | Public | — |

### `payment_reconciliations`

| Column | Class | Control |
|---|---|---|
| `id` | Public | — |
| `day` | Internal | — |
| `matched` | Internal | — |
| `fixed` | Internal | — |
| `mismatches` | Internal | — |
| `created_at` | Public | — |
| `updated_at` | Public | — |

### `payments`

| Column | Class | Control |
|---|---|---|
| `id` | Confidential | masked in exports |
| `order_id` | Confidential | masked in exports |
| `gateway` | Confidential | masked in exports |
| `gateway_ref` | Confidential | masked in exports |
| `amount` | Confidential | masked in exports |
| `status` | Confidential | masked in exports |
| `raw` | Confidential | masked in exports |
| `signature_valid` | Confidential | masked in exports |
| `captured_at` | Confidential | masked in exports |
| `created_at` | Confidential | masked in exports |
| `updated_at` | Confidential | masked in exports |

### `pd_activities`

| Column | Class | Control |
|---|---|---|
| `id` | Confidential | masked in exports |
| `employee_id` | Confidential | masked in exports |
| `type_id` | Confidential | masked in exports |
| `title` | Confidential | masked in exports |
| `provider` | Confidential | masked in exports |
| `domain` | Confidential | masked in exports |
| `skill_ids` | Confidential | masked in exports |
| `starts_on` | Confidential | masked in exports |
| `ends_on` | Confidential | masked in exports |
| `duration_hours` | Confidential | masked in exports |
| `participation_level` | Confidential | masked in exports |
| `location` | Confidential | masked in exports |
| `evidence` | Confidential | masked in exports |
| `computed_hours` | Confidential | masked in exports |
| `approved_hours` | Confidential | masked in exports |
| `status` | Confidential | masked in exports |
| `manager_id` | Confidential | masked in exports |
| `manager_note` | Confidential | masked in exports |
| `decided_at` | Confidential | masked in exports |
| `recognition_request` | Confidential | masked in exports |
| `created_at` | Confidential | masked in exports |
| `updated_at` | Confidential | masked in exports |

### `pd_activity_types`

| Column | Class | Control |
|---|---|---|
| `id` | Public | — |
| `code` | Internal | — |
| `name_ar` | Confidential | masked in exports |
| `name_en` | Confidential | masked in exports |
| `hour_rules` | Internal | — |
| `evidence_required` | Internal | — |
| `is_active` | Internal | — |
| `created_at` | Public | — |
| `updated_at` | Public | — |

### `pd_annual_targets`

| Column | Class | Control |
|---|---|---|
| `id` | Public | — |
| `year` | Internal | — |
| `audience` | Internal | — |
| `min_hours` | Internal | — |
| `counts` | Internal | — |
| `created_at` | Public | — |
| `updated_at` | Public | — |

### `pd_recognition_requests`

| Column | Class | Control |
|---|---|---|
| `id` | Public | — |
| `pd_activity_id` | Internal | — |
| `requested_equivalent_program_id` | Internal | — |
| `center_decision` | Internal | — |
| `recognised_hours` | Internal | — |
| `equivalent_program_ids` | Internal | — |
| `decided_by` | Internal | — |
| `note` | Internal | — |
| `decided_at` | Internal | — |
| `created_at` | Public | — |
| `updated_at` | Public | — |

### `performance_appraisals`

| Column | Class | Control |
|---|---|---|
| `id` | Public | — |
| `employee_id` | Internal | — |
| `year` | Internal | — |
| `rating_code` | Internal | — |
| `score` | Internal | — |
| `source` | Internal | — |
| `imported_at` | Internal | — |
| `created_at` | Public | — |
| `updated_at` | Public | — |

### `permission_role`

| Column | Class | Control |
|---|---|---|
| `permission_id` | Internal | — |
| `role_id` | Internal | — |

### `permissions`

| Column | Class | Control |
|---|---|---|
| `id` | Public | — |
| `slug` | Internal | — |
| `name_en` | Confidential | masked in exports |
| `name_ar` | Confidential | masked in exports |
| `group` | Internal | — |
| `created_at` | Public | — |
| `updated_at` | Public | — |

### `point_ledger`

| Column | Class | Control |
|---|---|---|
| `id` | Public | — |
| `user_id` | Internal | — |
| `event` | Internal | — |
| `points` | Internal | — |
| `source_type` | Internal | — |
| `source_id` | Internal | — |
| `note` | Internal | — |
| `created_by` | Internal | — |
| `created_at` | Public | — |

### `poll_votes`

| Column | Class | Control |
|---|---|---|
| `id` | Public | — |
| `poll_id` | Internal | — |
| `user_id` | Internal | — |
| `option_ids` | Internal | — |
| `created_at` | Public | — |
| `updated_at` | Public | — |

### `polls`

| Column | Class | Control |
|---|---|---|
| `id` | Public | — |
| `post_id` | Internal | — |
| `question` | Internal | — |
| `options` | Internal | — |
| `multiple` | Internal | — |
| `anonymous` | Internal | — |
| `closes_at` | Internal | — |
| `created_at` | Public | — |
| `updated_at` | Public | — |

### `posts`

| Column | Class | Control |
|---|---|---|
| `id` | Public | — |
| `space_id` | Internal | — |
| `author_id` | Internal | — |
| `kind` | Internal | — |
| `title` | Internal | — |
| `body` | Internal | — |
| `attachments` | Internal | — |
| `is_pinned` | Internal | — |
| `is_locked` | Internal | — |
| `accepted_answer_id` | Internal | — |
| `status` | Internal | — |
| `edits` | Internal | — |
| `edited_at` | Internal | — |
| `comments_count` | Internal | — |
| `reactions_count` | Internal | — |
| `created_at` | Public | — |
| `updated_at` | Public | — |

### `presence_sessions`

| Column | Class | Control |
|---|---|---|
| `id` | Public | — |
| `user_id` | Internal | — |
| `platform` | Internal | — |
| `team` | Internal | — |
| `role_label` | Internal | — |
| `started_at` | Internal | — |
| `last_seen_at` | Internal | — |
| `hits` | Internal | — |
| `idle_seconds` | Internal | — |
| `last_path` | Internal | — |
| `device` | Internal | — |
| `app_version` | Internal | — |
| `source` | Internal | — |
| `country` | Internal | — |
| `region` | Internal | — |
| `city` | Internal | — |
| `lat` | Internal | — |
| `lng` | Internal | — |

### `price_lists`

| Column | Class | Control |
|---|---|---|
| `id` | Public | — |
| `program_id` | Internal | — |
| `group_id` | Internal | — |
| `currency` | Internal | — |
| `rules` | Internal | — |
| `default_price` | Internal | — |
| `vat_rate` | Internal | — |
| `refund_policy` | Internal | — |
| `is_active` | Internal | — |
| `created_at` | Public | — |
| `updated_at` | Public | — |

### `professional_licences`

| Column | Class | Control |
|---|---|---|
| `id` | Public | — |
| `employee_id` | Internal | — |
| `path_id` | Internal | — |
| `level_no` | Internal | — |
| `licence_no` | Internal | — |
| `issued_at` | Internal | — |
| `expires_at` | Internal | — |
| `status` | Internal | — |
| `source` | Internal | — |
| `synced_at` | Internal | — |
| `reminded` | Internal | — |
| `created_at` | Public | — |
| `updated_at` | Public | — |

### `profile_change_requests`

| Column | Class | Control |
|---|---|---|
| `id` | Public | — |
| `user_id` | Internal | — |
| `field` | Internal | — |
| `kind` | Internal | — |
| `current_value` | Internal | — |
| `requested_value` | Internal | — |
| `note` | Internal | — |
| `status` | Internal | — |
| `applied` | Internal | — |
| `reviewed_by` | Internal | — |
| `reviewed_at` | Internal | — |
| `review_note` | Internal | — |
| `created_at` | Public | — |
| `updated_at` | Public | — |

### `program_categories`

| Column | Class | Control |
|---|---|---|
| `id` | Public | — |
| `slug` | Public | — |
| `name_en` | Confidential | masked in exports |
| `name_ar` | Confidential | masked in exports |
| `icon` | Public | — |
| `color` | Public | — |
| `created_at` | Public | — |
| `updated_at` | Public | — |

### `program_equivalences`

| Column | Class | Control |
|---|---|---|
| `id` | Public | — |
| `program_id` | Internal | — |
| `equivalent_program_id` | Internal | — |
| `bidirectional` | Internal | — |
| `note` | Internal | — |
| `created_at` | Public | — |
| `updated_at` | Public | — |

### `program_evaluation_reports`

| Column | Class | Control |
|---|---|---|
| `id` | Public | — |
| `program_id` | Internal | — |
| `group_id` | Internal | — |
| `period` | Internal | — |
| `metrics` | Internal | — |
| `qualitative` | Internal | — |
| `classification` | Internal | — |
| `classification_reasons` | Internal | — |
| `recommendations_ar` | Internal | — |
| `recommendations_en` | Internal | — |
| `status` | Internal | — |
| `prepared_by` | Internal | — |
| `approved_by` | Internal | — |
| `approved_at` | Internal | — |
| `created_at` | Public | — |
| `updated_at` | Public | — |

### `program_grants`

| Column | Class | Control |
|---|---|---|
| `id` | Public | — |
| `program_id` | Internal | — |
| `user_id` | Internal | — |
| `ability` | Internal | — |
| `granted_by` | Internal | — |
| `expires_at` | Internal | — |
| `created_at` | Public | — |
| `updated_at` | Public | — |

### `program_proposals`

| Column | Class | Control |
|---|---|---|
| `id` | Public | — |
| `cycle_id` | Internal | — |
| `entity_type` | Internal | — |
| `entity_id` | Internal | — |
| `entity_name` | Internal | — |
| `submitted_by` | Internal | — |
| `program_title_ar` | Internal | — |
| `program_title_en` | Internal | — |
| `existing_program_id` | Internal | — |
| `groups_count` | Internal | — |
| `axes` | Internal | — |
| `target_job_title_ids` | Internal | — |
| `target_description` | Internal | — |
| `days` | Internal | — |
| `hours` | Internal | — |
| `kit_availability` | Internal | — |
| `kit_attachment_path` | Internal | — |
| `trainer_nominations` | Internal | — |
| `importance` | Internal | — |
| `priority_rank` | Internal | — |
| `justification` | Internal | — |
| `status` | Internal | — |
| `reviewer_id` | Internal | — |
| `review_note` | Internal | — |
| `plan_item_id` | Internal | — |
| `created_at` | Public | — |
| `updated_at` | Public | — |

### `program_sessions`

| Column | Class | Control |
|---|---|---|
| `id` | Public | — |
| `program_id` | Internal | — |
| `trainer_id` | Internal | — |
| `training_room_id` | Internal | — |
| `sequence` | Internal | — |
| `title_en` | Internal | — |
| `title_ar` | Internal | — |
| `description` | Internal | — |
| `starts_at` | Internal | — |
| `ends_at` | Internal | — |
| `location_text` | Internal | — |
| `online_url` | Internal | — |
| `activities` | Internal | — |
| `status` | Internal | — |
| `qr_secret` | Restricted | hash / secret / expiring token |
| `created_at` | Public | — |
| `updated_at` | Public | — |
| `mode` | Internal | — |
| `online_platform` | Internal | — |
| `online_passcode` | Internal | — |
| `recording_url` | Internal | — |
| `training_group_id` | Internal | — |
| `checkin_window_minutes` | Internal | — |
| `checkout_window_minutes` | Internal | — |

### `program_skill`

| Column | Class | Control |
|---|---|---|
| `program_id` | Internal | — |
| `skill_id` | Internal | — |
| `target_level` | Internal | — |

### `program_trainer`

| Column | Class | Control |
|---|---|---|
| `program_id` | Internal | — |
| `trainer_id` | Internal | — |
| `role` | Internal | — |

### `program_unit_skill`

| Column | Class | Control |
|---|---|---|
| `program_unit_id` | Internal | — |
| `skill_id` | Internal | — |

### `program_units`

| Column | Class | Control |
|---|---|---|
| `id` | Public | — |
| `program_id` | Internal | — |
| `sort_order` | Internal | — |
| `title_ar` | Internal | — |
| `title_en` | Internal | — |
| `objectives` | Internal | — |
| `hours` | Internal | — |
| `summary_ar` | Internal | — |
| `summary_en` | Internal | — |
| `created_at` | Public | — |
| `updated_at` | Public | — |

### `programs`

| Column | Class | Control |
|---|---|---|
| `id` | Public | — |
| `code` | Public | — |
| `category_id` | Public | — |
| `title_en` | Public | — |
| `title_ar` | Public | — |
| `summary_en` | Public | — |
| `summary_ar` | Public | — |
| `description_en` | Public | — |
| `description_ar` | Public | — |
| `objectives` | Public | — |
| `delivery_mode` | Public | — |
| `level` | Public | — |
| `total_hours` | Public | — |
| `capacity` | Public | — |
| `min_attendance_percent` | Public | — |
| `requires_tasks` | Public | — |
| `requires_evaluation` | Public | — |
| `start_date` | Public | — |
| `end_date` | Public | — |
| `registration_opens_at` | Public | — |
| `registration_closes_at` | Public | — |
| `registration_modes` | Public | — |
| `status` | Public | — |
| `cover_path` | Public | — |
| `is_featured` | Public | — |
| `created_by` | Public | — |
| `created_at` | Public | — |
| `updated_at` | Public | — |
| `deleted_at` | Public | — |
| `audience` | Public | — |
| `source_type` | Public | — |
| `survey_mode` | Public | — |
| `survey_auto_hours` | Public | — |
| `survey_opened_at` | Public | — |
| `survey_closed_at` | Public | — |
| `remote` | Public | — |
| `certificate_template_id` | Public | — |
| `trainer_certificate_template_id` | Public | — |
| `has_course` | Public | — |
| `course_sequential` | Public | — |
| `course_completion_percent` | Public | — |
| `course_auto_certificate` | Public | — |
| `coordinator_id` | Public | — |
| `require_biometric` | Public | — |
| `parent_id` | Public | — |
| `kind` | Public | — |
| `axes` | Public | — |
| `is_emergency` | Public | — |
| `emergency_reason` | Public | — |
| `owner_type` | Public | — |
| `owner_school_id` | Public | — |
| `approval_status` | Public | — |
| `repeat_policy` | Public | — |
| `knowledge_transfer` | Public | — |
| `external_platform` | Public | — |

### `public_stats`

| Column | Class | Control |
|---|---|---|
| `id` | Public | — |
| `key` | Internal | — |
| `label_ar` | Internal | — |
| `label_en` | Internal | — |
| `source` | Internal | — |
| `value` | Internal | — |
| `icon` | Internal | — |
| `sort_order` | Internal | — |
| `is_visible` | Internal | — |
| `created_at` | Public | — |
| `updated_at` | Public | — |

### `push_logs`

| Column | Class | Control |
|---|---|---|
| `id` | Public | — |
| `type` | Internal | — |
| `title` | Internal | — |
| `recipients` | Internal | — |
| `devices` | Internal | — |
| `delivered` | Internal | — |
| `failed` | Internal | — |
| `pruned` | Internal | — |
| `error` | Internal | — |
| `triggered_by` | Internal | — |
| `created_at` | Public | — |
| `updated_at` | Public | — |

### `question_banks`

| Column | Class | Control |
|---|---|---|
| `id` | Public | — |
| `title_ar` | Internal | — |
| `title_en` | Internal | — |
| `program_id` | Internal | — |
| `owner_id` | Internal | — |
| `visibility` | Internal | — |
| `description` | Internal | — |
| `created_at` | Public | — |
| `updated_at` | Public | — |

### `questions`

| Column | Class | Control |
|---|---|---|
| `id` | Public | — |
| `bank_id` | Internal | — |
| `category_id` | Internal | — |
| `root_id` | Internal | — |
| `type` | Internal | — |
| `stem_ar` | Internal | — |
| `stem_en` | Internal | — |
| `media` | Internal | — |
| `payload` | Internal | — |
| `points` | Internal | — |
| `difficulty` | Internal | — |
| `skill_ids` | Internal | — |
| `explanation_ar` | Internal | — |
| `explanation_en` | Internal | — |
| `tags` | Internal | — |
| `version` | Internal | — |
| `status` | Internal | — |
| `author_id` | Internal | — |
| `content_hash` | Internal | — |
| `created_at` | Public | — |
| `updated_at` | Public | — |

### `quiz_attempts`

| Column | Class | Control |
|---|---|---|
| `id` | Public | — |
| `lesson_id` | Internal | — |
| `registration_id` | Internal | — |
| `answers` | Internal | — |
| `score_percent` | Internal | — |
| `passed` | Internal | — |
| `duration_seconds` | Internal | — |
| `started_at` | Internal | — |
| `submitted_at` | Internal | — |
| `created_at` | Public | — |
| `updated_at` | Public | — |

### `quiz_questions`

| Column | Class | Control |
|---|---|---|
| `id` | Public | — |
| `lesson_id` | Internal | — |
| `type` | Internal | — |
| `text_ar` | Internal | — |
| `text_en` | Internal | — |
| `options` | Internal | — |
| `points` | Internal | — |
| `explanation_ar` | Internal | — |
| `explanation_en` | Internal | — |
| `sort_order` | Internal | — |
| `created_at` | Public | — |
| `updated_at` | Public | — |

### `reactions`

| Column | Class | Control |
|---|---|---|
| `id` | Public | — |
| `target_type` | Internal | — |
| `target_id` | Internal | — |
| `user_id` | Internal | — |
| `type` | Internal | — |
| `created_at` | Public | — |
| `updated_at` | Public | — |

### `refunds`

| Column | Class | Control |
|---|---|---|
| `id` | Confidential | masked in exports |
| `order_id` | Confidential | masked in exports |
| `payment_id` | Confidential | masked in exports |
| `items` | Confidential | masked in exports |
| `amount` | Confidential | masked in exports |
| `reason` | Confidential | masked in exports |
| `status` | Confidential | masked in exports |
| `gateway_ref` | Confidential | masked in exports |
| `credit_note_no` | Confidential | masked in exports |
| `credit_note_pdf_path` | Confidential | masked in exports |
| `decision_note` | Confidential | masked in exports |
| `requested_by` | Confidential | masked in exports |
| `approved_by` | Confidential | masked in exports |
| `created_at` | Confidential | masked in exports |
| `updated_at` | Confidential | masked in exports |

### `registration_forms`

| Column | Class | Control |
|---|---|---|
| `id` | Public | — |
| `slug` | Internal | — |
| `title_ar` | Internal | — |
| `title_en` | Internal | — |
| `intro_ar` | Internal | — |
| `intro_en` | Internal | — |
| `audience` | Internal | — |
| `fields` | Internal | — |
| `conditions` | Internal | — |
| `opens_at` | Internal | — |
| `closes_at` | Internal | — |
| `is_active` | Internal | — |
| `created_at` | Public | — |
| `updated_at` | Public | — |

### `registration_priority_rules`

| Column | Class | Control |
|---|---|---|
| `id` | Public | — |
| `scope` | Internal | — |
| `scope_id` | Internal | — |
| `criteria` | Internal | — |
| `weights` | Internal | — |
| `is_active` | Internal | — |
| `created_at` | Public | — |
| `updated_at` | Public | — |

### `registration_requests`

| Column | Class | Control |
|---|---|---|
| `id` | Public | — |
| `number` | Internal | — |
| `form_id` | Internal | — |
| `data` | Internal | — |
| `email` | Confidential | masked in exports |
| `phone` | Confidential | masked in exports |
| `national_id_hash` | Internal | — |
| `email_verified_at` | Internal | — |
| `status` | Internal | — |
| `reviewer_id` | Internal | — |
| `decision_note` | Internal | — |
| `user_id` | Internal | — |
| `snapshot_path` | Internal | — |
| `created_at` | Public | — |
| `updated_at` | Public | — |

### `registrations`

| Column | Class | Control |
|---|---|---|
| `id` | Confidential | masked in exports |
| `program_id` | Confidential | masked in exports |
| `employee_id` | Confidential | masked in exports |
| `nomination_id` | Confidential | masked in exports |
| `source` | Confidential | masked in exports |
| `status` | Confidential | masked in exports |
| `eligibility_snapshot` | Confidential | masked in exports |
| `approved_by` | Confidential | masked in exports |
| `approved_at` | Confidential | masked in exports |
| `completed_at` | Confidential | masked in exports |
| `attendance_percent` | Confidential | masked in exports |
| `tasks_completed` | Confidential | masked in exports |
| `evaluation_completed` | Confidential | masked in exports |
| `certificate_status` | Confidential | masked in exports |
| `impact_score` | Confidential | masked in exports |
| `notes` | Confidential | masked in exports |
| `created_at` | Confidential | masked in exports |
| `updated_at` | Confidential | masked in exports |
| `course_percent` | Confidential | masked in exports |
| `course_completed` | Confidential | masked in exports |
| `training_group_id` | Confidential | masked in exports |
| `manager_id` | Confidential | masked in exports |
| `manager_decided_at` | Confidential | masked in exports |
| `manager_note` | Confidential | masked in exports |
| `center_decided_by` | Confidential | masked in exports |
| `center_decided_at` | Confidential | masked in exports |
| `seat_entity_type` | Confidential | masked in exports |
| `seat_entity_id` | Confidential | masked in exports |
| `priority_score` | Confidential | masked in exports |
| `priority_explanation` | Confidential | masked in exports |
| `participation_percent` | Confidential | masked in exports |
| `weighted_score` | Confidential | masked in exports |
| `pass_status` | Confidential | masked in exports |
| `passed_via` | Confidential | masked in exports |
| `computed_at` | Confidential | masked in exports |

### `report_definitions`

| Column | Class | Control |
|---|---|---|
| `id` | Public | — |
| `key` | Internal | — |
| `category` | Internal | — |
| `title_ar` | Internal | — |
| `title_en` | Internal | — |
| `description_ar` | Internal | — |
| `description_en` | Internal | — |
| `dataset` | Internal | — |
| `columns` | Internal | — |
| `filters` | Internal | — |
| `group_by` | Internal | — |
| `sort` | Internal | — |
| `chart` | Internal | — |
| `options` | Internal | — |
| `visibility` | Internal | — |
| `roles` | Internal | — |
| `owner_id` | Internal | — |
| `is_system` | Internal | — |
| `created_at` | Public | — |
| `updated_at` | Public | — |

### `report_favorites`

| Column | Class | Control |
|---|---|---|
| `user_id` | Internal | — |
| `definition_id` | Internal | — |

### `report_runs`

| Column | Class | Control |
|---|---|---|
| `id` | Public | — |
| `definition_id` | Internal | — |
| `params` | Internal | — |
| `formats` | Internal | — |
| `status` | Internal | — |
| `rows_count` | Internal | — |
| `file_paths` | Internal | — |
| `personal` | Internal | — |
| `requested_by` | Internal | — |
| `schedule_id` | Internal | — |
| `lang` | Internal | — |
| `started_at` | Internal | — |
| `finished_at` | Internal | — |
| `expires_at` | Internal | — |
| `error` | Internal | — |
| `created_at` | Public | — |
| `updated_at` | Public | — |

### `report_schedules`

| Column | Class | Control |
|---|---|---|
| `id` | Public | — |
| `definition_id` | Internal | — |
| `params` | Internal | — |
| `frequency` | Internal | — |
| `formats` | Internal | — |
| `recipients` | Internal | — |
| `lang` | Internal | — |
| `last_run_at` | Internal | — |
| `next_run_at` | Internal | — |
| `is_active` | Internal | — |
| `last_error` | Internal | — |
| `created_by` | Internal | — |
| `created_at` | Public | — |
| `updated_at` | Public | — |

### `reports`

| Column | Class | Control |
|---|---|---|
| `id` | Public | — |
| `type` | Internal | — |
| `title` | Internal | — |
| `parameters` | Internal | — |
| `payload` | Internal | — |
| `status` | Internal | — |
| `file_path` | Internal | — |
| `generated_by` | Internal | — |
| `created_at` | Public | — |
| `updated_at` | Public | — |

### `request_metrics`

| Column | Class | Control |
|---|---|---|
| `id` | Public | — |
| `route` | Internal | — |
| `duration_ms` | Internal | — |
| `status` | Internal | — |
| `created_at` | Public | — |

### `resource_shares`

| Column | Class | Control |
|---|---|---|
| `id` | Public | — |
| `resource_type` | Internal | — |
| `resource_id` | Internal | — |
| `target_type` | Internal | — |
| `target_id` | Internal | — |
| `permission` | Internal | — |
| `shared_by` | Internal | — |
| `expires_at` | Internal | — |
| `created_at` | Public | — |
| `updated_at` | Public | — |

### `reward_redemptions`

| Column | Class | Control |
|---|---|---|
| `id` | Public | — |
| `reward_id` | Internal | — |
| `user_id` | Internal | — |
| `points` | Internal | — |
| `code` | Internal | — |
| `status` | Internal | — |
| `created_at` | Public | — |
| `updated_at` | Public | — |

### `rewards`

| Column | Class | Control |
|---|---|---|
| `id` | Public | — |
| `title_ar` | Internal | — |
| `title_en` | Internal | — |
| `description_ar` | Internal | — |
| `description_en` | Internal | — |
| `kind` | Internal | — |
| `cost_points` | Internal | — |
| `min_level` | Internal | — |
| `stock` | Internal | — |
| `is_active` | Internal | — |
| `created_at` | Public | — |
| `updated_at` | Public | — |

### `risk_flags`

| Column | Class | Control |
|---|---|---|
| `id` | Public | — |
| `type` | Internal | — |
| `subject_type` | Internal | — |
| `subject_id` | Internal | — |
| `label` | Internal | — |
| `score` | Internal | — |
| `reasons` | Internal | — |
| `resolved_at` | Internal | — |
| `created_at` | Public | — |
| `updated_at` | Public | — |

### `role_user`

| Column | Class | Control |
|---|---|---|
| `id` | Public | — |
| `role_id` | Internal | — |
| `user_id` | Internal | — |
| `scope_type` | Internal | — |
| `scope_id` | Internal | — |
| `granted_by` | Internal | — |
| `granted_at` | Internal | — |
| `expires_at` | Internal | — |
| `created_at` | Public | — |
| `updated_at` | Public | — |

### `roles`

| Column | Class | Control |
|---|---|---|
| `id` | Public | — |
| `slug` | Internal | — |
| `name_en` | Confidential | masked in exports |
| `name_ar` | Confidential | masked in exports |
| `description` | Internal | — |
| `level` | Internal | — |
| `is_system` | Internal | — |
| `created_at` | Public | — |
| `updated_at` | Public | — |
| `scope_levels` | Internal | — |
| `landing_route` | Internal | — |

### `room_bookings`

| Column | Class | Control |
|---|---|---|
| `id` | Public | — |
| `room_id` | Internal | — |
| `purpose` | Internal | — |
| `title` | Internal | — |
| `starts_at` | Internal | — |
| `ends_at` | Internal | — |
| `booked_by` | Internal | — |
| `session_id` | Internal | — |
| `status` | Internal | — |
| `attendees` | Internal | — |
| `notes` | Internal | — |
| `created_at` | Public | — |
| `updated_at` | Public | — |

### `satisfaction_alerts`

| Column | Class | Control |
|---|---|---|
| `id` | Public | — |
| `program_id` | Internal | — |
| `group_id` | Internal | — |
| `response_rate` | Internal | — |
| `average` | Internal | — |
| `threshold` | Internal | — |
| `notified_at` | Internal | — |
| `created_at` | Public | — |
| `updated_at` | Public | — |

### `scheduled_notifications`

| Column | Class | Control |
|---|---|---|
| `id` | Public | — |
| `title_ar` | Internal | — |
| `title_en` | Internal | — |
| `body_ar` | Internal | — |
| `body_en` | Internal | — |
| `template_id` | Internal | — |
| `channels` | Internal | — |
| `audience` | Internal | — |
| `send_at` | Internal | — |
| `repeat` | Internal | — |
| `repeat_until` | Internal | — |
| `status` | Internal | — |
| `campaign_id` | Internal | — |
| `runs` | Internal | — |
| `last_error` | Internal | — |
| `created_by` | Internal | — |
| `created_at` | Public | — |
| `updated_at` | Public | — |

### `school_group_school`

| Column | Class | Control |
|---|---|---|
| `school_group_id` | Internal | — |
| `school_id` | Internal | — |

### `school_groups`

| Column | Class | Control |
|---|---|---|
| `id` | Public | — |
| `code` | Internal | — |
| `name_ar` | Confidential | masked in exports |
| `name_en` | Confidential | masked in exports |
| `type` | Internal | — |
| `description` | Internal | — |
| `created_at` | Public | — |
| `updated_at` | Public | — |

### `schools`

| Column | Class | Control |
|---|---|---|
| `id` | Public | — |
| `code` | Public | — |
| `name_en` | Confidential | masked in exports |
| `name_ar` | Confidential | masked in exports |
| `type` | Public | — |
| `gender` | Confidential | masked in exports |
| `stage` | Public | — |
| `region` | Public | — |
| `district` | Public | — |
| `latitude` | Confidential | masked in exports |
| `longitude` | Confidential | masked in exports |
| `phone` | Confidential | masked in exports |
| `email` | Confidential | masked in exports |
| `logo_path` | Public | — |
| `is_partner` | Public | — |
| `status` | Public | — |
| `created_at` | Public | — |
| `updated_at` | Public | — |
| `moe_no` | Public | — |
| `source` | Public | — |
| `address` | Confidential | masked in exports |
| `website` | Public | — |
| `curriculum` | Public | — |
| `synced_at` | Public | — |

### `scorm_attempts`

| Column | Class | Control |
|---|---|---|
| `id` | Public | — |
| `registration_id` | Internal | — |
| `lesson_id` | Internal | — |
| `package_id` | Internal | — |
| `attempt_no` | Internal | — |
| `cmi` | Internal | — |
| `completion_status` | Internal | — |
| `success_status` | Internal | — |
| `score_raw` | Internal | — |
| `score_min` | Internal | — |
| `score_max` | Internal | — |
| `score_scaled` | Internal | — |
| `total_time` | Internal | — |
| `suspend_data` | Internal | — |
| `location` | Internal | — |
| `started_at` | Internal | — |
| `committed_at` | Internal | — |
| `created_at` | Public | — |
| `updated_at` | Public | — |

### `seat_holds`

| Column | Class | Control |
|---|---|---|
| `id` | Public | — |
| `group_id` | Internal | — |
| `kind` | Internal | — |
| `owner_id` | Internal | — |
| `quantity` | Internal | — |
| `expires_at` | Internal | — |
| `created_at` | Public | — |
| `updated_at` | Public | — |

### `seat_vouchers`

| Column | Class | Control |
|---|---|---|
| `id` | Confidential | masked in exports |
| `order_id` | Confidential | masked in exports |
| `entity_account_id` | Confidential | masked in exports |
| `group_id` | Confidential | masked in exports |
| `code` | Confidential | masked in exports |
| `assigned_employee_id` | Confidential | masked in exports |
| `assigned_email` | Confidential | masked in exports |
| `status` | Confidential | masked in exports |
| `registration_id` | Confidential | masked in exports |
| `expires_at` | Confidential | masked in exports |
| `reminded_at` | Confidential | masked in exports |
| `created_at` | Confidential | masked in exports |
| `updated_at` | Confidential | masked in exports |

### `seating_plans`

| Column | Class | Control |
|---|---|---|
| `id` | Public | — |
| `room_id` | Internal | — |
| `session_id` | Internal | — |
| `group_id` | Internal | — |
| `layout` | Internal | — |
| `assignments` | Internal | — |
| `created_at` | Public | — |
| `updated_at` | Public | — |

### `security_events`

| Column | Class | Control |
|---|---|---|
| `id` | Confidential | masked in exports |
| `type` | Confidential | masked in exports |
| `user_id` | Confidential | masked in exports |
| `outcome` | Confidential | masked in exports |
| `ip` | Confidential | masked in exports |
| `user_agent` | Confidential | masked in exports |
| `request_id` | Confidential | masked in exports |
| `meta` | Confidential | masked in exports |
| `created_at` | Confidential | masked in exports |

### `sessions`

| Column | Class | Control |
|---|---|---|
| `id` | Public | — |
| `user_id` | Internal | — |
| `ip_address` | Confidential | masked in exports |
| `user_agent` | Confidential | masked in exports |
| `payload` | Restricted | hash / secret / expiring token |
| `last_activity` | Internal | — |

### `sharing_policies`

| Column | Class | Control |
|---|---|---|
| `id` | Public | — |
| `role` | Internal | — |
| `resource_types` | Internal | — |
| `target_types` | Internal | — |
| `allow_reshare` | Internal | — |
| `allow_download` | Internal | — |
| `watermark` | Internal | — |
| `created_at` | Public | — |
| `updated_at` | Public | — |

### `siem_outbox`

| Column | Class | Control |
|---|---|---|
| `id` | Confidential | masked in exports |
| `kind` | Confidential | masked in exports |
| `payload` | Confidential | masked in exports |
| `attempts` | Confidential | masked in exports |
| `last_error` | Confidential | masked in exports |
| `sent_at` | Confidential | masked in exports |
| `created_at` | Confidential | masked in exports |

### `site_settings`

| Column | Class | Control |
|---|---|---|
| `key` | Internal | — |
| `value` | Internal | — |
| `updated_by` | Internal | — |
| `created_at` | Internal | — |
| `updated_at` | Internal | — |

### `skills`

| Column | Class | Control |
|---|---|---|
| `id` | Public | — |
| `code` | Internal | — |
| `name_en` | Confidential | masked in exports |
| `name_ar` | Confidential | masked in exports |
| `category` | Internal | — |
| `created_at` | Public | — |
| `updated_at` | Public | — |
| `domain_id` | Internal | — |
| `framework_version` | Internal | — |
| `descriptors` | Internal | — |
| `licence_relevant` | Internal | — |
| `is_active` | Internal | — |

### `space_event_rsvps`

| Column | Class | Control |
|---|---|---|
| `id` | Public | — |
| `event_id` | Internal | — |
| `user_id` | Internal | — |
| `status` | Internal | — |
| `created_at` | Public | — |
| `updated_at` | Public | — |

### `space_events`

| Column | Class | Control |
|---|---|---|
| `id` | Public | — |
| `space_id` | Internal | — |
| `title` | Internal | — |
| `starts_at` | Internal | — |
| `ends_at` | Internal | — |
| `location` | Internal | — |
| `online_url` | Internal | — |
| `agenda` | Internal | — |
| `rsvp_required` | Internal | — |
| `reminded_at` | Internal | — |
| `created_by` | Internal | — |
| `created_at` | Public | — |
| `updated_at` | Public | — |

### `space_members`

| Column | Class | Control |
|---|---|---|
| `id` | Public | — |
| `space_id` | Internal | — |
| `user_id` | Internal | — |
| `role` | Internal | — |
| `status` | Internal | — |
| `notify` | Internal | — |
| `source` | Internal | — |
| `joined_at` | Internal | — |
| `created_at` | Public | — |
| `updated_at` | Public | — |

### `spaces`

| Column | Class | Control |
|---|---|---|
| `id` | Public | — |
| `type` | Internal | — |
| `subject_type` | Internal | — |
| `subject_id` | Internal | — |
| `title_ar` | Internal | — |
| `title_en` | Internal | — |
| `description_ar` | Internal | — |
| `description_en` | Internal | — |
| `cover_path` | Internal | — |
| `visibility` | Internal | — |
| `join_policy` | Internal | — |
| `settings` | Internal | — |
| `created_by` | Internal | — |
| `archived_at` | Internal | — |
| `posts_count` | Internal | — |
| `created_at` | Public | — |
| `updated_at` | Public | — |

### `sso_states`

| Column | Class | Control |
|---|---|---|
| `id` | Public | — |
| `state` | Internal | — |
| `nonce` | Internal | — |
| `verifier` | Internal | — |
| `redirect_after` | Internal | — |
| `kind` | Internal | — |
| `session` | Internal | — |
| `expires_at` | Internal | — |
| `created_at` | Public | — |
| `updated_at` | Public | — |

### `supervisor_evaluations`

| Column | Class | Control |
|---|---|---|
| `id` | Public | — |
| `registration_id` | Internal | — |
| `program_id` | Internal | — |
| `employee_id` | Internal | — |
| `supervisor_user_id` | Internal | — |
| `application_score` | Internal | — |
| `behavior_change` | Internal | — |
| `comments` | Internal | — |
| `recommendations` | Internal | — |
| `submitted_at` | Internal | — |
| `created_at` | Public | — |
| `updated_at` | Public | — |
| `evidence` | Internal | — |

### `support_tickets`

| Column | Class | Control |
|---|---|---|
| `id` | Public | — |
| `user_id` | Internal | — |
| `category` | Internal | — |
| `priority` | Internal | — |
| `subject` | Internal | — |
| `description` | Internal | — |
| `page_url` | Internal | — |
| `context` | Internal | — |
| `screenshot_path` | Internal | — |
| `saaed_ticket_no` | Internal | — |
| `saaed_status` | Internal | — |
| `status` | Internal | — |
| `attempts` | Internal | — |
| `last_error` | Internal | — |
| `last_synced_at` | Internal | — |
| `created_at` | Public | — |
| `updated_at` | Public | — |

### `survey_questions`

| Column | Class | Control |
|---|---|---|
| `id` | Public | — |
| `lesson_id` | Internal | — |
| `type` | Internal | — |
| `text_ar` | Internal | — |
| `text_en` | Internal | — |
| `options` | Internal | — |
| `required` | Internal | — |
| `sort_order` | Internal | — |
| `created_at` | Public | — |
| `updated_at` | Public | — |

### `survey_responses`

| Column | Class | Control |
|---|---|---|
| `id` | Public | — |
| `lesson_id` | Internal | — |
| `registration_id` | Internal | — |
| `answers` | Internal | — |
| `submitted_at` | Internal | — |
| `created_at` | Public | — |
| `updated_at` | Public | — |

### `target_groups`

| Column | Class | Control |
|---|---|---|
| `id` | Public | — |
| `program_id` | Internal | — |
| `job_title_id` | Internal | — |
| `department_id` | Internal | — |
| `school_type` | Internal | — |
| `education_stage` | Internal | — |
| `description` | Internal | — |
| `created_at` | Public | — |
| `updated_at` | Public | — |

### `task_submissions`

| Column | Class | Control |
|---|---|---|
| `id` | Public | — |
| `task_id` | Internal | — |
| `registration_id` | Internal | — |
| `employee_id` | Internal | — |
| `text_response` | Internal | — |
| `file_path` | Internal | — |
| `file_name` | Internal | — |
| `mime` | Internal | — |
| `status` | Internal | — |
| `feedback` | Internal | — |
| `reviewed_by` | Internal | — |
| `reviewed_at` | Internal | — |
| `version` | Internal | — |
| `created_at` | Public | — |
| `updated_at` | Public | — |
| `trainer_decision` | Internal | — |
| `trainer_id` | Internal | — |
| `trainer_decided_at` | Internal | — |
| `supervisor_decision` | Internal | — |
| `supervisor_id` | Internal | — |
| `supervisor_decided_at` | Internal | — |
| `returned_count` | Internal | — |

### `tasks`

| Column | Class | Control |
|---|---|---|
| `id` | Public | — |
| `program_id` | Internal | — |
| `program_session_id` | Internal | — |
| `title_en` | Internal | — |
| `title_ar` | Internal | — |
| `instructions_en` | Internal | — |
| `instructions_ar` | Internal | — |
| `due_at` | Internal | — |
| `submission_types` | Internal | — |
| `max_file_mb` | Internal | — |
| `is_required` | Internal | — |
| `created_by` | Internal | — |
| `created_at` | Public | — |
| `updated_at` | Public | — |
| `training_group_id` | Internal | — |
| `self_assessed` | Internal | — |

### `teams_attendance_records`

| Column | Class | Control |
|---|---|---|
| `id` | Public | — |
| `session_id` | Internal | — |
| `email` | Confidential | masked in exports |
| `display_name` | Internal | — |
| `role` | Internal | — |
| `total_seconds` | Internal | — |
| `intervals` | Internal | — |
| `employee_id` | Internal | — |
| `registration_id` | Internal | — |
| `minutes` | Internal | — |
| `percent` | Internal | — |
| `applied` | Internal | — |
| `created_at` | Public | — |
| `updated_at` | Public | — |

### `teams_meetings`

| Column | Class | Control |
|---|---|---|
| `id` | Public | — |
| `session_id` | Internal | — |
| `meeting_id` | Internal | — |
| `join_url` | Internal | — |
| `organizer_upn` | Internal | — |
| `kind` | Internal | — |
| `status` | Internal | — |
| `lobby` | Internal | — |
| `recording_url` | Internal | — |
| `attendance_synced_at` | Internal | — |
| `attendance_attempts` | Internal | — |
| `last_error` | Internal | — |
| `created_at` | Public | — |
| `updated_at` | Public | — |

### `teams_teams`

| Column | Class | Control |
|---|---|---|
| `id` | Public | — |
| `group_id` | Internal | — |
| `team_id` | Internal | — |
| `channel_id` | Internal | — |
| `drive_id` | Internal | — |
| `folder_item_id` | Internal | — |
| `web_url` | Internal | — |
| `members_synced_at` | Internal | — |
| `last_error` | Internal | — |
| `created_at` | Public | — |
| `updated_at` | Public | — |

### `trainer_attendance`

| Column | Class | Control |
|---|---|---|
| `id` | Public | — |
| `session_id` | Internal | — |
| `trainer_id` | Internal | — |
| `check_in_at` | Internal | — |
| `check_out_at` | Internal | — |
| `method` | Internal | — |
| `recorded_by` | Internal | — |
| `notes` | Internal | — |
| `created_at` | Public | — |
| `updated_at` | Public | — |

### `trainer_certificates`

| Column | Class | Control |
|---|---|---|
| `id` | Public | — |
| `certificate_no` | Internal | — |
| `verification_code` | Internal | — |
| `trainer_id` | Internal | — |
| `program_id` | Internal | — |
| `issued_at` | Internal | — |
| `hours` | Internal | — |
| `file_path` | Internal | — |
| `status` | Internal | — |
| `revoked_reason` | Internal | — |
| `meta` | Internal | — |
| `created_at` | Public | — |
| `updated_at` | Public | — |
| `template_id` | Internal | — |

### `trainers`

| Column | Class | Control |
|---|---|---|
| `id` | Confidential | masked in exports |
| `user_id` | Confidential | masked in exports |
| `name_en` | Confidential | masked in exports |
| `name_ar` | Confidential | masked in exports |
| `title_en` | Confidential | masked in exports |
| `title_ar` | Confidential | masked in exports |
| `email` | Confidential | masked in exports |
| `phone` | Confidential | masked in exports |
| `bio_en` | Confidential | masked in exports |
| `bio_ar` | Confidential | masked in exports |
| `specializations` | Confidential | masked in exports |
| `photo_path` | Confidential | masked in exports |
| `is_external` | Confidential | masked in exports |
| `organization` | Confidential | masked in exports |
| `rating` | Confidential | masked in exports |
| `status` | Confidential | masked in exports |
| `created_at` | Confidential | masked in exports |
| `updated_at` | Confidential | masked in exports |
| `source` | Confidential | masked in exports |
| `school_id` | Confidential | masked in exports |
| `employee_id` | Confidential | masked in exports |
| `partner_id` | Confidential | masked in exports |
| `country` | Confidential | masked in exports |
| `city` | Confidential | masked in exports |
| `languages` | Confidential | masked in exports |
| `experience_years` | Confidential | masked in exports |
| `hourly_rate` | Confidential | masked in exports |
| `currency` | Confidential | masked in exports |
| `notes` | Confidential | masked in exports |

### `training_groups`

| Column | Class | Control |
|---|---|---|
| `id` | Public | — |
| `program_id` | Internal | — |
| `code` | Internal | — |
| `title_ar` | Internal | — |
| `title_en` | Internal | — |
| `sequence` | Internal | — |
| `delivery_mode` | Internal | — |
| `start_date` | Internal | — |
| `end_date` | Internal | — |
| `registration_opens_at` | Internal | — |
| `registration_closes_at` | Internal | — |
| `capacity` | Internal | — |
| `min_attendance_percent` | Internal | — |
| `supervisor_id` | Internal | — |
| `default_room_id` | Internal | — |
| `status` | Internal | — |
| `status_reason` | Internal | — |
| `postponed_to` | Internal | — |
| `plan_item_id` | Internal | — |
| `is_emergency` | Internal | — |
| `published_at` | Internal | — |
| `created_at` | Public | — |
| `updated_at` | Public | — |
| `deleted_at` | Public | — |
| `approval_mode` | Internal | — |
| `approve_after_window` | Internal | — |
| `allow_overlap_until_approved` | Internal | — |

### `training_kits`

| Column | Class | Control |
|---|---|---|
| `id` | Public | — |
| `code` | Internal | — |
| `title_ar` | Internal | — |
| `title_en` | Internal | — |
| `description_ar` | Internal | — |
| `description_en` | Internal | — |
| `program_id` | Internal | — |
| `category_id` | Internal | — |
| `status` | Internal | — |
| `audience` | Internal | — |
| `duration_hours` | Internal | — |
| `objectives` | Internal | — |
| `tags` | Internal | — |
| `cover_path` | Internal | — |
| `version` | Internal | — |
| `review_round` | Internal | — |
| `owner_id` | Internal | — |
| `due_at` | Internal | — |
| `submitted_at` | Internal | — |
| `approved_at` | Internal | — |
| `published_at` | Internal | — |
| `approved_by` | Internal | — |
| `created_by` | Internal | — |
| `created_at` | Public | — |
| `updated_at` | Public | — |
| `delivery` | Internal | — |

### `training_needs`

| Column | Class | Control |
|---|---|---|
| `id` | Public | — |
| `school_id` | Internal | — |
| `submitted_by` | Internal | — |
| `skill_id` | Internal | — |
| `skill_name` | Internal | — |
| `employees_count` | Internal | — |
| `priority` | Internal | — |
| `reason` | Internal | — |
| `target_job_title_id` | Internal | — |
| `target_group` | Internal | — |
| `status` | Internal | — |
| `program_id` | Internal | — |
| `reviewed_by` | Internal | — |
| `review_notes` | Internal | — |
| `created_at` | Public | — |
| `updated_at` | Public | — |
| `survey_id` | Internal | — |
| `need_index` | Internal | — |

### `training_places`

| Column | Class | Control |
|---|---|---|
| `id` | Public | — |
| `name_ar` | Confidential | masked in exports |
| `name_en` | Confidential | masked in exports |
| `address` | Confidential | masked in exports |
| `map_url` | Internal | — |
| `website` | Internal | — |
| `latitude` | Confidential | masked in exports |
| `longitude` | Confidential | masked in exports |
| `capacity_limit` | Internal | — |
| `created_at` | Public | — |
| `updated_at` | Public | — |

### `training_plan_changes`

| Column | Class | Control |
|---|---|---|
| `id` | Public | — |
| `plan_id` | Internal | — |
| `item_id` | Internal | — |
| `change_type` | Internal | — |
| `before` | Internal | — |
| `after` | Internal | — |
| `reason` | Internal | — |
| `changed_by` | Internal | — |
| `created_at` | Public | — |

### `training_plan_items`

| Column | Class | Control |
|---|---|---|
| `id` | Public | — |
| `plan_id` | Internal | — |
| `program_id` | Internal | — |
| `title_ar` | Internal | — |
| `title_en` | Internal | — |
| `category_id` | Internal | — |
| `audience` | Internal | — |
| `priority` | Internal | — |
| `priority_score` | Internal | — |
| `planned_groups` | Internal | — |
| `planned_seats` | Internal | — |
| `planned_hours` | Internal | — |
| `window_start` | Internal | — |
| `window_end` | Internal | — |
| `source` | Internal | — |
| `source_refs` | Internal | — |
| `status` | Internal | — |
| `is_emergency` | Internal | — |
| `rationale_ar` | Internal | — |
| `rationale_en` | Internal | — |
| `review_comment` | Internal | — |
| `created_at` | Public | — |
| `updated_at` | Public | — |

### `training_plans`

| Column | Class | Control |
|---|---|---|
| `id` | Public | — |
| `year` | Internal | — |
| `version` | Internal | — |
| `title_ar` | Internal | — |
| `title_en` | Internal | — |
| `status` | Internal | — |
| `rules` | Internal | — |
| `submitted_by` | Internal | — |
| `submitted_at` | Internal | — |
| `approved_by` | Internal | — |
| `approved_at` | Internal | — |
| `baseline` | Internal | — |
| `signed_pdf_path` | Internal | — |
| `notes` | Internal | — |
| `created_at` | Public | — |
| `updated_at` | Public | — |

### `training_rooms`

| Column | Class | Control |
|---|---|---|
| `id` | Public | — |
| `name_en` | Confidential | masked in exports |
| `name_ar` | Confidential | masked in exports |
| `building` | Internal | — |
| `capacity` | Internal | — |
| `facilities` | Internal | — |
| `latitude` | Confidential | masked in exports |
| `longitude` | Confidential | masked in exports |
| `created_at` | Public | — |
| `updated_at` | Public | — |
| `code` | Internal | — |
| `office` | Internal | — |
| `location` | Internal | — |
| `floor` | Internal | — |
| `area_m2` | Internal | — |
| `layout` | Internal | — |
| `layouts` | Internal | — |
| `is_accessible` | Internal | — |
| `status` | Internal | — |
| `notes` | Internal | — |
| `display_token` | Restricted | hash / secret / expiring token |
| `place_id` | Internal | — |
| `building_id` | Internal | — |

### `user_badges`

| Column | Class | Control |
|---|---|---|
| `id` | Public | — |
| `user_id` | Internal | — |
| `badge_id` | Internal | — |
| `source` | Internal | — |
| `awarded_at` | Internal | — |
| `created_at` | Public | — |
| `updated_at` | Public | — |

### `user_dashboard_layouts`

| Column | Class | Control |
|---|---|---|
| `id` | Public | — |
| `user_id` | Internal | — |
| `role_slug` | Internal | — |
| `layout` | Internal | — |
| `created_at` | Public | — |
| `updated_at` | Public | — |

### `user_identities`

| Column | Class | Control |
|---|---|---|
| `id` | Confidential | masked in exports |
| `user_id` | Confidential | masked in exports |
| `provider` | Confidential | masked in exports |
| `subject` | Confidential | masked in exports |
| `upn` | Confidential | masked in exports |
| `last_login_at` | Confidential | masked in exports |
| `created_at` | Confidential | masked in exports |
| `updated_at` | Confidential | masked in exports |

### `user_notification_preferences`

| Column | Class | Control |
|---|---|---|
| `id` | Public | — |
| `user_id` | Internal | — |
| `event_group` | Internal | — |
| `channel` | Internal | — |
| `enabled` | Internal | — |
| `created_at` | Public | — |
| `updated_at` | Public | — |

### `user_tours`

| Column | Class | Control |
|---|---|---|
| `id` | Public | — |
| `user_id` | Internal | — |
| `tour_key` | Internal | — |
| `state` | Internal | — |
| `created_at` | Public | — |
| `updated_at` | Public | — |

### `users`

| Column | Class | Control |
|---|---|---|
| `id` | Confidential | masked in exports |
| `auth_id` | Confidential | masked in exports |
| `name` | Confidential | masked in exports |
| `name_ar` | Confidential | masked in exports |
| `email` | Confidential | masked in exports |
| `phone` | Confidential | masked in exports |
| `password` | Restricted | hash / secret / expiring token |
| `locale` | Confidential | masked in exports |
| `avatar_path` | Confidential | masked in exports |
| `status` | Confidential | masked in exports |
| `email_verified_at` | Confidential | masked in exports |
| `last_login_at` | Confidential | masked in exports |
| `remember_token` | Restricted | hash / secret / expiring token |
| `created_at` | Confidential | masked in exports |
| `updated_at` | Confidential | masked in exports |
| `deleted_at` | Confidential | masked in exports |
| `last_active_at` | Confidential | masked in exports |
| `locked_at` | Confidential | masked in exports |
| `active_role_user_id` | Confidential | masked in exports |
| `failed_attempts` | Confidential | masked in exports |
| `locked_until` | Confidential | masked in exports |
| `password_changed_at` | Confidential | masked in exports |
| `mfa_enabled` | Confidential | masked in exports |
| `mfa_secret` | Restricted | application-level encryption |
| `mfa_recovery` | Confidential | masked in exports |
| `mfa_confirmed_at` | Confidential | masked in exports |

### `video_interaction_responses`

| Column | Class | Control |
|---|---|---|
| `id` | Public | — |
| `interaction_id` | Internal | — |
| `registration_id` | Internal | — |
| `answer` | Internal | — |
| `correct` | Internal | — |
| `answered_at` | Internal | — |
| `created_at` | Public | — |
| `updated_at` | Public | — |

### `video_interactions`

| Column | Class | Control |
|---|---|---|
| `id` | Public | — |
| `lesson_id` | Internal | — |
| `at_seconds` | Internal | — |
| `type` | Internal | — |
| `question_id` | Internal | — |
| `prompt_ar` | Internal | — |
| `prompt_en` | Internal | — |
| `required` | Internal | — |
| `blocks_progress` | Internal | — |
| `require_correct` | Internal | — |
| `allow_skip` | Internal | — |
| `sort_order` | Internal | — |
| `created_at` | Public | — |
| `updated_at` | Public | — |

### `waiting_lists`

| Column | Class | Control |
|---|---|---|
| `id` | Public | — |
| `program_id` | Internal | — |
| `employee_id` | Internal | — |
| `registration_id` | Internal | — |
| `position` | Internal | — |
| `status` | Internal | — |
| `promoted_at` | Internal | — |
| `created_at` | Public | — |
| `updated_at` | Public | — |
| `training_group_id` | Internal | — |

### `webhook_deliveries`

| Column | Class | Control |
|---|---|---|
| `id` | Public | — |
| `subscription_id` | Internal | — |
| `outbox_event_id` | Internal | — |
| `status` | Internal | — |
| `attempts` | Internal | — |
| `next_attempt_at` | Internal | — |
| `last_status` | Internal | — |
| `last_error` | Internal | — |
| `delivered_at` | Internal | — |
| `created_at` | Public | — |
| `updated_at` | Public | — |

### `webhook_subscriptions`

| Column | Class | Control |
|---|---|---|
| `id` | Public | — |
| `name` | Confidential | masked in exports |
| `url` | Internal | — |
| `secret` | Restricted | application-level encryption |
| `events` | Internal | — |
| `enabled` | Internal | — |
| `created_by` | Internal | — |
| `created_at` | Public | — |
| `updated_at` | Public | — |

### `withdrawal_reasons`

| Column | Class | Control |
|---|---|---|
| `id` | Public | — |
| `code` | Internal | — |
| `label_ar` | Internal | — |
| `label_en` | Internal | — |
| `requires_attachment` | Internal | — |
| `is_active` | Internal | — |
| `sort_order` | Internal | — |
| `created_at` | Public | — |
| `updated_at` | Public | — |

### `withdrawal_requests`

| Column | Class | Control |
|---|---|---|
| `id` | Public | — |
| `registration_id` | Internal | — |
| `requested_by` | Internal | — |
| `reason_code` | Internal | — |
| `reason_text` | Internal | — |
| `attachments` | Internal | — |
| `timing` | Internal | — |
| `stage` | Internal | — |
| `status` | Internal | — |
| `manager_id` | Internal | — |
| `manager_decision` | Internal | — |
| `manager_note` | Internal | — |
| `supervisor_id` | Internal | — |
| `supervisor_decision` | Internal | — |
| `supervisor_note` | Internal | — |
| `is_late` | Internal | — |
| `created_at` | Public | — |
| `updated_at` | Public | — |

### `xapi_documents`

| Column | Class | Control |
|---|---|---|
| `id` | Public | — |
| `kind` | Internal | — |
| `activity_id` | Internal | — |
| `agent_key` | Internal | — |
| `registration` | Internal | — |
| `doc_id` | Internal | — |
| `content` | Internal | — |
| `content_type` | Internal | — |
| `created_at` | Public | — |
| `updated_at` | Public | — |

### `xapi_statements`

| Column | Class | Control |
|---|---|---|
| `id` | Public | — |
| `statement` | Internal | — |
| `actor_key` | Internal | — |
| `verb` | Internal | — |
| `object_id` | Internal | — |
| `registration_id` | Internal | — |
| `lesson_id` | Internal | — |
| `voided` | Internal | — |
| `stored` | Internal | — |

