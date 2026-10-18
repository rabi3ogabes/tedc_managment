<?php

namespace Tests\Feature;

use App\Models\PlcCommunity;
use App\Models\ProgramSession;
use App\Models\Registration;
use App\Models\Role;
use App\Models\Trainer;
use App\Models\TrainingRoom;
use App\Services\AttendanceService;
use App\Services\FeatureSettings;
use Carbon\Carbon;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/** PLC Phase 7: the figures reconcile with the database and with the dashboard, respect the scope and the filters, and export. */
class PlcReportsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
        app(FeatureSettings::class)->set('plc', true, 'test', $this->makeUser(Role::SUPER_ADMIN));
        Cache::flush();
    }

    /** The n-th training day from today at 09:00 (weekends not counted), so two offsets never land on the same date. */
    private function day(int $offset = 10): Carbon
    {
        $d = today()->setTime(9, 0);
        for ($i = 0; $i < $offset; $i++) {
            do {
                $d->addDay();
            } while (in_array($d->dayOfWeek, [5, 6], true));
        }

        return $d;
    }

    public function test_figures_reconcile_with_the_database_the_dashboard_the_scope_and_the_filters(): void
    {
        $officer = $this->makeUser(Role::COORDINATOR);
        $head = $this->makeUser(Role::TRAINING_HEAD);
        $host = $this->makeSchool(['gender' => 'girls', 'region' => 'الدوحة']);
        $other = $this->makeSchool(['gender' => 'girls', 'region' => 'الريان']);
        [$a, $b, $c] = [$this->makeEmployee(['school_id' => $host->id]), $this->makeEmployee(['school_id' => $host->id]), $this->makeEmployee(['school_id' => $host->id])];
        $deputyElsewhere = $this->makeUser(Role::ACADEMIC_DEPUTY);
        $this->makeEmployee(['school_id' => $other->id], $deputyElsewhere);
        $room = TrainingRoom::create(['code' => 'R8', 'name_ar' => 'قاعة', 'name_en' => 'Room', 'capacity' => 20, 'status' => 'active']);
        $trainer = Trainer::create(['name_ar' => 'مدرب', 'name_en' => 'Trainer', 'email' => 'tr'.uniqid().'@test.qa', 'status' => 'active', 'specializations' => ['assessment'], 'rating' => 4, 'user_id' => $this->makeUser(Role::TRAINER)->id]);
        $community = PlcCommunity::create(['code' => 'C-'.uniqid(), 'title_ar' => 'مجتمع التقويم', 'status' => 'active', 'lead_user_id' => $head->id, 'host_school_id' => $host->id, 'created_by' => $officer->id]);
        $d1 = $this->day(10);
        $id = $this->asUser($officer)->postJson('/api/v1/admin/plc/workshops', ['title_ar' => 'ورشة التقرير', 'host_school_id' => $host->id, 'start_date' => $d1->toDateString(), 'end_date' => $d1->toDateString(), 'capacity' => 12, 'delivery_mode' => 'in_person', 'source' => 'rased', 'source_ref' => 'R-9', 'community_id' => $community->id, 'min_attendance_percent' => 50])->assertCreated()->json('data.id');
        $this->asUser($officer)->postJson('/api/v1/admin/plc/workshops', ['title_ar' => 'مسودة', 'host_school_id' => $host->id, 'start_date' => $d1->copy()->addMonthsNoOverflow(2)->toDateString(), 'capacity' => 8, 'delivery_mode' => 'online', 'source' => 'centre'])->assertCreated();
        $this->asUser($officer)->postJson("/api/v1/admin/plc/workshops/$id/nominations", ['employee_ids' => [$a->id, $b->id, $c->id]])->assertOk();
        foreach ($this->asUser($officer)->getJson("/api/v1/admin/plc/workshops/$id/nominations")->json('data.rows') as $row) {
            $this->asUser($officer)->postJson("/api/v1/admin/plc/workshops/$id/nominations/{$row['id']}", ['to' => 'approved'])->assertOk();
        }
        $this->asUser($officer)->postJson("/api/v1/admin/plc/workshops/$id/trainers", ['trainer_id' => $trainer->id, 'role' => 'lead'])->assertSuccessful();
        foreach ($this->asUser($officer)->getJson("/api/v1/admin/plc/workshops/$id/trainers")->json('data') as $row) {
            $this->asUser($officer)->postJson("/api/v1/admin/plc/workshops/$id/trainers/{$row['id']}/confirm")->assertSuccessful();
        }
        $s1 = $this->asUser($officer)->postJson("/api/v1/admin/plc/workshops/$id/sessions", ['starts_at' => $d1->toIso8601String(), 'ends_at' => $d1->copy()->addHours(3)->toIso8601String(), 'mode' => 'in_person', 'training_room_id' => $room->id, 'trainer_ids' => [$trainer->id]])->assertCreated()->json('data.id');
        $this->asUser($officer)->postJson("/api/v1/admin/plc/workshops/$id/submit")->assertOk();
        $this->asUser($head)->postJson("/api/v1/admin/plc/workshops/$id/approve", ['note' => 'موافق'])->assertOk();
        $this->asUser($officer)->postJson("/api/v1/admin/plc/workshops/$id/schedule")->assertOk();

        // The session happens: two of three attend; the clock completes the workshop and sends the forms.
        $this->travelTo($d1->copy()->addMinutes(5));
        $this->artisan('tedc:plc-tick')->assertSuccessful();
        $code = app(AttendanceService::class)->currentQr(ProgramSession::find($s1))['payload'];
        $this->asUser($a->user)->postJson('/api/v1/me/attendance/scan', ['payload' => $code])->assertOk();
        $this->asUser($b->user)->postJson('/api/v1/me/attendance/scan', ['payload' => $code])->assertOk();
        $this->travelTo($d1->copy()->addHours(4));
        $this->artisan('tedc:plc-tick')->assertSuccessful();

        // The figures match the database.
        $this->asUser($a->user)->getJson('/api/v1/admin/plc/reports')->assertForbidden();
        $r = $this->asUser($officer)->getJson('/api/v1/admin/plc/reports')->assertOk()->json('data');
        $this->assertSame([2, 1], [$r['workshops']['total'], $r['workshops']['held']]);
        $this->assertSame(['awaiting_evaluation' => 1, 'draft' => 1], $r['workshops']['by_state']);
        $this->assertSame(['centre' => 1, 'rased' => 1], $r['workshops']['by_source']);
        $this->assertSame([1, 1, 3.0], [$r['sessions']['total'], $r['sessions']['held'], (float) $r['sessions']['hours']]);
        $this->assertSame([20, 3, 2, 3, 1, 1], [$r['people']['capacity'], $r['people']['seated'], $r['people']['completed'], $r['people']['unique'], $r['people']['schools'], $r['people']['communities']]);
        $expectedRate = round((float) Registration::where('program_id', $id)->avg('attendance_percent'), 1);
        $this->assertSame($expectedRate, (float) $r['attendance']['rate']);
        $this->assertSame([3, 2], [$r['attendance']['people'], $r['attendance']['meeting']]);
        $this->assertSame([3, 0], [$r['evaluation']['workshop']['assigned'], $r['evaluation']['workshop']['submitted']]);
        $this->assertSame(2, $r['impact']['trainee']['total']); // the two who completed, +45 days
        $this->assertCount(2, $r['trend']);
        $this->assertSame($host->id, $r['by_school'][0]['school_id']);
        $this->assertSame([2, 3], [$r['by_school'][0]['workshops'], $r['by_school'][0]['people']]);
        $this->assertSame($community->id, $r['by_community'][0]['community_id']);
        $this->assertSame(3, $r['by_community'][0]['people']);
        $this->assertSame($id, $r['top_attendance'][0]['id']);

        // The same figures on the dashboard.
        $dash = $this->asUser($officer)->getJson('/api/v1/admin/plc/dashboard')->assertOk()->json('data');
        $this->assertSame($r['workshops']['total'], $dash['workshops']['total']);
        $this->assertSame($r['workshops']['by_state'], $dash['workshops']['by_state']);
        $this->assertSame($r['attendance'], $dash['kpis']['attendance']);
        $this->assertSame($r['evaluation']['workshop'], $dash['kpis']['evaluation']['workshop']);

        // Filters narrow, the scope isolates, the options list what is in scope.
        $this->assertSame(1, $this->asUser($officer)->getJson('/api/v1/admin/plc/reports?source=centre')->json('data.workshops.total'));
        $this->assertSame(0, $this->asUser($officer)->getJson('/api/v1/admin/plc/reports?source=entity')->json('data.workshops.total'));
        $this->assertSame(1, $this->asUser($officer)->getJson('/api/v1/admin/plc/reports?community_id='.$community->id)->json('data.workshops.total'));
        $this->assertSame(1, $this->asUser($officer)->getJson('/api/v1/admin/plc/reports?state=draft&mode=online')->json('data.workshops.total'));
        $this->assertSame(2, $this->asUser($officer)->getJson('/api/v1/admin/plc/reports?school_id='.$host->id.'&region='.urlencode('الدوحة'))->json('data.workshops.total'));
        $this->assertSame(0, $this->asUser($officer)->getJson('/api/v1/admin/plc/reports?from='.$d1->copy()->addDays(40)->toDateString().'&to='.$d1->copy()->addDays(45)->toDateString())->json('data.workshops.total'));
        $this->asUser($officer)->getJson('/api/v1/admin/plc/reports?state=bogus')->assertStatus(422);
        $this->assertSame(0, $this->asUser($deputyElsewhere)->getJson('/api/v1/admin/plc/reports')->assertOk()->json('data.workshops.total'));
        $opts = $this->asUser($officer)->getJson('/api/v1/admin/plc/reports/options')->assertOk()->json('data');
        $this->assertSame([$host->id], array_column($opts['schools'], 'id'));
        $this->assertSame(['الدوحة'], $opts['regions']);

        // Exports carry the same figures.
        $csv = $this->asUser($officer)->get('/api/v1/admin/plc/reports/export?format=csv')->assertOk()->getContent();
        $this->assertStringContainsString($host->name_ar, $csv);
        $this->assertStringContainsString('مجتمع التقويم', $csv);
        $this->assertSame('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $this->asUser($officer)->get('/api/v1/admin/plc/reports/export?format=xlsx&lang=en')->assertOk()->headers->get('Content-Type'));
        $this->assertSame('application/pdf', $this->asUser($officer)->get('/api/v1/admin/plc/reports/export?format=pdf')->assertOk()->headers->get('Content-Type'));
    }
}
