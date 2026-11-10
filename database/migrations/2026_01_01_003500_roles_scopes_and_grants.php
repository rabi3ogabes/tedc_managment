<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        // School groups (directorates, clusters, stages…): a scope a role can be granted at.
        Schema::create('school_groups', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code', 40)->unique();
            $table->string('name_ar');
            $table->string('name_en');
            $table->string('type', 20)->default('custom');   // directorate | cluster | stage | custom
            $table->text('description')->nullable();
            $table->timestamps();
        });
        Schema::create('school_group_school', function (Blueprint $table) {
            $table->foreignUuid('school_group_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('school_id')->constrained()->cascadeOnDelete();
            $table->primary(['school_group_id', 'school_id']);
        });

        Schema::table('roles', function (Blueprint $table) {
            $table->json('scope_levels')->nullable();        // which scopes the role may be granted at; null = any
            $table->string('landing_route', 120)->nullable();
        });

        // A role is now granted with a scope (Ministry / school group / school / department), so the same role
        // can be held several times by one user. The pivot gets its own id and the primary key moves to it.
        Schema::create('role_user_new', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('role_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->string('scope_type', 20)->default('ministry');
            $table->uuid('scope_id')->nullable();
            $table->foreignUuid('granted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('granted_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'role_id']);
        });

        $schoolAdmin = DB::table('roles')->where('slug', 'school_admin')->value('id');
        $schools = DB::table('employees')->whereNotNull('user_id')->pluck('school_id', 'user_id');
        foreach (DB::table('role_user')->get() as $row) {
            // A school administrator keeps working inside their own school.
            $school = $row->role_id === $schoolAdmin ? ($schools[$row->user_id] ?? null) : null;
            DB::table('role_user_new')->insert([
                'id' => (string) Str::uuid7(), 'role_id' => $row->role_id, 'user_id' => $row->user_id,
                'scope_type' => $school ? 'school' : 'ministry', 'scope_id' => $school,
                'granted_at' => $row->created_at, 'created_at' => $row->created_at, 'updated_at' => $row->updated_at,
            ]);
        }
        Schema::drop('role_user');
        Schema::rename('role_user_new', 'role_user');

        // One grant per user, role and scope (a missing scope id counts as one value, which a plain unique index would not).
        DB::statement(DB::getDriverName() === 'pgsql'
            ? "CREATE UNIQUE INDEX role_user_unique_scope ON role_user (user_id, role_id, scope_type, COALESCE(scope_id, '00000000-0000-0000-0000-000000000000'::uuid))"
            : "CREATE UNIQUE INDEX role_user_unique_scope ON role_user (user_id, role_id, scope_type, COALESCE(scope_id, '00000000-0000-0000-0000-000000000000'))");

        Schema::table('users', function (Blueprint $table) {
            $table->foreignUuid('active_role_user_id')->nullable()->constrained('role_user')->nullOnDelete();
        });

        // Per-program rights given by the head of training: attendance, notifications, kits, task review.
        Schema::create('program_grants', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('program_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->string('ability', 40);                  // attendance.mark | notifications.send | kits.assign | tasks.review
            $table->foreignUuid('granted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
            $table->unique(['program_id', 'user_id', 'ability']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('program_grants');
        Schema::table('users', fn (Blueprint $t) => $t->dropConstrainedForeignId('active_role_user_id'));

        Schema::create('role_user_old', function (Blueprint $table) {
            $table->foreignUuid('role_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->primary(['role_id', 'user_id']);
            $table->timestamps();
        });
        foreach (DB::table('role_user')->get()->unique(fn ($r) => $r->role_id.$r->user_id) as $row) {
            DB::table('role_user_old')->insert(['role_id' => $row->role_id, 'user_id' => $row->user_id, 'created_at' => $row->created_at, 'updated_at' => $row->updated_at]);
        }
        Schema::drop('role_user');
        Schema::rename('role_user_old', 'role_user');

        Schema::table('roles', fn (Blueprint $t) => $t->dropColumn(['scope_levels', 'landing_route']));
        Schema::dropIfExists('school_group_school');
        Schema::dropIfExists('school_groups');
    }
};
