<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Phase 18: help-centre articles (role-targeted, linked to pages, versioned), feedback and the tours each user has finished. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('help_articles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('slug', 120)->unique();
            $table->string('title_ar');
            $table->string('title_en');
            $table->text('body_ar')->nullable();
            $table->text('body_en')->nullable();
            $table->json('roles')->nullable();                 // role slugs that see it; empty = everyone
            $table->string('module', 40)->default('general');
            $table->json('related_routes')->nullable();        // page paths where the "?" button offers it, e.g. ["/my-training", "/courses/*"]
            $table->string('video_url', 500)->nullable();
            $table->string('video_asset', 500)->nullable();    // uploaded screen recording (storage path)
            $table->json('screenshots')->nullable();           // [{path, caption_ar, caption_en}]
            $table->unsignedInteger('sort_order')->default(0);
            $table->string('status', 12)->default('published'); // draft | published
            $table->unsignedInteger('version')->default(1);
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['status', 'module']);
        });

        Schema::create('help_article_versions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('article_id')->constrained('help_articles')->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->json('snapshot');
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['article_id', 'version']);
        });

        Schema::create('help_feedback', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('article_id')->constrained('help_articles')->cascadeOnDelete();
            $table->foreignUuid('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('helpful');
            $table->string('comment', 1000)->nullable();
            $table->unsignedInteger('article_version')->default(1);
            $table->timestamps();
            $table->index(['article_id', 'helpful']);
        });

        Schema::create('user_tours', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->string('tour_key', 80);                    // first-login:<role> or whats-new:<release>
            $table->string('state', 10)->default('done');      // done | dismissed
            $table->timestamps();
            $table->unique(['user_id', 'tour_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_tours');
        Schema::dropIfExists('help_feedback');
        Schema::dropIfExists('help_article_versions');
        Schema::dropIfExists('help_articles');
    }
};
