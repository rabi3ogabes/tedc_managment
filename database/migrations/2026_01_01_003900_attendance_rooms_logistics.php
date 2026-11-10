<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 05 — more attendance methods (signature, staff scan, fingerprint), trainer attendance, absence alerts, excuses,
 * leaves, places/buildings, room bookings, seating plans and logistics requests.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance', function (Blueprint $table) {
            $table->string('signature_path')->nullable();
            $table->uuid('device_id')->nullable();
            $table->uuid('excuse_id')->nullable();
            $table->unsignedSmallInteger('leave_minutes')->default(0);
            $table->timestamp('left_early_at')->nullable();
            $table->text('notes')->nullable();
        });

        Schema::table('program_sessions', function (Blueprint $table) {
            $table->unsignedSmallInteger('checkin_window_minutes')->nullable();
            $table->unsignedSmallInteger('checkout_window_minutes')->nullable();
        });

        Schema::create('trainer_attendance', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('session_id')->constrained('program_sessions')->cascadeOnDelete();
            $table->foreignUuid('trainer_id')->constrained()->cascadeOnDelete();
            $table->timestamp('check_in_at')->nullable();
            $table->timestamp('check_out_at')->nullable();
            $table->string('method', 16)->default('manual');   // qr | staff_scan | fingerprint | manual
            $table->foreignUuid('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['session_id', 'trainer_id']);
        });

        Schema::create('attendance_devices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('vendor', 16)->default('generic_http');   // zkteco | suprema | generic_http | csv
            $table->string('serial', 80)->nullable();
            $table->foreignUuid('location_room_id')->nullable()->constrained('training_rooms')->nullOnDelete();
            $table->text('api_config')->nullable();   // encrypted json
            $table->timestamp('last_sync_at')->nullable();
            $table->string('status', 12)->default('active');
            $table->text('last_error')->nullable();
            $table->timestamps();
        });

        Schema::create('device_punches', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('device_id')->constrained('attendance_devices')->cascadeOnDelete();
            $table->string('person_ref', 80);
            $table->timestamp('punched_at');
            $table->string('direction', 8)->default('unknown');
            $table->json('raw')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->string('outcome', 24)->nullable();   // matched | duplicate | unmatched_person | unmatched_session
            $table->uuid('attendance_id')->nullable();
            $table->timestamps();
            $table->unique(['device_id', 'person_ref', 'punched_at']);
        });

        Schema::create('absence_excuses', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('registration_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('session_id')->nullable()->constrained('program_sessions')->nullOnDelete();
            $table->foreignUuid('employee_id')->constrained()->cascadeOnDelete();
            $table->string('reason_code', 24);   // sick_leave | bereavement | work_assignment | other
            $table->text('reason_text')->nullable();
            $table->json('attachments')->nullable();
            $table->date('from_date')->nullable();
            $table->date('to_date')->nullable();
            $table->string('status', 10)->default('pending');   // pending | approved | rejected
            $table->foreignUuid('manager_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_note')->nullable();
            $table->timestamps();
            $table->index(['status', 'manager_id']);
        });

        Schema::create('attendance_leaves', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('attendance_id')->constrained('attendance')->cascadeOnDelete();
            $table->string('type', 16);   // late_arrival | early_leave | temporary
            $table->time('from_time')->nullable();
            $table->time('to_time')->nullable();
            $table->unsignedSmallInteger('minutes')->default(0);
            $table->text('reason')->nullable();
            $table->json('attachments')->nullable();
            $table->foreignUuid('entered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('notified_at')->nullable();
            $table->timestamps();
        });

        Schema::create('absence_alerts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('registration_id')->constrained()->cascadeOnDelete();
            $table->string('level', 10);   // warning | breach
            $table->decimal('absence_percent', 5, 2);
            $table->text('supervisor_note')->nullable();
            $table->timestamp('notified_at')->nullable();
            $table->timestamps();
            $table->unique(['registration_id', 'level']);
        });

        Schema::create('training_places', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name_ar');
            $table->string('name_en');
            $table->string('address')->nullable();
            $table->string('map_url')->nullable();
            $table->string('website')->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->unsignedInteger('capacity_limit')->nullable();
            $table->timestamps();
        });
        Schema::create('buildings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('place_id')->constrained('training_places')->cascadeOnDelete();
            $table->string('name_ar');
            $table->string('name_en');
            $table->unsignedInteger('capacity_limit')->nullable();
            $table->timestamps();
        });
        Schema::table('training_rooms', function (Blueprint $table) {
            $table->foreignUuid('place_id')->nullable()->constrained('training_places')->nullOnDelete();
            $table->foreignUuid('building_id')->nullable()->constrained('buildings')->nullOnDelete();
        });

        Schema::create('room_bookings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('room_id')->constrained('training_rooms')->cascadeOnDelete();
            $table->string('purpose', 12)->default('meeting');   // training | meeting | exam | event | maintenance | other
            $table->string('title');
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->foreignUuid('booked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('session_id')->nullable()->constrained('program_sessions')->nullOnDelete();
            $table->string('status', 10)->default('confirmed');   // confirmed | cancelled
            $table->unsignedInteger('attendees')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['room_id', 'starts_at']);
        });

        Schema::create('seating_plans', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('room_id')->constrained('training_rooms')->cascadeOnDelete();
            $table->foreignUuid('session_id')->nullable()->constrained('program_sessions')->cascadeOnDelete();
            $table->foreignUuid('group_id')->nullable()->constrained('training_groups')->cascadeOnDelete();
            $table->json('layout');        // {rows, cols, blocked: ["r,c"], labels: {}}
            $table->json('assignments')->nullable();   // {"r,c": registration_id}
            $table->timestamps();
        });

        Schema::create('logistics_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('session_id')->nullable()->constrained('program_sessions')->nullOnDelete();
            $table->foreignUuid('group_id')->nullable()->constrained('training_groups')->nullOnDelete();
            $table->foreignUuid('booking_id')->nullable()->constrained('room_bookings')->nullOnDelete();
            $table->foreignUuid('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('items');
            $table->text('notes')->nullable();
            $table->timestamp('needed_by')->nullable();
            $table->string('status', 12)->default('new');   // new | in_progress | done | rejected
            $table->foreignUuid('assignee_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->text('comment')->nullable();
            $table->timestamp('overdue_notified_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'needed_by']);
        });
    }

    public function down(): void
    {
        foreach (['logistics_requests', 'seating_plans', 'room_bookings'] as $t) {
            Schema::dropIfExists($t);
        }
        Schema::table('training_rooms', function (Blueprint $table) {
            $table->dropConstrainedForeignId('building_id');
            $table->dropConstrainedForeignId('place_id');
        });
        foreach (['buildings', 'training_places', 'absence_alerts', 'attendance_leaves', 'absence_excuses', 'device_punches', 'attendance_devices', 'trainer_attendance'] as $t) {
            Schema::dropIfExists($t);
        }
        Schema::table('program_sessions', fn (Blueprint $t) => $t->dropColumn(['checkin_window_minutes', 'checkout_window_minutes']));
        Schema::table('attendance', fn (Blueprint $t) => $t->dropColumn(['signature_path', 'device_id', 'excuse_id', 'leave_minutes', 'left_early_at', 'notes']));
    }
};
