<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TEC-07, the contract kit: the administrator's versions of a system's contract and mapping (a row with no document means "the one
 * shipped with TEDC"), and the go-live checklist ticks (who, when) of each system.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integration_contract_versions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('integration_key', 40);
            $table->string('kind', 10);                                  // contract | mapping
            $table->unsignedInteger('version');
            $table->json('document')->nullable();                        // null: the document shipped with TEDC
            $table->string('source', 10);                                // override | rollback | reset
            $table->unsignedInteger('restored_from')->nullable();
            $table->string('note', 200)->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['integration_key', 'kind', 'version']);
        });

        Schema::create('integration_checklist_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('integration_key', 40);
            $table->string('item', 60);
            $table->foreignUuid('done_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('done_at')->nullable();
            $table->string('note', 300)->nullable();
            $table->timestamps();
            $table->unique(['integration_key', 'item']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_checklist_items');
        Schema::dropIfExists('integration_contract_versions');
    }
};
