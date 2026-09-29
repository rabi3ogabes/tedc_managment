<?php

namespace Tests\Feature;

use App\Models\CalendarApproval;
use App\Models\CalendarDay;
use App\Models\Role;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class CalendarTest extends TestCase
{
    /** A Sunday far enough away to be stable: 2027-03-07 (weekend = Fri 2027-03-12, Sat 2027-03-13). */
    private const SUNDAY = '2027-03-07';

    private function sessionPayload(string $startsAt, array $extra = []): array
    {
        $start = CarbonImmutable::parse($startsAt);

        return $extra + ['title_ar' => 'جلسة', 'title_en' => 'Session', 'starts_at' => $start->toDateTimeString(), 'ends_at' => $start->addHours(2)->toDateTimeString()];
    }

    public function test_calendar_lists_working_and_off_days_with_summary(): void
    {
        $admin = $this->makeUser(Role::CENTER_ADMIN);
        $this->asUser($admin)->postJson('/api/v1/admin/calendar/days', ['date' => '2027-03-09', 'type' => 'vacation', 'title_ar' => 'إجازة', 'title_en' => 'Holiday'])->assertCreated();
        $this->asUser($admin)->postJson('/api/v1/admin/calendar/days', ['date' => '2027-03-10', 'type' => 'exam', 'title_ar' => 'اختبارات', 'title_en' => 'Exams'])->assertCreated();

        $res = $this->asUser($admin)->getJson('/api/v1/admin/calendar?from=2027-03-07&to=2027-03-13')->assertOk();
        $kinds = collect($res->json('data'))->pluck('kind', 'date');

        $this->assertSame(['2027-03-07' => 'workday', '2027-03-08' => 'workday', '2027-03-09' => 'vacation', '2027-03-10' => 'exam', '2027-03-11' => 'workday', '2027-03-12' => 'weekend', '2027-03-13' => 'weekend'], $kinds->all());
        $res->assertJsonPath('meta.summary.total', 7)->assertJsonPath('meta.summary.working', 4)->assertJsonPath('meta.summary.off', 3)
            ->assertJsonPath('meta.summary.open_for_training', 3);
        // Exam day is a working day for staff but closed for training.
        $exam = collect($res->json('data'))->firstWhere('date', '2027-03-10');
        $this->assertTrue($exam['is_working_day']);
        $this->assertFalse($exam['training_allowed']);

        $this->asUser($admin)->getJson('/api/v1/admin/calendar?from=2027-03-07&to=2027-03-13&kind=weekend')->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_a_range_can_be_marked_and_the_calendar_supports_years_ahead(): void
    {
        $admin = $this->makeUser(Role::CENTER_ADMIN);
        $this->asUser($admin)->postJson('/api/v1/admin/calendar/days', ['date' => '2027-06-01', 'end_date' => '2027-06-05', 'type' => 'vacation', 'title_ar' => 'إجازة العيد', 'title_en' => 'Eid'])
            ->assertCreated()->assertJsonCount(5, 'data');
        $this->assertSame(5, CalendarDay::count());

        // Re-marking a date updates it instead of failing on the unique date.
        $this->asUser($admin)->postJson('/api/v1/admin/calendar/days', ['date' => '2027-06-03', 'type' => 'exam', 'title_ar' => 'اختبار', 'title_en' => 'Exam'])->assertCreated();
        $this->assertSame(5, CalendarDay::count());
        $this->assertSame('exam', CalendarDay::whereDate('date', '2027-06-03')->value('type'));

        $this->asUser($admin)->getJson('/api/v1/admin/calendar?from=2027-01-01&to=2029-12-31')->assertUnprocessable()->assertJsonValidationErrors('to');
        $this->asUser($admin)->getJson('/api/v1/admin/calendar?from=2027-01-01&to=2027-12-31')->assertOk()->assertJsonPath('meta.summary.total', 365);
        $this->asUser($admin)->getJson('/api/v1/admin/calendar?from=2027-02-01&to=2027-01-01')->assertUnprocessable();
    }

    public function test_sessions_are_blocked_on_closed_days_until_approved(): void
    {
        $admin = $this->makeUser(Role::CENTER_ADMIN);
        $program = $this->makeProgram();
        $url = "/api/v1/admin/programs/{$program->id}/sessions";

        $this->asUser($admin)->postJson($url, $this->sessionPayload(self::SUNDAY.' 09:00'))->assertCreated();

        foreach ([['2027-03-09', 'vacation'], ['2027-03-10', 'exam'], ['2027-03-11', 'normal']] as [$date, $type]) {
            $this->asUser($admin)->postJson('/api/v1/admin/calendar/days', ['date' => $date, 'type' => $type, 'title_ar' => 'ي', 'title_en' => 'D'])->assertCreated();
            $this->asUser($admin)->postJson($url, $this->sessionPayload("$date 09:00"))
                ->assertUnprocessable()->assertJsonPath('code', 'calendar_closed')->assertJsonPath('details.dates.0.kind', $type);
        }
        // Weekend is closed too.
        $this->asUser($admin)->postJson($url, $this->sessionPayload('2027-03-12 09:00'))->assertUnprocessable()->assertJsonPath('code', 'calendar_closed');
        // A session that runs into a closed day is blocked as well.
        $this->asUser($admin)->postJson($url, $this->sessionPayload('2027-03-08 20:00', ['ends_at' => '2027-03-09 02:00']))->assertUnprocessable();
        $this->assertSame(1, $program->sessions()->count());

        // Approval opens the normal day for training.
        $this->asUser($admin)->postJson('/api/v1/admin/calendar/approvals', ['date' => '2027-03-11', 'reason' => 'Extra cohort'])->assertCreated();
        $this->asUser($admin)->postJson($url, $this->sessionPayload('2027-03-11 09:00'))->assertCreated();
        $day = collect($this->asUser($admin)->getJson('/api/v1/admin/calendar?from=2027-03-11&to=2027-03-11')->json('data'))->first();
        $this->assertTrue($day['training_allowed']);
        $this->assertSame('Extra cohort', $day['approval']['reason']);
        $this->assertSame(1, $day['sessions_count']);

        // Revoking closes it again.
        $this->asUser($admin)->deleteJson('/api/v1/admin/calendar/approvals/2027-03-11')->assertNoContent();
        $this->asUser($admin)->postJson($url, $this->sessionPayload('2027-03-11 13:00'))->assertUnprocessable();
    }

    public function test_only_approvers_can_approve_and_coordinators_cannot_bypass(): void
    {
        $admin = $this->makeUser(Role::CENTER_ADMIN);
        $coordinator = $this->makeUser(Role::COORDINATOR);
        $program = $this->makeProgram();
        $url = "/api/v1/admin/programs/{$program->id}/sessions";
        $this->asUser($admin)->postJson('/api/v1/admin/calendar/days', ['date' => '2027-03-09', 'type' => 'normal', 'title_ar' => 'ع', 'title_en' => 'N'])->assertCreated();

        $this->asUser($coordinator)->getJson('/api/v1/admin/calendar?from=2027-03-07&to=2027-03-13')->assertOk();
        $this->asUser($coordinator)->postJson('/api/v1/admin/calendar/days', ['date' => '2027-03-08', 'type' => 'vacation', 'title_ar' => 'ع', 'title_en' => 'N'])->assertForbidden();
        $this->asUser($coordinator)->postJson('/api/v1/admin/calendar/approvals', ['date' => '2027-03-09', 'reason' => 'please'])->assertForbidden();
        // Sending an approval reason without the permission does not bypass the rule.
        $this->asUser($coordinator)->postJson($url, $this->sessionPayload('2027-03-09 09:00', ['calendar_approval_reason' => 'trust me']))->assertUnprocessable()->assertJsonPath('code', 'calendar_closed');
        $this->assertSame(0, CalendarApproval::count());

        // An approver can approve and schedule in one request.
        $this->asUser($admin)->postJson($url, $this->sessionPayload('2027-03-09 09:00', ['calendar_approval_reason' => 'Ministry request']))->assertCreated();
        $this->assertSame(1, CalendarApproval::count());

        $this->asUser($this->makeUser(Role::TRAINER))->getJson('/api/v1/admin/calendar')->assertForbidden();
    }

    public function test_approvals_only_apply_to_closed_days_and_moving_sessions_is_checked(): void
    {
        $admin = $this->makeUser(Role::CENTER_ADMIN);
        $program = $this->makeProgram();
        $url = "/api/v1/admin/programs/{$program->id}/sessions";

        $this->asUser($admin)->postJson('/api/v1/admin/calendar/approvals', ['date' => self::SUNDAY, 'reason' => 'not needed'])->assertUnprocessable()->assertJsonPath('code', 'calendar_not_closed');
        $this->asUser($admin)->postJson('/api/v1/admin/calendar/approvals', ['date' => self::SUNDAY])->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->asUser($admin)->postJson('/api/v1/admin/calendar/approvals', ['date' => '2027-03-12', 'end_date' => '2027-03-13', 'reason' => 'Weekend bootcamp'])->assertCreated()->assertJsonPath('meta.approved', 2);
        $this->asUser($admin)->postJson('/api/v1/admin/calendar/approvals', ['date' => '2027-03-12', 'reason' => 'again'])->assertUnprocessable();
        $this->asUser($admin)->deleteJson('/api/v1/admin/calendar/approvals/2027-01-01')->assertNotFound();

        $id = $this->asUser($admin)->postJson($url, $this->sessionPayload(self::SUNDAY.' 09:00'))->assertCreated()->json('data.id');
        $this->asUser($admin)->putJson("/api/v1/admin/sessions/$id", $this->sessionPayload('2027-03-14 09:00'))->assertOk();
        $this->asUser($admin)->postJson('/api/v1/admin/calendar/days', ['date' => '2027-03-16', 'type' => 'vacation', 'title_ar' => 'ع', 'title_en' => 'N'])->assertCreated();
        $this->asUser($admin)->putJson("/api/v1/admin/sessions/$id", $this->sessionPayload('2027-03-16 09:00'))->assertUnprocessable()->assertJsonPath('code', 'calendar_closed');
        // Renaming, or cancelling, does not re-check the calendar.
        $this->asUser($admin)->putJson("/api/v1/admin/sessions/$id", ['title_en' => 'Renamed'])->assertOk();
    }

    public function test_marking_a_day_reports_sessions_already_scheduled_on_it(): void
    {
        $admin = $this->makeUser(Role::CENTER_ADMIN);
        $program = $this->makeProgram();
        $session = $this->makeSession($program, CarbonImmutable::parse('2027-03-08 09:00'));

        $this->asUser($admin)->postJson('/api/v1/admin/calendar/days', ['date' => '2027-03-08', 'type' => 'exam', 'title_ar' => 'ع', 'title_en' => 'E'])
            ->assertCreated()->assertJsonPath('meta.conflicting_sessions.0.id', $session->id);
        $this->asUser($admin)->postJson('/api/v1/admin/calendar/days', ['date' => '2027-03-08', 'type' => 'bogus', 'title_ar' => 'ع', 'title_en' => 'E'])->assertUnprocessable()->assertJsonValidationErrors('type');

        $day = CalendarDay::first();
        $this->asUser($admin)->putJson("/api/v1/admin/calendar/days/{$day->id}", ['title_en' => 'Exams week'])->assertOk()->assertJsonPath('data.title_en', 'Exams week');
        $this->asUser($admin)->deleteJson("/api/v1/admin/calendar/days/{$day->id}")->assertNoContent();
        $this->assertSame(0, CalendarDay::count());
    }
}
