<?php

use App\Gamification\Defaults;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Phase 14: communities, forums, posts and comments, polls, events, ratings, ask-the-trainer; gamification. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('spaces', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type', 16);                              // community | program_forum | group_forum | trainers_channel | lesson_thread
            $table->string('subject_type', 24)->nullable();          // program | group | lesson | material
            $table->uuid('subject_id')->nullable();
            $table->string('title_ar');
            $table->string('title_en');
            $table->text('description_ar')->nullable();
            $table->text('description_en')->nullable();
            $table->string('cover_path')->nullable();
            $table->string('visibility', 16)->default('members');   // public_in_scope | members | private
            $table->string('join_policy', 8)->default('open');      // open | request | invite
            $table->json('settings')->nullable();                    // allow_polls, allow_files, moderation, anonymous_qa
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('archived_at')->nullable();
            $table->unsignedInteger('posts_count')->default(0);
            $table->timestamps();
            $table->index(['type', 'archived_at']);
            $table->unique(['type', 'subject_type', 'subject_id']);
        });

        Schema::create('space_members', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('space_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->string('role', 10)->default('member');           // owner | manager | moderator | member
            $table->string('status', 8)->default('active');          // active | pending | banned
            $table->string('notify', 8)->default('all');             // all | mentions | none
            $table->string('source', 8)->default('manual');          // manual | auto (forums follow registrations)
            $table->timestamp('joined_at')->nullable();
            $table->timestamps();
            $table->unique(['space_id', 'user_id']);
            $table->index(['user_id', 'status']);
        });

        Schema::create('posts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('space_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('author_id')->constrained('users')->cascadeOnDelete();
            $table->string('kind', 12)->default('discussion');       // discussion | question | announcement | poll | resource | meeting
            $table->string('title', 255)->nullable();
            $table->text('body');                                    // sanitised HTML
            $table->json('attachments')->nullable();
            $table->boolean('is_pinned')->default(false);
            $table->boolean('is_locked')->default(false);
            $table->uuid('accepted_answer_id')->nullable();
            $table->string('status', 10)->default('published');      // published | hidden | deleted
            $table->json('edits')->nullable();                       // edit history (previous bodies and times)
            $table->timestamp('edited_at')->nullable();
            $table->unsignedInteger('comments_count')->default(0);
            $table->unsignedInteger('reactions_count')->default(0);
            $table->timestamps();
            $table->index(['space_id', 'status', 'is_pinned', 'created_at']);
        });

        Schema::create('comments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('post_id')->constrained()->cascadeOnDelete();
            $table->uuid('parent_id')->nullable()->index();
            $table->foreignUuid('author_id')->constrained('users')->cascadeOnDelete();
            $table->text('body');
            $table->json('attachments')->nullable();
            $table->string('status', 10)->default('published');
            $table->timestamp('edited_at')->nullable();
            $table->timestamps();
            $table->index(['post_id', 'created_at']);
        });

        Schema::create('reactions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('target_type', 8);                        // post | comment
            $table->uuid('target_id');
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 10)->default('like');             // like | insightful | thanks
            $table->timestamps();
            $table->unique(['target_type', 'target_id', 'user_id']);
        });

        Schema::create('polls', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('post_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('question', 255);
            $table->json('options');                                 // [{id, text}]
            $table->boolean('multiple')->default(false);
            $table->boolean('anonymous')->default(false);
            $table->timestamp('closes_at')->nullable();
            $table->timestamps();
        });

        Schema::create('poll_votes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('poll_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->json('option_ids');
            $table->timestamps();
            $table->unique(['poll_id', 'user_id']);
        });

        Schema::create('space_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('space_id')->constrained()->cascadeOnDelete();
            $table->string('title', 255);
            $table->timestamp('starts_at');
            $table->timestamp('ends_at')->nullable();
            $table->string('location', 255)->nullable();
            $table->string('online_url', 500)->nullable();
            $table->text('agenda')->nullable();
            $table->boolean('rsvp_required')->default(false);
            $table->timestamp('reminded_at')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['space_id', 'starts_at']);
        });

        Schema::create('space_event_rsvps', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('event_id')->constrained('space_events')->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->string('status', 8)->default('going');           // going | maybe | no
            $table->timestamps();
            $table->unique(['event_id', 'user_id']);
        });

        Schema::create('content_ratings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('subject_type', 12);                      // lesson | material | kit | library | program
            $table->uuid('subject_id');
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('stars');
            $table->text('review')->nullable();
            $table->string('status', 10)->default('published');      // published | hidden
            $table->timestamps();
            $table->unique(['subject_type', 'subject_id', 'user_id']);
            $table->index(['subject_type', 'subject_id', 'status']);
        });

        Schema::create('abuse_reports', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('target_type', 8);                        // post | comment
            $table->uuid('target_id');
            $table->foreignUuid('reporter_id')->constrained('users')->cascadeOnDelete();
            $table->string('reason', 24);
            $table->text('note')->nullable();
            $table->string('status', 10)->default('open');           // open | actioned | dismissed
            $table->string('action', 16)->nullable();                // hidden | warned | banned | none
            $table->foreignUuid('handled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('handled_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'created_at']);
        });

        Schema::create('lesson_notes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('lesson_id')->constrained('course_lessons')->cascadeOnDelete();
            $table->text('body');
            $table->timestamps();
            $table->index(['user_id', 'lesson_id']);
        });

        Schema::create('course_questions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('asker_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUuid('program_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('group_id')->nullable()->constrained('training_groups')->nullOnDelete();
            $table->foreignUuid('lesson_id')->nullable()->constrained('course_lessons')->nullOnDelete();
            $table->string('visibility', 8)->default('private');     // private | group
            $table->string('subject', 200);
            $table->text('body');
            $table->string('status', 10)->default('open');           // open | answered | closed
            $table->text('answer')->nullable();
            $table->foreignUuid('answered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('answered_at')->nullable();
            $table->timestamp('due_at')->nullable();                 // the trainers' service-level target
            $table->foreignUuid('post_id')->nullable();              // the forum post made for a group-visible question
            $table->timestamps();
            $table->index(['program_id', 'status']);
        });

        // ---- gamification ----------------------------------------------------------------------------
        Schema::create('gamification_rules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('event', 32)->unique();
            $table->integer('points')->default(0);
            $table->json('caps')->nullable();                        // {day: n, week: n}
            $table->json('conditions')->nullable();                  // {threshold: n} etc.
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('point_ledger', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->string('event', 32);
            $table->integer('points');
            $table->string('source_type', 24)->nullable();
            $table->string('source_id', 64)->nullable();
            $table->string('note', 255)->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['user_id', 'created_at']);
            $table->index(['event', 'source_type', 'source_id']);
        });

        Schema::create('levels', function (Blueprint $table) {
            $table->unsignedSmallInteger('level_no')->primary();
            $table->string('name_ar', 80);
            $table->string('name_en', 80);
            $table->unsignedInteger('min_points');
            $table->string('icon', 32)->nullable();
            $table->timestamps();
        });

        Schema::create('badges', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code', 40)->unique();
            $table->string('name_ar', 120);
            $table->string('name_en', 120);
            $table->text('description_ar')->nullable();
            $table->text('description_en')->nullable();
            $table->text('icon_svg')->nullable();                    // sanitised SVG
            $table->string('tier', 8)->default('bronze');            // bronze | silver | gold
            $table->json('criteria');                                // {event, count, within_days?}
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('user_badges', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('badge_id')->constrained()->cascadeOnDelete();
            $table->string('source', 24)->default('rule');           // rule | challenge | manual
            $table->timestamp('awarded_at');
            $table->timestamps();
            $table->unique(['user_id', 'badge_id']);
        });

        Schema::create('challenges', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('title_ar');
            $table->string('title_en');
            $table->text('description_ar')->nullable();
            $table->text('description_en')->nullable();
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->json('audience')->nullable();                    // {schools: [], roles: []}
            $table->json('goal');                                    // {event, count}
            $table->json('reward')->nullable();                      // {points, badge_code, text}
            $table->string('type', 10)->default('individual');       // individual | school
            $table->boolean('is_active')->default(true);
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
            $table->index(['is_active', 'starts_at', 'ends_at']);
        });

        Schema::create('challenge_participants', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('challenge_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('progress')->default(0);
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->unique(['challenge_id', 'user_id']);
        });

        Schema::create('rewards', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('title_ar');
            $table->string('title_en');
            $table->text('description_ar')->nullable();
            $table->text('description_en')->nullable();
            $table->string('kind', 12)->default('voucher');          // certificate | content | voucher
            $table->unsignedInteger('cost_points');
            $table->unsignedSmallInteger('min_level')->default(1);
            $table->integer('stock')->nullable();                    // null = unlimited
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('reward_redemptions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('reward_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('points');
            $table->string('code', 24)->nullable();
            $table->string('status', 10)->default('granted');        // granted | fulfilled | cancelled
            $table->timestamps();
        });

        Schema::create('leaderboard_snapshots', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('period', 8);                             // week | month | term
            $table->string('scope', 12);                             // ministry | school_group | school | program
            $table->uuid('scope_id')->nullable();
            $table->date('period_start');
            $table->json('ranks');                                   // [{user_id, points, rank}]
            $table->timestamps();
            $table->index(['period', 'scope', 'period_start']);
        });

        Schema::create('gamification_profiles', function (Blueprint $table) {
            $table->foreignUuid('user_id')->primary()->constrained()->cascadeOnDelete();
            $table->boolean('hidden')->default(false);               // out of public leaderboards
            $table->timestamps();
        });

        Defaults::seed();   // starting rules, levels and badges (off until the flag is on)
    }

    public function down(): void
    {
        foreach (['gamification_profiles', 'leaderboard_snapshots', 'reward_redemptions', 'rewards', 'challenge_participants', 'challenges', 'user_badges', 'badges', 'levels', 'point_ledger', 'gamification_rules',
            'course_questions', 'lesson_notes', 'abuse_reports', 'content_ratings', 'space_event_rsvps', 'space_events', 'poll_votes', 'polls', 'reactions', 'comments', 'posts', 'space_members', 'spaces'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
