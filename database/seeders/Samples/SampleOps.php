<?php

namespace Database\Seeders\Samples;

use App\Models\AbsenceAlert;
use App\Models\AbsenceExcuse;
use App\Models\Attendance;
use App\Models\AttendanceAttempt;
use App\Models\AttendanceDevice;
use App\Models\AttendanceLeave;
use App\Models\Building;
use App\Models\GroupSeatAllocation;
use App\Models\GroupTrainer;
use App\Models\ProgramSession;
use App\Models\RoomBooking;
use App\Models\SeatHold;
use App\Models\SeatingPlan;
use App\Models\TrainerAttendance;
use App\Models\TrainingPlace;
use App\Models\TrainingRoom;

/** Training places and buildings, room bookings, attendance with leaves and excuses, absence alerts, devices, trainer check-in and seat control. */
class SampleOps
{
    use Steps;

    public function __construct(private readonly SampleContext $c) {}

    public function run(): void
    {
        $this->step('places', fn () => $this->places());
        $this->step('attendance', fn () => $this->attendance());
        $this->step('groups', fn () => $this->groups());
    }

    private function places(): void
    {
        $place = TrainingPlace::firstOrCreate(['name_en' => 'Doha Training Campus'], ['name_ar' => 'حرم الدوحة التدريبي', 'address' => 'الدوحة – المنطقة التعليمية', 'latitude' => 25.2854, 'longitude' => 51.5310, 'capacity_limit' => 400, 'website' => 'https://example.qa/campus']);
        $b = Building::firstOrCreate(['place_id' => $place->id, 'name_en' => 'Building A'], ['name_ar' => 'المبنى أ', 'capacity_limit' => 200]);
        $room = TrainingRoom::firstOrCreate(['code' => 'SMP-A1'], ['name_ar' => 'قاعة أ-١', 'name_en' => 'Hall A1', 'capacity' => 40, 'building_id' => $b->id]);
        $trainer = $this->c->user('trainer@tedc.qa') ?? $this->c->admin();
        if (! RoomBooking::where('room_id', $room->id)->exists()) {
            foreach ([['اجتماع فريق الجودة', 1, 'meeting'], ['ورشة داخلية للمعلمين', 2, 'workshop'], ['تصوير مواد تدريبية', 4, 'other']] as [$title, $d, $purpose]) {
                RoomBooking::create(['room_id' => $room->id, 'purpose' => $purpose, 'title' => $title, 'starts_at' => now()->addDays($d)->setTime(9, 0), 'ends_at' => now()->addDays($d)->setTime(12, 0), 'booked_by' => $trainer->id, 'status' => 'confirmed', 'attendees' => 15, 'notes' => 'حجز تجريبي']);
            }
        }
        if (! AttendanceDevice::where('serial', 'SMP-FP-001')->exists()) {
            AttendanceDevice::create(['name' => 'جهاز بصمة المدخل الرئيسي', 'vendor' => 'generic', 'serial' => 'SMP-FP-001', 'location_room_id' => $room->id, 'status' => 'active', 'last_sync_at' => now()->subHour()]);
        }
    }

