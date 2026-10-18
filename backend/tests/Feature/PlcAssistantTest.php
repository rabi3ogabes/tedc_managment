<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\EvaluationAssignment;
use App\Models\PlcWorkshop;
use App\Models\ProgramSession;
use App\Models\Role;
use App\Models\Trainer;
use App\Models\TrainingRoom;
use App\Services\Ai\AiModelSettings;
use App\Services\AttendanceService;
use App\Services\FeatureSettings;
use Carbon\Carbon;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** PLC Phase 8: the checklist and tracker reflect the records, every item is advisory, feedback themes need the opt-in, a permitted model and enough text, and leave with names hidden. */
class PlcAssistantTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
        $admin = $this->makeUser(Role::SUPER_ADMIN);
        app(FeatureSettings::class)->set('plc', true, 'test', $admin);
        app(FeatureSettings::class)->set('ai', true, 'test', $admin);
        Cache::flush();
    }

    /** The n-th training day from today at 09:00 (Fridays and Saturdays skipped). Counted one training day at a time, so two offsets never meet on one date, whatever today's weekday. */
    private function day(int $offset): Carbon
    {
        $d = today()->setTime(9, 0);
        for ($i = 0; $i < $offset; $i++) {
            $d->addDay();
            while (in_array($d->dayOfWeek, [5, 6], true)) {
                $d->addDay();
            }
        }

        return $d;
    }

    private function model(string $residency): void
    {
        $settings = app(AiModelSettings::class);
        $c = $settings->saveConnection(['name' => 'Test', 'driver' => 'custom', 'base_url' => 'https://ai.test/v1', 'api_key' => 'sk-test', 'data_residency' => $residency]);
        $m = $settings->saveModel(['connection_id' => $c['id'], 'model' => 'gpt-test', 'tasks' => ['text']]);
        $settings->assign(['text' => $m['id']]);
        Cache::flush();
    }

    public function test_the_checklist_tracks_the_workshop_and_themes_are_guarded(): void
    {
        $admin = $this->makeUser(Role::SUPER_ADMIN);
        $officer = $this->makeUser(Role::COORDINATOR);
        $head = $this->makeUser(Role::TRAINING_HEAD);
        $host = $this->makeSchool(['gender' => 'girls']);
        [$a, $b, $c] = [$this->makeEmployee(['school_id' => $host->id]), $this->makeEmployee(['school_id' => $host->id]), $this->makeEmployee(['school_id' => $host->id])];
        $room = TrainingRoom::create(['code' => 'R9', 'name_ar' => 'قاعة', 'name_en' => 'Room', 'capacity' => 20, 'status' => 'active']);
        $trainer = Trainer::create(['name_ar' => 'مدرب', 'name_en' => 'Trainer', 'email' => 'tr'.uniqid().'@test.qa', 'status' => 'active', 'specializations' => ['assessment'], 'rating' => 4, 'user_id' => $this->makeUser(Role::TRAINER)->id]);
        $d1 = $this->day(10);
        $id = $this->asUser($officer)->postJson('/api/v1/admin/plc/workshops', ['title_ar' => 'ورشة المساعد', 'host_school_id' => $host->id, 'start_date' => $d1->toDateString(), 'end_date' => $d1->toDateString(), 'capacity' => 6, 'delivery_mode' => 'in_person', 'source' => 'centre', 'min_attendance_percent' => 50])->assertCreated()->json('data.id');

        // A fresh draft: source and host are done, the basics warn about the missing hours, the rest is to do.
        $this->asUser($this->makeEmployee()->user)->getJson("/api/v1/admin/plc/workshops/$id/assistant")->assertForbidden();
        $c0 = $this->asUser($officer)->getJson("/api/v1/admin/plc/workshops/$id/assistant")->assertOk()->json('data');
        $this->assertSame(['source', 'basics', 'host', 'schools', 'trainees', 'trainers', 'sessions', 'approval', 'scheduled', 'attendance', 'evaluation', 'closed'], array_column($c0['items'], 'key'));
        $this->assertSame(['done', 'warn', 'done', 'warn', 'todo'], array_slice(array_column($c0['items'], 'status'), 0, 5)); // no total hours yet; no participating schools yet
        $this->assertSame('trainees', $c0['next']['key']); // the first thing not started; the warnings come after
        $this->assertSame(2, $c0['done']);
        $this->assertSame(0, $c0['decisions']['exceptions']);
        $this->assertFalse($c0['themes']['opt_in']);

        // People, a trainer and a session: the tracker moves; the trainer item warns while the proposal awaits confirmation.
        $this->asUser($officer)->postJson("/api/v1/admin/plc/workshops/$id/nominations", ['employee_ids' => [$a->id, $b->id, $c->id]])->assertOk();
        foreach ($this->asUser($officer)->getJson("/api/v1/admin/plc/workshops/$id/nominations")->json('data.rows') as $row) {
            $this->asUser($officer)->postJson("/api/v1/admin/plc/workshops/$id/nominations/{$row['id']}", ['to' => 'approved'])->assertOk();
        }
        $this->asUser($officer)->postJson("/api/v1/admin/plc/workshops/$id/trainers", ['trainer_id' => $trainer->id, 'role' => 'lead'])->assertSuccessful();
        $c1 = $this->asUser($officer)->getJson("/api/v1/admin/plc/workshops/$id/assistant")->assertOk()->json('data');
        $this->assertSame('warn', collect($c1['items'])->firstWhere('key', 'trainers')['status']);
        $this->assertSame('done', collect($c1['items'])->firstWhere('key', 'trainees')['status']);
        $this->assertSame(3, $c1['explanations']['trainees']['seated']);
        foreach ($this->asUser($officer)->getJson("/api/v1/admin/plc/workshops/$id/trainers")->json('data') as $row) {
            $this->asUser($officer)->postJson("/api/v1/admin/plc/workshops/$id/trainers/{$row['id']}/confirm")->assertSuccessful();
        }
        $s1 = $this->asUser($officer)->postJson("/api/v1/admin/plc/workshops/$id/sessions", ['starts_at' => $d1->toIso8601String(), 'ends_at' => $d1->copy()->addHours(3)->toIso8601String(), 'mode' => 'in_person', 'training_room_id' => $room->id, 'trainer_ids' => [$trainer->id]])->assertCreated()->json('data.id');
        $this->asUser($officer)->postJson("/api/v1/admin/plc/workshops/$id/submit")->assertOk();
        $this->asUser($head)->postJson("/api/v1/admin/plc/workshops/$id/approve", ['note' => 'موافق'])->assertOk();
        $this->asUser($officer)->postJson("/api/v1/admin/plc/workshops/$id/schedule")->assertOk();
        $c2 = $this->asUser($officer)->getJson("/api/v1/admin/plc/workshops/$id/assistant")->assertOk()->json('data');
        $this->assertSame('attendance', $c2['next']['key']);
        $this->assertSame(['done', 'done'], [collect($c2['items'])->firstWhere('key', 'approval')['status'], collect($c2['items'])->firstWhere('key', 'scheduled')['status']]);
        $this->assertSame(58, $c2['progress']); // 7 of 12 done; the hours and the participating schools stay warnings

        // The session happens and the workshop completes; the forms go out; three people write open answers.
        $this->travelTo($d1->copy()->addMinutes(5));
        $this->artisan('tedc:plc-tick')->assertSuccessful();
        $code = app(AttendanceService::class)->currentQr(ProgramSession::find($s1))['payload'];
        foreach ([$a, $b, $c] as $e) {
            $this->asUser($e->user)->postJson('/api/v1/me/attendance/scan', ['payload' => $code])->assertOk();
        }
        $this->travelTo($d1->copy()->addHours(4));
        $this->artisan('tedc:plc-tick')->assertSuccessful();
        $this->assertSame('awaiting_evaluation', PlcWorkshop::find($id)->workflow_state);
        $texts = ['الوقت قصير ولا يكفي للتطبيق', 'المدربة ممتازة لكن القاعة حارة', 'نحتاج أمثلة عملية أكثر من مدارسنا'];
        foreach ([$a, $b, $c] as $i => $e) {
            $aid = EvaluationAssignment::where('program_id', $id)->where('rule_key', 'plc.workshop')->where('respondent_user_id', $e->user_id)->value('id');
            $this->asUser($e->user)->postJson("/api/v1/me/evaluations/$aid", ['answers' => ['objectives' => 5, 'content' => 4, 'methods' => 5, 'organisation' => 3, 'apply' => 4, 'overall' => 4, 'strengths' => $texts[$i].' — '.$e->user->name]])->assertSuccessful();
        }
        $c3 = $this->asUser($officer)->getJson("/api/v1/admin/plc/workshops/$id/assistant")->assertOk()->json('data');
        $this->assertSame(3, $c3['themes']['answers']);
        $this->assertSame(3, $c3['impact']['completed']);
        $this->assertSame(3, $c3['impact']['evaluation']['submitted']);

        // Themes: refused before the Ministry opts in; then unavailable without a permitted model; an external model may not see the text.
        $this->asUser($officer)->postJson("/api/v1/admin/plc/workshops/$id/assistant/themes")->assertStatus(422)->assertJsonPath('code', 'plc_ai_opt_out');
        $this->asUser($admin)->putJson('/api/v1/admin/plc/settings', ['ai_feedback_opt_in' => true])->assertOk()->assertJsonPath('data.ai_feedback_opt_in', true);
        $this->asUser($officer)->postJson("/api/v1/admin/plc/workshops/$id/assistant/themes")->assertStatus(422)->assertJsonPath('code', 'plc_ai_unavailable');
        $this->model('external');
        $this->asUser($officer)->postJson("/api/v1/admin/plc/workshops/$id/assistant/themes")->assertStatus(422)->assertJsonPath('code', 'plc_ai_unavailable');

        // A model inside Qatar: the answers leave with the respondents' names hidden; the result is cached and audited.
        $this->model('qatar');
        Http::fake(['ai.test/*' => Http::response(['choices' => [['message' => ['content' => json_encode([
            'summary' => 'يطلب المشاركون وقتاً أطول وأمثلة عملية.', 'sentiment' => ['positive' => 1, 'neutral' => 1, 'negative' => 1],
            'themes' => [['title' => 'ضيق الوقت', 'count' => 1, 'quotes' => ['الوقت قصير ولا يكفي للتطبيق']], ['title' => 'أمثلة عملية', 'count' => 1, 'quotes' => []]],
            'recommendations' => ['إطالة الورشة إلى يومين'],
        ], JSON_UNESCAPED_UNICODE)]]]])]);
        $r = $this->asUser($officer)->postJson("/api/v1/admin/plc/workshops/$id/assistant/themes")->assertOk()->json('data');
        $this->assertSame('ضيق الوقت', $r['themes'][0]['title']);
        $this->assertTrue($r['advisory']);
        $this->assertSame(3, $r['answers_count']);
        Http::assertSent(function ($request) use ($a) {
            $body = json_encode($request->data(), JSON_UNESCAPED_UNICODE);

            return str_contains($body, 'الوقت قصير') && ! str_contains($body, $a->user->name);
        });
        Http::assertSentCount(1);
        $this->asUser($officer)->postJson("/api/v1/admin/plc/workshops/$id/assistant/themes")->assertOk()->assertJsonPath('data.themes.0.title', 'ضيق الوقت');
        Http::assertSentCount(1); // served from the cache
        $this->assertTrue(AuditLog::where('action', 'plc_feedback_themes')->where('auditable_id', $id)->exists());
        $this->assertNotNull($this->asUser($officer)->getJson("/api/v1/admin/plc/workshops/$id/assistant")->json('data.themes.result'));

        // The decisions register across the scope, filtered by kind: the trainer form becomes mandatory and nobody answered it, so the centre closes with an exception.
        $this->asUser($admin)->putJson('/api/v1/admin/plc/settings', ['evaluation_rules' => ['trainer' => ['enabled' => true, 'mandatory' => true]]])->assertOk();
        $this->asUser($officer)->postJson("/api/v1/admin/plc/workshops/$id/evaluation/close")->assertStatus(422)->assertJsonPath('code', 'plc_evaluation_incomplete');
        $this->asUser($head)->postJson("/api/v1/admin/plc/workshops/$id/evaluation/close", ['exception' => true, 'reason' => 'نهاية الفصل'])->assertOk();
        $reg = $this->asUser($officer)->getJson('/api/v1/admin/plc/reports/decisions?kind=exception')->assertOk()->json('data');
        $this->assertSame(1, $reg['by_kind']['exception']);
        $this->assertSame(['evaluation_waived', 'نهاية الفصل'], [$reg['rows'][0]['decision'], $reg['rows'][0]['reason']]);
        $this->assertSame($id, $reg['rows'][0]['workshop_id']);
        $this->assertGreaterThan(1, count($this->asUser($officer)->getJson('/api/v1/admin/plc/reports/decisions')->json('data.rows')));
        $this->assertSame(1, $this->asUser($officer)->getJson('/api/v1/admin/plc/reports?state=closed')->json('data.workshops.total'));
    }
}
