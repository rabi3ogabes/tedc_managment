<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Phase 10 — content packages (SCORM, xAPI, cmi5, H5P, HTML5, Common Cartridge), LRS, LTI, lesson versions, kits, sharing, the digital library, providers and offline sync. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_packages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('standard', 12);                 // scorm12 | scorm2004 | xapi | cmi5 | h5p | html5 | cc
            $table->string('title');
            $table->string('version', 20)->nullable();
            $table->string('storage_root');                 // directory in the private "packages" bucket
            $table->json('manifest')->nullable();
            $table->json('entry_points')->nullable();       // [{id, title, href, parent?, type?}]
            $table->unsignedBigInteger('size')->default(0);
            $table->foreignUuid('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 12)->default('ready');  // processing | ready | failed
            $table->text('error')->nullable();
            $table->timestamps();
        });

        Schema::table('course_lessons', function (Blueprint $table) {
            $table->foreignUuid('package_id')->nullable()->constrained('content_packages')->nullOnDelete();
            $table->string('package_item_id', 120)->nullable();
            $table->uuid('lti_tool_id')->nullable();
            $table->uuid('external_course_id')->nullable();
            $table->unsignedSmallInteger('version')->default(1);
        });

        Schema::create('scorm_attempts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('registration_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('lesson_id')->constrained('course_lessons')->cascadeOnDelete();
            $table->foreignUuid('package_id')->constrained('content_packages')->cascadeOnDelete();
            $table->unsignedSmallInteger('attempt_no')->default(1);
            $table->json('cmi')->nullable();
            $table->string('completion_status', 16)->default('incomplete');
            $table->string('success_status', 16)->default('unknown');
            $table->decimal('score_raw', 7, 2)->nullable();
            $table->decimal('score_min', 7, 2)->nullable();
            $table->decimal('score_max', 7, 2)->nullable();
            $table->decimal('score_scaled', 5, 4)->nullable();
            $table->unsignedInteger('total_time')->default(0);
            $table->text('suspend_data')->nullable();
            $table->string('location', 1000)->nullable();
            $table->timestamp('started_at');
            $table->timestamp('committed_at')->nullable();
            $table->timestamps();
            $table->unique(['registration_id', 'lesson_id', 'attempt_no']);
        });

        Schema::create('xapi_statements', function (Blueprint $table) {
            $table->uuid('id')->primary();                   // the statement id
            $table->json('statement');
            $table->string('actor_key', 255)->index();
            $table->string('verb', 255)->index();
            $table->string('object_id', 500)->index();
            $table->foreignUuid('registration_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('lesson_id')->nullable()->constrained('course_lessons')->nullOnDelete();
            $table->boolean('voided')->default(false);
            $table->timestamp('stored');
            $table->index('stored');
        });

        Schema::create('xapi_documents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('kind', 16);                      // state | activity_profile | agent_profile
            $table->string('activity_id', 500)->nullable();
            $table->string('agent_key', 255)->nullable();
            $table->string('registration', 64)->nullable();
            $table->string('doc_id', 255);
            $table->longText('content');
            $table->string('content_type', 120)->default('application/json');
            $table->timestamps();
            $table->index(['kind', 'activity_id', 'agent_key', 'doc_id']);
        });

        Schema::create('cmi5_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('registration_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('lesson_id')->constrained('course_lessons')->cascadeOnDelete();
            $table->string('au_id', 500);
            $table->string('token_hash', 64)->unique();
            $table->json('state')->nullable();              // {launched, initialized, completed, passed, failed, abandoned, waived, terminated, satisfied}
            $table->timestamp('expires_at');
            $table->timestamps();
        });

        Schema::create('caliper_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->json('event');
            $table->string('status', 8)->default('pending');   // pending | sent | failed
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->text('error')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
            $table->index('status');
        });

        Schema::create('lti_tools', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('version', 4);                    // 1.1 | 1.3
            $table->string('client_id')->nullable();
            $table->string('deployment_id')->default('1');
            $table->string('login_url', 500)->nullable();
            $table->string('launch_url', 500);
            $table->string('jwks_url', 500)->nullable();
            $table->text('public_key')->nullable();
            $table->string('deep_link_url', 500)->nullable();
            $table->string('consumer_key')->nullable();
            $table->text('consumer_secret')->nullable();     // encrypted
            $table->json('custom')->nullable();
            $table->json('privacy')->nullable();             // {share_name, share_email}
            $table->boolean('supports_ags')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('lti_states', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('state', 64)->unique();
            $table->string('nonce', 64);
            $table->foreignUuid('tool_id')->constrained('lti_tools')->cascadeOnDelete();
            $table->foreignUuid('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('lesson_id')->nullable()->constrained('course_lessons')->nullOnDelete();
            $table->string('purpose', 12)->default('launch');   // launch | deep_link
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamps();
        });

        Schema::create('lti_scores', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tool_id')->constrained('lti_tools')->cascadeOnDelete();
            $table->foreignUuid('lesson_id')->nullable()->constrained('course_lessons')->nullOnDelete();
            $table->foreignUuid('user_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('score_given', 8, 2)->nullable();
            $table->decimal('score_max', 8, 2)->nullable();
            $table->string('activity_progress', 16)->nullable();
            $table->string('grading_progress', 16)->nullable();
            $table->timestamps();
        });

        Schema::create('course_lesson_versions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('lesson_id')->constrained('course_lessons')->cascadeOnDelete();
            $table->unsignedSmallInteger('version');
            $table->json('snapshot');                        // content, settings and question set at that time
            $table->string('note')->nullable();
            $table->boolean('is_archived')->default(false);
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['lesson_id', 'version']);
        });

        Schema::table('lesson_progress', fn (Blueprint $table) => $table->unsignedSmallInteger('lesson_version')->nullable());

        Schema::create('kit_program', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('kit_id')->constrained('training_kits')->cascadeOnDelete();
            $table->foreignUuid('program_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('pinned_version')->nullable();
            $table->timestamps();
            $table->unique(['kit_id', 'program_id']);
        });
        foreach (DB::table('training_kits')->whereNotNull('program_id')->get(['id', 'program_id']) as $k) {
            DB::table('kit_program')->insert(['id' => (string) \Illuminate\Support\Str::uuid(), 'kit_id' => $k->id, 'program_id' => $k->program_id, 'created_at' => now(), 'updated_at' => now()]);
        }

        Schema::create('job_groups', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name_ar');
            $table->string('name_en');
            $table->json('rule');                            // {job_title_ids, job_categories, school_ids, subjects}
            $table->timestamps();
        });

        Schema::create('resource_shares', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('resource_type', 16);             // material | kit_file | library_item | lesson
            $table->uuid('resource_id');
            $table->string('target_type', 12);               // program | group | job_group | role | user
            $table->string('target_id', 64);
            $table->string('permission', 10)->default('view');   // view | download | reshare
            $table->foreignUuid('shared_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
            $table->index(['target_type', 'target_id']);
            $table->index(['resource_type', 'resource_id']);
        });

        Schema::create('sharing_policies', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('role', 40)->unique();
            $table->json('resource_types');                  // which may be shared
            $table->json('target_types');                    // with which targets
            $table->boolean('allow_reshare')->default(false);
            $table->boolean('allow_download')->default(true);
            $table->boolean('watermark')->default(false);
            $table->timestamps();
        });

        Schema::create('library_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type', 12);                      // book | journal | periodical | audio | video | elearning | kit | link
            $table->string('title_ar');
            $table->string('title_en')->nullable();
            $table->text('description_ar')->nullable();
            $table->text('description_en')->nullable();
            $table->json('authors')->nullable();
            $table->string('publisher')->nullable();
            $table->string('isbn', 30)->nullable();
            $table->unsignedSmallInteger('year')->nullable();
            $table->string('language', 5)->default('ar');
            $table->json('subjects')->nullable();
            $table->json('skill_ids')->nullable();
            $table->string('cover_path')->nullable();
            $table->string('file_path')->nullable();
            $table->string('file_mime', 120)->nullable();
            $table->string('url', 500)->nullable();
            $table->string('source', 12)->default('local');  // local | maktabati | qnl | provider
            $table->string('external_id')->nullable();
            $table->json('rights')->nullable();              // {owner, licence, download, print, watermark, embargo_from, embargo_until}
            $table->json('audience')->nullable();            // eligibility-rule syntax
            $table->string('status', 10)->default('draft');  // draft | published | archived
            $table->text('search_text')->nullable();         // normalised text for Arabic-friendly search
            $table->unsignedInteger('views')->default(0);
            $table->unsignedInteger('downloads')->default(0);
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['status', 'type']);
        });

        Schema::create('library_collections', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name_ar');
            $table->string('name_en');
            $table->boolean('is_featured')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });
        Schema::create('library_collection_items', function (Blueprint $table) {
            $table->foreignUuid('collection_id')->constrained('library_collections')->cascadeOnDelete();
            $table->foreignUuid('item_id')->constrained('library_items')->cascadeOnDelete();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->primary(['collection_id', 'item_id']);
        });
        Schema::create('library_reviews', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('item_id')->constrained('library_items')->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('stars');
            $table->text('review')->nullable();
            $table->timestamps();
            $table->unique(['item_id', 'user_id']);
        });
        Schema::create('library_shelf', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('item_id')->constrained('library_items')->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->decimal('progress', 5, 2)->default(0);
            $table->string('position', 60)->nullable();
            $table->timestamps();
            $table->unique(['item_id', 'user_id']);
        });

        Schema::create('external_courses', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('provider', 20);                  // coursera | edx | udemy | linkedin_learning | fake
            $table->string('external_id');
            $table->string('title');
            $table->string('url', 500);
            $table->unsignedSmallInteger('hours')->default(0);
            $table->json('meta')->nullable();
            $table->foreignUuid('program_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
            $table->unique(['provider', 'external_id']);
        });

        Schema::create('content_imports', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('kind', 8);                       // qti | cc
            $table->foreignUuid('program_id')->nullable()->constrained()->nullOnDelete();
            $table->json('log');
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('offline_sync_log', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('idempotency_key', 80)->unique();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->json('result')->nullable();
            $table->timestamps();
        });

        Schema::table('programs', fn (Blueprint $table) => $table->json('external_platform')->nullable());
    }

    public function down(): void
    {
        Schema::table('programs', fn (Blueprint $table) => $table->dropColumn('external_platform'));
        foreach (['offline_sync_log', 'content_imports', 'external_courses', 'library_shelf', 'library_reviews', 'library_collection_items', 'library_collections', 'library_items', 'sharing_policies', 'resource_shares', 'job_groups', 'kit_program', 'course_lesson_versions', 'lti_scores', 'lti_states', 'lti_tools', 'caliper_events', 'cmi5_sessions', 'xapi_documents', 'xapi_statements', 'scorm_attempts'] as $t) {
            Schema::dropIfExists($t);
        }
        Schema::table('lesson_progress', fn (Blueprint $table) => $table->dropColumn('lesson_version'));
        Schema::table('course_lessons', fn (Blueprint $table) => $table->dropColumn(['package_id', 'package_item_id', 'lti_tool_id', 'external_course_id', 'version']));
        Schema::dropIfExists('content_packages');
    }
};
