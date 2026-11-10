<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Phase 15: AI logs, recommendation feedback, item similarity, assessment feedback drafts, adaptive rules and mastery, forecasts and risks, retrieval index, assistant conversations. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('feature', 24);
            $table->uuid('user_id')->nullable();
            $table->string('connection_id', 24)->nullable();
            $table->string('model', 120)->nullable();
            $table->string('status', 10);                              // ok | blocked | failed | fallback
            $table->string('reason', 120)->nullable();
            $table->string('residency', 10)->nullable();               // qatar | approved | external
            $table->unsignedInteger('prompt_chars')->default(0);
            $table->unsignedInteger('tokens_in')->default(0);
            $table->unsignedInteger('tokens_out')->default(0);
            $table->unsignedInteger('latency_ms')->default(0);
            $table->unsignedInteger('redactions')->default(0);
            $table->timestamp('created_at')->useCurrent();
            $table->index(['feature', 'created_at']);
        });

        Schema::create('ai_recommendation_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->string('item_type', 10)->default('program');       // program | library
            $table->uuid('item_id');
            $table->string('event', 10);                               // shown | clicked | enrolled | dismissed | liked | disliked
            $table->string('variant', 8)->default('hybrid');           // hybrid | rules
            $table->float('score')->nullable();
            $table->json('reasons')->nullable();
            $table->string('note', 200)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['user_id', 'item_id', 'event']);
            $table->index(['variant', 'event']);
        });

        Schema::create('item_similarity', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('item_type', 10)->default('program');
            $table->uuid('item_id');
            $table->uuid('other_id');
            $table->float('score');
            $table->unsignedInteger('support')->default(0);
            $table->timestamp('computed_at');
            $table->unique(['item_type', 'item_id', 'other_id']);
        });

        Schema::create('ai_feedback_drafts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('attempt_id')->constrained('assessment_attempts')->cascadeOnDelete();
            $table->uuid('question_id');
            $table->text('draft_text');
            $table->float('suggested_score')->nullable();
            $table->float('max_points');
            $table->json('rubric')->nullable();                        // criteria the draft was matched against
            $table->string('source', 10)->default('rules');           // ai | rules
            $table->string('status', 10)->default('draft');            // draft | accepted | edited | rejected
            $table->text('final_comment')->nullable();
            $table->float('final_score')->nullable();
            $table->foreignUuid('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
            $table->unique(['attempt_id', 'question_id']);
        });

        Schema::create('adaptive_rules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('program_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('skill_id')->constrained()->cascadeOnDelete();
            $table->string('action', 14);                              // skip_module | add_lesson
            $table->foreignUuid('module_id')->nullable()->constrained('course_modules')->cascadeOnDelete();   // module to skip when mastered
            $table->foreignUuid('lesson_id')->nullable()->constrained('course_lessons')->cascadeOnDelete(); // remedial lesson to add when weak
            $table->float('skip_at')->default(0.85);                   // mastery at or above → skip
            $table->float('remedial_below')->default(0.5);             // mastery below → add the lesson
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['program_id', 'is_active']);
        });

        Schema::create('learner_mastery', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('registration_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('skill_id')->constrained()->cascadeOnDelete();
            $table->float('mastery');                                  // 0..1
            $table->unsignedInteger('evidence_count')->default(0);
            $table->timestamps();
            $table->unique(['registration_id', 'skill_id']);
        });

        Schema::create('forecasts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('dimension', 12);                           // competency | job | school
            $table->uuid('subject_id')->nullable();
            $table->string('label', 200);
            $table->unsignedSmallInteger('year');
            $table->float('value');
            $table->float('low');
            $table->float('high');
            $table->string('model', 12);                               // sma | ets | naive
            $table->text('explanation')->nullable();
            $table->json('history')->nullable();
            $table->timestamps();
            $table->index(['dimension', 'year']);
        });

        Schema::create('risk_flags', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type', 16);                                // hours_shortfall | licence_gap | group_underfill | low_satisfaction
            $table->string('subject_type', 12);                        // employee | group | program
            $table->uuid('subject_id');
            $table->string('label', 200)->nullable();
            $table->float('score');                                    // 0..1
            $table->json('reasons')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
            $table->unique(['type', 'subject_id']);
        });

        Schema::create('embeddings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('source_type', 12);                         // lesson | program | library | faq
            $table->uuid('source_id');
            $table->unsignedInteger('chunk_no')->default(0);
            $table->uuid('program_id')->nullable();
            $table->string('visibility', 8)->default('program');       // public | program
            $table->string('lang', 2)->default('ar');
            $table->string('title', 255)->nullable();
            $table->string('route', 255)->nullable();
            $table->text('content');
            $table->json('vector');
            $table->string('content_hash', 40);
            $table->timestamp('updated_at')->nullable();
            $table->unique(['source_type', 'source_id', 'chunk_no']);
            $table->index(['program_id', 'visibility']);
        });

        Schema::create('assistant_conversations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->string('title', 160)->nullable();
            $table->string('locale', 2)->default('ar');
            $table->timestamps();
            $table->index(['user_id', 'updated_at']);
        });

        Schema::create('assistant_messages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('conversation_id')->constrained('assistant_conversations')->cascadeOnDelete();
            $table->string('role', 10);                                // user | assistant
            $table->text('content');
            $table->json('citations')->nullable();
            $table->json('meta')->nullable();                          // tools used, refused, escalation options, source (ai | rules)
            $table->string('feedback', 4)->nullable();                 // up | down
            $table->string('feedback_reason', 200)->nullable();
            $table->timestamps();
            $table->index(['conversation_id', 'created_at']);
        });
    }

    public function down(): void
    {
        foreach (['assistant_messages', 'assistant_conversations', 'embeddings', 'risk_flags', 'forecasts', 'learner_mastery', 'adaptive_rules', 'ai_feedback_drafts', 'item_similarity', 'ai_recommendation_events', 'ai_logs'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
