<?php

namespace Tests\Feature;

use App\Models\Program;
use App\Models\ProgramSession;
use App\Models\Role;
use App\Models\TrainingRoom;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class LobbyScreenTest extends TestCase
{
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->admin = $this->makeUser(Role::SUPER_ADMIN);
    }

    private function screen(string $token): TestResponse
    {
        return $this->getJson("/api/v1/public/lobby-screen/{$token}");
    }

    public function test_the_screen_is_reached_only_by_its_secret_address_and_can_be_switched_off(): void
    {
        $token = $this->asUser($this->admin)->getJson('/api/v1/admin/settings/lobby-screen')->assertOk()->assertJsonPath('data.size.w', 1080)->assertJsonPath('data.size.h', 1920)->json('data.token');
        $this->assertSame(40, strlen($token));

        $this->screen($token)->assertOk()->assertJsonStructure(['data' => ['date', 'settings', 'center', 'programs', 'pages', 'slides']]);
        $this->assertStringNotContainsString($token, $this->screen($token)->getContent(), 'the address is never repeated in the content');
        $this->screen('wrong-token')->assertNotFound();

        $new = $this->asUser($this->admin)->postJson('/api/v1/admin/settings/lobby-screen/token')->assertOk()->json('data.token');
        $this->assertNotSame($token, $new);
        $this->screen($token)->assertNotFound();

        $this->asUser($this->admin)->putJson('/api/v1/admin/settings/lobby-screen', ['enabled' => false])->assertOk();
        $this->screen($new)->assertNotFound();
        $this->asUser($this->makeEmployee()->user)->getJson('/api/v1/admin/settings/lobby-screen')->assertForbidden();
    }

    public function test_the_first_slides_list_todays_programs_with_room_floor_and_coordinator(): void
    {
        $coordinator = $this->makeUser(Role::COORDINATOR, ['name' => 'Rashid', 'name_ar' => 'راشد الكبيسي']);
        $room = TrainingRoom::create(['code' => '204', 'name_ar' => 'قاعة الابتكار', 'name_en' => 'Innovation Hall', 'floor' => '2', 'building' => 'A', 'capacity' => 20]);
        $program = $this->makeProgram(['status' => Program::STATUS_IN_PROGRESS, 'coordinator_id' => $coordinator->id, 'title_ar' => 'إدارة الصف', 'title_en' => 'Classroom']);
        $today = now(config('app.timezone'))->startOfDay();
        $program->sessions()->create(['title_ar' => 'اللقاء', 'title_en' => 'Session', 'starts_at' => $today->copy()->setTime(8, 0), 'ends_at' => $today->copy()->setTime(13, 0), 'training_room_id' => $room->id, 'mode' => 'in_person']);
        // Other days, cancelled sessions and unpublished programs are not shown.
        $program->sessions()->create(['title_ar' => 'غداً', 'title_en' => 'Tomorrow', 'starts_at' => $today->copy()->addDay()->setTime(8, 0), 'ends_at' => $today->copy()->addDay()->setTime(13, 0)]);
        $draft = $this->makeProgram(['status' => Program::STATUS_DRAFT]);
        $draft->sessions()->create(['title_ar' => 'x', 'title_en' => 'x', 'starts_at' => $today->copy()->setTime(9, 0), 'ends_at' => $today->copy()->setTime(12, 0)]);
        ProgramSession::create(['program_id' => $program->id, 'title_ar' => 'ملغاة', 'title_en' => 'Cancelled', 'starts_at' => $today->copy()->setTime(10, 0), 'ends_at' => $today->copy()->setTime(11, 0), 'status' => 'cancelled']);

        $token = $this->asUser($this->admin)->getJson('/api/v1/admin/settings/lobby-screen')->json('data.token');
        $rows = $this->screen($token)->assertOk()->json('data.programs');

        $this->assertCount(1, $rows);
        $this->assertSame(['إدارة الصف', '204', '2', 'A', 'راشد الكبيسي'], [$rows[0]['program']['title_ar'], $rows[0]['room']['code'], $rows[0]['room']['floor'], $rows[0]['room']['building'], $rows[0]['coordinator']['name_ar']]);

        // The coordinator can be set on the program itself.
        $this->asUser($this->admin)->putJson("/api/v1/admin/programs/{$program->id}", ['coordinator_id' => $this->admin->id])->assertOk();
        $this->assertSame($this->admin->id, $program->fresh()->coordinator_id);
        $this->asUser($this->admin)->getJson("/api/v1/admin/programs/{$program->id}")->assertJsonPath('data.coordinator.id', $this->admin->id);
    }

    public function test_images_become_slides_with_their_own_time_and_transition_in_the_order_chosen(): void
    {
        $up = fn (string $name) => $this->asUser($this->admin)->post('/api/v1/admin/settings/lobby-screen/slides', ['image' => UploadedFile::fake()->image($name, 1080, 1920), 'title' => $name], ['Accept' => 'application/json']);
        $a = $up('welcome.jpg')->assertCreated()->json('data.slides.0.id');
        $b = $up('news.png')->assertCreated()->json('data.slides.1.id');
        $this->asUser($this->admin)->post('/api/v1/admin/settings/lobby-screen/slides', ['image' => UploadedFile::fake()->create('x.pdf', 10, 'application/pdf')], ['Accept' => 'application/json'])->assertUnprocessable();

        $this->asUser($this->admin)->putJson('/api/v1/admin/settings/lobby-screen', ['slide_seconds' => 15, 'transition' => 'slide', 'transition_ms' => 1200, 'programs_seconds' => 25])->assertOk();
        $this->asUser($this->admin)->putJson("/api/v1/admin/settings/lobby-screen/slides/{$b}", ['seconds' => 30, 'transition' => 'zoom'])->assertOk();
        $this->asUser($this->admin)->putJson('/api/v1/admin/settings/lobby-screen/slides/order', ['ids' => [$b, $a]])->assertOk();

        $token = $this->asUser($this->admin)->getJson('/api/v1/admin/settings/lobby-screen')->json('data.token');
        $slides = $this->screen($token)->json('data.slides');
        $this->assertSame([$b, $a], array_column($slides, 'id'));
        $this->assertSame([30, 15], array_column($slides, 'seconds'), 'own time, else the default');
        $this->assertSame(['zoom', 'slide'], array_column($slides, 'transition'), 'own transition, else the default');
        $this->assertSame(1200, $this->screen($token)->json('data.settings.transition_ms'));

        // A slide can be hidden, limited to dates, or removed.
        $this->asUser($this->admin)->putJson("/api/v1/admin/settings/lobby-screen/slides/{$a}", ['enabled' => false])->assertOk();
        $this->asUser($this->admin)->putJson("/api/v1/admin/settings/lobby-screen/slides/{$b}", ['from' => now()->addDays(2)->toDateString(), 'to' => now()->addDays(5)->toDateString()])->assertOk();
        $this->assertSame([], $this->screen($token)->json('data.slides'));
        $this->asUser($this->admin)->deleteJson("/api/v1/admin/settings/lobby-screen/slides/{$a}")->assertOk()->assertJsonCount(1, 'data.slides');
        $this->asUser($this->admin)->deleteJson('/api/v1/admin/settings/lobby-screen/slides/nope')->assertNotFound();
        $this->asUser($this->admin)->putJson('/api/v1/admin/settings/lobby-screen', ['transition' => 'wobble'])->assertUnprocessable();
    }
}
