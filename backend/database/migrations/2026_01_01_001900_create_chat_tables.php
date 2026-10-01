<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** The website's chat assistant: conversations with visitors (answered by the AI bot or by the administration team). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_conversations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('token_hash', 64);                       // the visitor holds the secret, we keep its hash
            $table->foreignUuid('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('visitor_name', 120)->nullable();
            $table->string('visitor_email', 190)->nullable();
            $table->string('locale', 2)->default('ar');
            $table->string('mode', 8)->default('bot');               // bot | human (an administrator took over)
            $table->string('status', 8)->default('open');            // open | closed
            $table->boolean('needs_human')->default(false);
            $table->unsignedInteger('admin_unread')->default(0);
            $table->unsignedInteger('visitor_unread')->default(0);
            $table->unsignedInteger('messages_count')->default(0);
            $table->string('user_agent', 160)->nullable();
            $table->timestamp('last_message_at')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('chat_messages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('conversation_id')->constrained('chat_conversations')->cascadeOnDelete();
            $table->string('sender', 8);                             // visitor | bot | admin
            $table->foreignUuid('admin_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('body');
            $table->json('meta')->nullable();                        // program_codes, refused, source (ai | rules)
            $table->timestamp('created_at')->useCurrent();
            $table->index(['conversation_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_messages');
        Schema::dropIfExists('chat_conversations');
    }
};