    private function attendance(): void
    {
        $t = $this->c->trainees();
        $program = $this->c->program('TEST-P2') ?? $this->c->program('TEST-P1');
        if (! $program || ! $t || AttendanceLeave::query()->exists()) {
            return;
        }
        $sessions = ProgramSession::where('program_id', $program->id)->orderBy('starts_at')->limit(5)->get();
        foreach ($t as $i => $u) {
            $reg = $this->c->registration($u, $program);
            $emp = $u->employee;
            if (! $reg || ! $emp) {
                continue;
            }
            foreach ($sessions as $k => $s) {
                $status = ($k + $i) % 4 === 3 ? 'absent' : (($k + $i) % 4 === 2 ? 'late' : 'present');
                $att = Attendance::firstOrCreate(['program_session_id' => $s->id, 'registration_id' => $reg->id], ['employee_id' => $emp->id, 'training_group_id' => $s->training_group_id, 'check_in_at' => $s->starts_at->copy()->addMinutes($status === 'late' ? 25 : 2), 'check_out_at' => $status === 'absent' ? null : $s->ends_at, 'method' => 'qr', 'status' => $status, 'minutes_attended' => $status === 'absent' ? 0 : 180]);
                if ($status === 'late' && ! AttendanceLeave::where('attendance_id', $att->id)->exists()) {
                    AttendanceLeave::create(['attendance_id' => $att->id, 'type' => 'late_arrival', 'from_time' => '07:00', 'to_time' => '07:25', 'minutes' => 25, 'reason' => 'ازدحام مروري', 'entered_by' => $this->c->admin()->id]);
                }
                if ($status === 'absent') {
                    AbsenceExcuse::firstOrCreate(['registration_id' => $reg->id, 'session_id' => $s->id], ['employee_id' => $emp->id, 'reason_code' => 'sick_leave', 'reason_text' => 'وعكة صحية مع تقرير طبي.', 'from_date' => $s->starts_at->toDateString(), 'to_date' => $s->starts_at->toDateString(), 'status' => $i % 2 ? 'approved' : 'pending']);
                }
            }
            AbsenceAlert::firstOrCreate(['registration_id' => $reg->id, 'level' => $i === 3 ? 'breach' : 'warning'], ['absence_percent' => 18 + $i * 6, 'notified_at' => now()->subDay()]);
            AttendanceAttempt::create(['employee_id' => $emp->id, 'program_id' => $program->id, 'program_session_id' => $sessions->first()?->id, 'outcome' => 'rejected', 'code' => 'outside_geofence', 'message' => 'الموقع خارج النطاق المسموح.', 'device_info' => 'Android sample', 'ip_address' => '10.0.0.'.($i + 10)]);
        }
        if ($sessions->first() && ($trainer = $this->c->user('trainer@tedc.qa')?->trainer) && ! TrainerAttendance::where('session_id', $sessions->first()->id)->exists()) {
            TrainerAttendance::create(['session_id' => $sessions->first()->id, 'trainer_id' => $trainer->id, 'check_in_at' => $sessions->first()->starts_at->copy()->subMinutes(10), 'check_out_at' => $sessions->first()->ends_at, 'method' => 'qr', 'notes' => 'حضور تجريبي']);
        }
    }

    private function groups(): void
    {
        $trainer = $this->c->user('trainer@tedc.qa')?->trainer;
        $admin = $this->c->admin();
        foreach ($this->c->groups(3) as $i => $g) {
            if ($trainer && ! GroupTrainer::where('group_id', $g->id)->where('trainer_id', $trainer->id)->exists()) {
                GroupTrainer::create(['group_id' => $g->id, 'trainer_id' => $trainer->id, 'role' => 'lead', 'hours' => 12, 'status' => $i === 0 ? 'approved' : 'proposed', 'proposed_by' => $admin->id, 'decided_by' => $i === 0 ? $admin->id : null, 'decided_at' => $i === 0 ? now()->subDay() : null]);
            }
            if (! GroupSeatAllocation::where('group_id', $g->id)->exists()) {
                GroupSeatAllocation::create(['group_id' => $g->id, 'entity_type' => 'school_group', 'entity_id' => $g->id, 'seats' => 10, 'release_at' => now()->addDays(10), 'priority' => 1]);
            }
            if ($i === 0 && ! SeatHold::where('group_id', $g->id)->exists()) {
                SeatHold::create(['group_id' => $g->id, 'kind' => 'cart', 'owner_id' => $admin->id, 'quantity' => 2, 'expires_at' => now()->addHours(2)]);
                if ($room = TrainingRoom::where('code', 'SMP-A1')->first()) {
                    SeatingPlan::firstOrCreate(['room_id' => $room->id, 'group_id' => $g->id], ['layout' => 'classroom', 'assignments' => []]);
                }
            }
        }
    }
}
