<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Phase 12: report definitions, runs and schedules; role dashboards; KPI samples, targets and request metrics. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_definitions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('key', 64)->nullable()->unique();     // built-in reports have a key
            $table->string('category', 24)->default('general');   // admin | supervisor | trainer | manager | trainee | qa | kit | general
            $table->string('title_ar');
            $table->string('title_en');
            $table->text('description_ar')->nullable();
            $table->text('description_en')->nullable();
            $table->string('dataset', 40);
            $table->json('columns');                              // [{field, label?, aggregate?, format?}]
            $table->json('filters')->nullable();                  // [{field, operator, value, adjustable}]
            $table->json('group_by')->nullable();
            $table->json('sort')->nullable();
            $table->json('chart')->nullable();                    // {type, x, y}
            $table->json('options')->nullable();                  // kind (table|matrix|sheet), parts, date_field …
            $table->string('visibility', 10)->default('private'); // private | role | everyone
            $table->json('roles')->nullable();
            $table->foreignUuid('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('is_system')->default(false);
            $table->timestamps();
        });

        Schema::create('report_favorites', function (Blueprint $table) {
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('definition_id')->constrained('report_definitions')->cascadeOnDelete();
            $table->primary(['user_id', 'definition_id']);
        });

        Schema::create('report_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('definition_id')->constrained('report_definitions')->cascadeOnDelete();
            $table->json('params')->nullable();
            $table->json('formats')->nullable();
            $table->string('status', 10)->default('queued');      // queued | running | ready | failed
            $table->unsignedInteger('rows_count')->default(0);
            $table->json('file_paths')->nullable();                // {xlsx: path, pdf: path, docx: path}
            $table->boolean('personal')->default(false);           // contains personal data
            $table->foreignUuid('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('schedule_id')->nullable()->index();
            $table->string('lang', 2)->default('ar');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();
            $table->index(['status', 'created_at']);
        });

        Schema::create('report_schedules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('definition_id')->constrained('report_definitions')->cascadeOnDelete();
            $table->json('params')->nullable();
            $table->string('frequency', 10);                      // daily | weekly | monthly
            $table->json('formats')->nullable();
            $table->json('recipients');                           // {users: [], roles: [], emails: []}
            $table->string('lang', 2)->default('ar');
            $table->timestamp('last_run_at')->nullable();
            $table->timestamp('next_run_at')->nullable()->index();
            $table->boolean('is_active')->default(true);
            $table->text('last_error')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('dashboard_presets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('role_slug', 48)->unique();
            $table->json('widgets');                              // ordered widget keys
            $table->timestamps();
        });

        Schema::create('user_dashboard_layouts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->string('role_slug', 48);
            $table->json('layout');                               // {order: [keys], hidden: [keys]}
            $table->timestamps();
            $table->unique(['user_id', 'role_slug']);
        });

        Schema::create('kpi_samples', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('metric', 40);
            $table->double('value');
            $table->string('window', 4)->default('5m');           // 5m | 1h | 1d | 30d
            $table->json('meta')->nullable();
            $table->timestamp('measured_at');
            $table->index(['metric', 'measured_at']);
        });

        Schema::create('kpi_targets', function (Blueprint $table) {
            $table->string('metric', 40)->primary();
            $table->double('target');
            $table->string('comparator', 4)->default('gte');      // gte | lte
            $table->boolean('editable')->default(true);
            $table->timestamps();
        });

        // One row per sampled request; aggregated every minute into kpi_samples and pruned.
        Schema::create('request_metrics', function (Blueprint $table) {
            $table->id();
            $table->string('route', 120)->nullable();
            $table->unsignedInteger('duration_ms');
            $table->unsignedSmallInteger('status');
            $table->timestamp('created_at')->index();
        });
    }

    public function down(): void
    {
        foreach (['request_metrics', 'kpi_targets', 'kpi_samples', 'user_dashboard_layouts', 'dashboard_presets', 'report_schedules', 'report_runs', 'report_favorites', 'report_definitions'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
