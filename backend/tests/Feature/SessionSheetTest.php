<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\AttendanceLeave;
use App\Models\Program;
use App\Models\ProgramSession;
use App\Models\Registration;
use App\Models\Role;
use App\Models\SessionRemark;
use App\Models\Trainer;
use App\Models\TrainingKit;
use App\Models\User;
use Illuminate\Support\Str;
use Tests\TestCase;

/** The attendance sheet in the hands of the people who run a session: a trainee who left early, a comment about a trainee, a remark about the session; and what a trainer gets with each group. */
class SessionSheetTest extends TestCase
{
    private User $user;

    private Trainer $trainer;

    private Program $program;

    private ProgramSession $session;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = $this->makeUser(Role::TRAINER);
        $this->trainer = Trainer::create(['user_id' => $this->user->id, 'name_ar' => 'مدرب', 'name_en' => 'Trainer', 'email' => $this->user->email, 'status' => 'active']);
        $this->program = $this->makeProgram(['status' => Program::STATUS_IN_PROGRESS, 'start_date' => today()->subDay(), 'end_date' => today()->addDay(), 'total_hours' => 15]);
        $this->program->trainers()->attach($this->trainer->id, ['role' => 'lead']);
        // Three hours, one of them already gone.
        $this->session = $this->makeSession($this->program, now()->startOfMinute()->subHour(), 3);
    }

    private function trainee(): Registration
    {
        return Registration::create(['program_id' => $this->program->id, 'employee_id' => $this->makeEmployee()->id, 'source' => 'self', 'status' => Registration::STATUS_APPROVED]);
    }

    private function row(array $sheet, Registration $registration): array
    {
        return collect($sheet['rows'])->firstWhere('registration_id', $registration->id);
    }

    private function url(string $rest = ''): string
    {
        return "/api/v1/admin/sessions/{$this->session->id}{$rest}";
    }

    public function test_a_trainee_marked_present_leaves_early_and_the_sheet_counts_what_he_attended(): void
    {
        $registration = $this->trainee();
        $this->asUser($this->user)->postJson($this->url('/attendance'), ['registration_id' => $registration->id, 'status' => 'present'])->assertOk();
        $at = $this->session->starts_at->copy()->addMinutes(90);

        $sheet = $this->asUser($this->user)->postJson($this->url('/early-leave'), ['registration_id' => $registration->id, 'at' => $at->toIso8601String(), 'reason' => 'موعد طبي'])->assertOk()->json('data');
        $row = $this->row($sheet, $registration);
        $this->assertSame('present', $row['status'], 'he was present; leaving early does not make him absent');
        $this->assertNotNull($row['left_early_at']);
        $this->assertSame(90, $row['minutes']);
        $this->assertSame(90, $row['leave_minutes']);
        $leave = AttendanceLeave::where('attendance_id', $row['attendance_id'])->sole();
        $this->assertSame('early_leave', $leave->type);
        $this->assertSame('موعد طبي', $leave->reason);
        $this->assertSame($this->user->id, $leave->entered_by);
        $this->assertTrue(Attendance::find($row['attendance_id'])->check_out_at->equalTo($at));

        // Recording it again moves the time: one leave, not two.
        $later = $this->session->starts_at->copy()->addMinutes(120);
        $row = $this->row($this->asUser($this->user)->postJson($this->url('/early-leave'), ['registration_id' => $registration->id, 'at' => $later->toIso8601String()])->assertOk()->json('data'), $registration);
        $this->assertSame(120, $row['minutes']);
        $this->assertSame(60, $row['leave_minutes']);
        $this->assertSame(1, AttendanceLeave::where('attendance_id', $row['attendance_id'])->count());

        // He stayed after all: the mark made by hand runs to the end of the session again.
        $row = $this->row($this->asUser($this->user)->deleteJson($this->url("/early-leave/{$registration->id}"))->assertOk()->json('data'), $registration);
        $this->assertNull($row['left_early_at']);
        $this->assertSame(180, $row['minutes']);
        $this->assertSame(0, $row['leave_minutes']);
        $this->assertSame(0, AttendanceLeave::count());
    }

    public function test_an_early_leave_needs_somebody_present_and_a_time_inside_the_session(): void
    {
        $registration = $this->trainee();
        // Nobody can leave a session he did not come to.
        $this->asUser($this->user)->postJson($this->url('/early-leave'), ['registration_id' => $registration->id])->assertStatus(422);
        $this->asUser($this->user)->postJson($this->url('/attendance'), ['registration_id' => $registration->id, 'status' => 'absent'])->assertOk();
        $this->asUser($this->user)->postJson($this->url('/early-leave'), ['registration_id' => $registration->id])->assertStatus(422)->assertJsonPath('code', 'leave_not_present');

        $this->asUser($this->user)->postJson($this->url('/attendance'), ['registration_id' => $registration->id, 'status' => 'present'])->assertOk();
        foreach ([$this->session->starts_at->copy()->subMinute(), $this->session->ends_at->copy()->addMinute()] as $outside) {
            $this->asUser($this->user)->postJson($this->url('/early-leave'), ['registration_id' => $registration->id, 'at' => $outside->toIso8601String()])->assertStatus(422)->assertJsonPath('code', 'leave_bad_time');
        }
        // With no time given it is now, an hour into the session.
        $row = $this->row($this->asUser($this->user)->postJson($this->url('/early-leave'), ['registration_id' => $registration->id])->assertOk()->json('data'), $registration);
        $this->assertEqualsWithDelta(60, $row['minutes'], 1);
        // A trainee of another programme is not found on this sheet.
        $foreign = Registration::create(['program_id' => $this->makeProgram()->id, 'employee_id' => $this->makeEmployee()->id, 'source' => 'self', 'status' => Registration::STATUS_APPROVED]);
        $this->asUser($this->user)->postJson($this->url('/early-leave'), ['registration_id' => $foreign->id])->assertNotFound();
    }

    public function test_a_comment_about_a_trainee_is_kept_with_his_attendance(): void
    {
        $present = $this->trainee();
        $unmarked = $this->trainee();
        $this->asUser($this->user)->postJson($this->url('/attendance'), ['registration_id' => $present->id, 'status' => 'present'])->assertOk();

        $sheet = $this->asUser($this->user)->putJson($this->url("/attendance/{$present->id}/note"), ['note' => '  شارك بفاعلية  '])->assertOk()->json('data');
        $this->assertSame('شارك بفاعلية', $this->row($sheet, $present)['note']);
        $this->assertSame('present', $this->row($sheet, $present)['status'], 'a comment does not change the mark');

        // A trainee with no mark yet keeps what the sheet already said about him: absent.
        $sheet = $this->asUser($this->user)->putJson($this->url("/attendance/{$unmarked->id}/note"), ['note' => 'اعتذر هاتفياً'])->assertOk()->json('data');
        $this->assertSame('absent', $this->row($sheet, $unmarked)['status']);
        $this->assertSame('اعتذر هاتفياً', $this->row($sheet, $unmarked)['note']);
        $this->assertSame(1, $sheet['absent']);

        // An empty comment removes it.
        $sheet = $this->asUser($this->user)->putJson($this->url("/attendance/{$present->id}/note"), ['note' => ''])->assertOk()->json('data');
        $this->assertNull($this->row($sheet, $present)['note']);
        $this->asUser($this->user)->putJson($this->url("/attendance/{$present->id}/note"), ['note' => str_repeat('ا', 1001)])->assertStatus(422);
    }

    public function test_the_people_who_run_a_session_each_write_a_remark_about_it_and_read_the_others(): void
    {
        $supervisor = $this->makeUser(Role::SUPERVISOR);
        $this->program->ensureGroup()->update(['supervisor_id' => $supervisor->id]);

        $sheet = $this->asUser($this->user)->putJson($this->url('/remark'), ['body' => 'جهاز العرض لا يعمل في القاعة.'])->assertOk()->json('data');
        $this->assertCount(1, $sheet['remarks']);
        $this->assertTrue($sheet['remarks'][0]['mine']);

        $sheet = $this->asUser($supervisor)->putJson($this->url('/remark'), ['body' => 'تم إبلاغ الدعم الفني.'])->assertOk()->json('data');
        $this->assertCount(2, $sheet['remarks']);
        $this->assertSame([false, true], array_column($sheet['remarks'], 'mine'));
        $this->assertSame('جهاز العرض لا يعمل في القاعة.', $sheet['remarks'][0]['body']);

        // Writing again rewrites one's own remark; an empty one removes it.
        $sheet = $this->asUser($this->user)->putJson($this->url('/remark'), ['body' => 'جهاز العرض أُصلح.'])->assertOk()->json('data');
        $this->assertCount(2, $sheet['remarks']);
        $this->assertSame(1, SessionRemark::where('user_id', $this->user->id)->count());
        $sheet = $this->asUser($this->user)->putJson($this->url('/remark'), ['body' => ' '])->assertOk()->json('data');
        $this->assertCount(1, $sheet['remarks']);
        // The sheet carries the remarks to whoever opens it.
        $read = $this->asUser($supervisor)->getJson($this->url('/attendance'))->assertOk()->json('data.remarks');
        $this->assertSame(['تم إبلاغ الدعم الفني.'], array_column($read, 'body'));
        $this->assertTrue($read[0]['mine']);
    }

    public function test_somebody_who_does_not_run_the_session_writes_nothing_on_its_sheet(): void
    {
        $registration = $this->trainee();
        $outsider = $this->makeUser(Role::TRAINER);
        Trainer::create(['user_id' => $outsider->id, 'name_ar' => 'آخر', 'name_en' => 'Other', 'email' => $outsider->email, 'status' => 'active']);

        $this->asUser($outsider)->postJson($this->url('/early-leave'), ['registration_id' => $registration->id])->assertForbidden();
        $this->asUser($outsider)->deleteJson($this->url("/early-leave/{$registration->id}"))->assertForbidden();
        $this->asUser($outsider)->putJson($this->url("/attendance/{$registration->id}/note"), ['note' => 'x'])->assertForbidden();
        $this->asUser($outsider)->putJson($this->url('/remark'), ['body' => 'x'])->assertForbidden();
        $this->asUser($this->makeEmployee()->user)->putJson($this->url('/remark'), ['body' => 'x'])->assertForbidden();
        $this->assertSame(0, SessionRemark::count());
    }

    public function test_a_trainer_gets_the_hours_the_kits_the_room_and_the_way_to_reach_the_supervisor(): void
    {
        $supervisor = $this->makeUser(Role::SUPERVISOR, ['phone' => '+97455500011']);
        $this->program->ensureGroup()->update(['supervisor_id' => $supervisor->id]);
        $kit = TrainingKit::create(['owner_id' => $supervisor->id, 'code' => 'KIT-1', 'title_ar' => 'حقيبة التعلم النشط', 'title_en' => 'Active learning kit', 'status' => TrainingKit::PUBLISHED, 'version' => 2, 'duration_hours' => 15]);
        $draft = TrainingKit::create(['owner_id' => $supervisor->id, 'code' => 'KIT-2', 'title_ar' => 'مسودة', 'title_en' => 'Draft', 'status' => 'draft']);
        foreach ([$kit, $draft] as $attached) {
            $this->program->kits()->attach($attached->id, ['id' => (string) Str::uuid()]);
        }

        $row = $this->asUser($this->user)->getJson('/api/v1/me/duties')->assertOk()->json('data.groups.0');
        $this->assertEquals(15, $row['program']['hours']);
        $this->assertArrayHasKey('has_course', $row['program']);
        $this->assertSame($supervisor->email, $row['supervisor']['email']);
        $this->assertSame('+97455500011', $row['supervisor']['phone']);
        $this->assertSame(['KIT-1'], array_column($row['kits'], 'code'), 'a kit that is not approved is not announced');
        $this->assertSame(2, $row['kits'][0]['version']);
        $this->assertArrayHasKey('room_id', $row['sessions'][0]);
        $this->assertArrayHasKey('map_place', $row['sessions'][0]);

        // The kits are for the people who teach the programme: its supervisor is not given them.
        $this->assertSame([], $this->asUser($supervisor)->getJson('/api/v1/me/duties')->assertOk()->json('data.groups.0.kits'));
    }
}
