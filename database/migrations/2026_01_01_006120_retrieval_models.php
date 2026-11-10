<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AI-05 retrieval: every stored vector names the embedder that made it and its size, so vectors of different models are
 * never compared; one piece of content may be held by several embedders at once (the local one always, a model one when
 * configured). Vectors already stored came from the first local embedder (local-hash-256); tedc:deploy replaces them with
 * the current one. Re-embedding runs and golden-set evaluations are recorded. On PostgreSQL with the pgvector extension
 * the vectors are also kept in a native column with an HNSW index; everywhere else (SQLite, no extension) this part does
 * nothing and the JSON vectors are used.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('embeddings', function (Blueprint $table) {
            $table->string('model', 80)->default('local-hash-256');
            $table->unsignedSmallInteger('dim')->default(256);
        });
        Schema::table('embeddings', function (Blueprint $table) {
            $table->dropUnique(['source_type', 'source_id', 'chunk_no']);
            $table->unique(['source_type', 'source_id', 'chunk_no', 'model']);
            $table->index(['model', 'visibility']);
        });

        Schema::create('ai_index_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('model', 80);                       // the embedder being filled: local-hash-256 | model:<id>
            $table->unsignedSmallInteger('dim')->nullable();
            $table->string('status', 12)->default('queued');   // queued | running | done | failed
            $table->unsignedInteger('total')->default(0);
            $table->unsignedInteger('done')->default(0);
            $table->text('error')->nullable();
            $table->foreignUuid('started_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->index(['model', 'status']);
        });

        Schema::create('ai_eval_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('embedder', 80);
            $table->string('store', 12);
            $table->boolean('with_model')->default(false);
            $table->unsignedSmallInteger('questions');
            $table->unsignedSmallInteger('answerable');
            $table->unsignedSmallInteger('off_topic');
            $table->float('hit_at_3');
            $table->float('hit_at_5');
            $table->float('citation_accuracy');
            $table->float('refusal_accuracy');
            $table->unsignedSmallInteger('false_refusals')->default(0);
            $table->boolean('passed');
            $table->json('details')->nullable();
            $table->foreignUuid('started_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        $this->pgvector();
    }

    /** The native vector column, only where the database offers pgvector; any failure leaves the JSON vectors in charge. */
    private function pgvector(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        try {
            DB::transaction(fn () => DB::statement('CREATE EXTENSION IF NOT EXISTS vector'));   // a savepoint: a refusal does not spoil the migration
        } catch (Throwable) {
            return;
        }
        DB::statement('ALTER TABLE embeddings ADD COLUMN IF NOT EXISTS vec vector');
        // The index of the local embedder (1024 numbers); tedc:deploy fills the vectors, an embeddings model gets its own index when a run finishes.
        DB::statement("CREATE INDEX IF NOT EXISTS embeddings_vec_local ON embeddings USING hnsw ((vec::vector(1024)) vector_cosine_ops) WHERE model = 'local-hash-1024'");
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql' && Schema::hasColumn('embeddings', 'vec')) {
            DB::statement('DROP INDEX IF EXISTS embeddings_vec_local');
            DB::statement('ALTER TABLE embeddings DROP COLUMN vec');
        }
        Schema::dropIfExists('ai_eval_runs');
        Schema::dropIfExists('ai_index_runs');
        DB::table('embeddings')->delete();   // derived data: rebuilt by tedc:deploy
        Schema::table('embeddings', function (Blueprint $table) {
            $table->dropIndex(['model', 'visibility']);
            $table->dropUnique(['source_type', 'source_id', 'chunk_no', 'model']);
            $table->unique(['source_type', 'source_id', 'chunk_no']);
        });
        Schema::table('embeddings', fn (Blueprint $table) => $table->dropColumn(['model', 'dim']));
    }
};
