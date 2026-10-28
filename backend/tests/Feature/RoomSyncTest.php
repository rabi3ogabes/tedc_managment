<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\RoomBooking;
use App\Models\TrainingRoom;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/** «مزامنة القاعات من ملف» (docs/saber/06-rooms-sync.md). The rooms mirror the centre's (room names only); workbooks are made here. */
class RoomSyncTest extends TestCase
{
    /** @param callable(Worksheet): void $fill */
    private function workbook(callable $fill, string $title = 'تقويم القاعات التدريبية'): UploadedFile
    {
        $book = new Spreadsheet;
        $fill($book->getActiveSheet()->setTitle($title));
        $path = tempnam(sys_get_temp_dir(), 'wb').'.xlsx';
        (new Xlsx($book))->save($path);

        return new UploadedFile($path, 'book.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }

    protected function setUp(): void
    {
        parent::setUp();
        TrainingRoom::query()->delete();   // start from the centre's rooms only, not the reference data's
    }

    private function room(string $name, int $capacity, array $extra = []): TrainingRoom
    {
        return TrainingRoom::create($extra + ['name_ar' => $name, 'name_en' => $name, 'capacity' => $capacity, 'layout' => 'classroom', 'layouts' => ['classroom' => $capacity], 'status' => 'active', 'room_type' => 'training']);
    }

    /** The calendar's header: priority row 2, room names row 3, capacities row 4, rooms from column H. */
    private function calendar(array $rooms): UploadedFile
    {
        return $this->workbook(function ($s) use ($rooms) {
            $col = 8;
            foreach ($rooms as [$tag, $name, $cap]) {
                $c = Coordinate::stringFromColumnIndex($col++);
                $s->setCellValue("{$c}2", $tag)->setCellValue("{$c}3", $name)->setCellValue("{$c}4", "{$cap} متدرب");
            }
            $s->setCellValue('D5', 46000);
        });
    }

    public function test_the_rooms_calendar_brings_the_rooms_in_line_adding_changing_and_retiring_with_history_kept(): void
    {
        $admin = $this->makeUser(Role::SUPER_ADMIN);
        $one = $this->room('القاعة 1', 20);
        $three = $this->room('قاعة 3', 25);
        $meeting = $this->room('الابتكار - قاعة إجتماعات', 25, ['room_type' => 'meeting']);
        $unused = $this->room('قاعة مؤقتة', 10);
        $booked = $this->room('قاعة الاختبار', 10);
        RoomBooking::create(['room_id' => $booked->id, 'purpose' => 'meeting', 'title' => 'اجتماع', 'starts_at' => now()->addDays(3)->setTime(9, 0), 'ends_at' => now()->addDays(3)->setTime(10, 0), 'status' => 'confirmed']);
        $file = $this->calendar([['رجال', 'القاعة 1', 25], ['نساء', 'القاعة 3', 25], ['الكل', 'المسرح', 60]]);

        $plan = $this->asUser($admin)->post('/api/v1/admin/rooms/sync/preview', ['file' => $file], ['Accept' => 'application/json'])->assertOk()->json('data');

        $this->assertSame('grid', $plan['source']);
        $this->assertSame(['المسرح'], array_column($plan['add'], 'name'));
        $this->assertSame(60, $plan['add'][0]['capacity']);
        $updates = collect($plan['update'])->keyBy('id');
        $this->assertSame(['from' => 20, 'to' => 25], $updates[$one->id]['changes']['capacity']);
        $this->assertSame('رجال', $updates[$one->id]['changes']['tag']['to']);
        $this->assertSame(['from' => 'قاعة 3', 'to' => 'القاعة 3'], $updates[$three->id]['changes']['name'], '«قاعة 3» and «القاعة 3» are the same room');
        $this->assertSame([$meeting->id], array_column($plan['kept'], 'id'), 'meeting rooms are not in the training calendar and are left alone');
        $remove = collect($plan['remove'])->keyBy('id');
        $this->assertSame('delete', $remove[$unused->id]['action']);
        $this->assertSame('deactivate', $remove[$booked->id]['action']);
        $this->assertTrue($remove[$booked->id]['upcoming']);

        $this->asUser($admin)->postJson('/api/v1/admin/rooms/sync/apply', [
            'add' => $plan['add'],
            'update' => collect($plan['update'])->map(fn ($u) => ['id' => $u['id'], 'changes' => collect($u['changes'])->map(fn ($c) => $c['to'])->all()])->values()->all(),
            'remove' => [$unused->id, $booked->id],
        ])->assertOk()->assertJsonPath('data.added', 1)->assertJsonPath('data.updated', 2)->assertJsonPath('data.deleted', 1)->assertJsonPath('data.deactivated', 1);

        $this->assertSame([25, 'رجال', 25], [$one->fresh()->capacity, $one->fresh()->tag, $one->fresh()->layouts['classroom']]);
        $this->assertSame(['القاعة 3', 'القاعة 3'], [$three->fresh()->name_ar, $three->fresh()->name_en], 'an English name that copied the Arabic one follows it');
        $this->assertNull(TrainingRoom::find($unused->id));
        $this->assertSame('inactive', $booked->fresh()->status);
        $this->assertSame(1, RoomBooking::where('room_id', $booked->id)->count(), 'the booking is kept');
        $this->assertSame('active', $meeting->fresh()->status);
        $this->assertSame(60, TrainingRoom::where('name_ar', 'المسرح')->value('capacity'));

        // Applying the same file again changes nothing.
        $again = $this->asUser($admin)->post('/api/v1/admin/rooms/sync/preview', ['file' => $this->calendar([['رجال', 'القاعة 1', 25], ['نساء', 'القاعة 3', 25], ['الكل', 'المسرح', 60]])], ['Accept' => 'application/json'])->json('data');
        $this->assertSame([0, 0, 3], [count($again['add']), count($again['update']), count($again['same'])]);
    }

    public function test_a_room_list_with_any_column_order_names_meeting_rooms_and_reactivates_a_room(): void
    {
        $admin = $this->makeUser(Role::SUPER_ADMIN);
        $old = $this->room('معمل العلوم 1', 16, ['status' => 'inactive']);
        $meeting = $this->room('قاعة قديمة للاجتماعات', 8, ['room_type' => 'meeting']);
        $file = $this->workbook(function ($s) {
            $s->fromArray([['قائمة القاعات'], [], ['السعة', 'اسم القاعة', 'النوع', 'الرمز'], [16, 'معمل العلوم ١', 'تدريب', 'LAB1'], [12, 'التميز - قاعة إجتماعات', 'اجتماعات', 'M2']]);
        }, 'القاعات');

        $plan = $this->asUser($admin)->post('/api/v1/admin/rooms/sync/preview', ['file' => $file], ['Accept' => 'application/json'])->assertOk()->json('data');

        $this->assertSame('list', $plan['source']);
        $this->assertSame([['التميز - قاعة إجتماعات', 'meeting', 12, 'M2']], array_map(fn ($a) => [$a['name'], $a['room_type'], $a['capacity'], $a['code']], $plan['add']));
        $this->assertSame(['from' => 'inactive', 'to' => 'active'], $plan['update'][0]['changes']['status'], 'Arabic-Indic digits match and the room comes back');
        $this->assertSame([], $plan['kept'], 'the list names meeting rooms, so the old one is offered for removal');
        $this->assertSame([[$meeting->id, 'delete']], array_map(fn ($r) => [$r['id'], $r['action']], $plan['remove']));

        $this->asUser($admin)->postJson('/api/v1/admin/rooms/sync/apply', ['add' => $plan['add'], 'update' => [['id' => $old->id, 'changes' => ['status' => 'active', 'code' => 'LAB1']]], 'remove' => []])->assertOk();
        $this->assertSame(['active', 'LAB1'], [$old->fresh()->status, $old->fresh()->code]);
        $this->assertSame('meeting', TrainingRoom::where('code', 'M2')->value('room_type'));
    }

    public function test_only_room_managers_sync_and_a_booked_room_is_never_deleted_one_by_one(): void
    {
        $file = $this->calendar([['رجال', 'القاعة 1', 25]]);
        $this->asUser($this->makeUser(Role::EMPLOYEE))->post('/api/v1/admin/rooms/sync/preview', ['file' => $file], ['Accept' => 'application/json'])->assertForbidden();
        $this->asUser($this->makeUser(Role::EMPLOYEE))->postJson('/api/v1/admin/rooms/sync/apply', ['add' => [], 'update' => [], 'remove' => []])->assertForbidden();

        $admin = $this->makeUser(Role::SUPER_ADMIN);
        $empty = $this->workbook(fn ($s) => $s->setCellValue('A1', 'لا شيء'));
        $this->asUser($admin)->post('/api/v1/admin/rooms/sync/preview', ['file' => $empty], ['Accept' => 'application/json'])->assertStatus(422);

        // Deleting a room with only a meeting booking used to delete the booking with it.
        $room = $this->room('قاعة الاجتماع الصغيرة', 6, ['room_type' => 'meeting']);
        RoomBooking::create(['room_id' => $room->id, 'purpose' => 'meeting', 'title' => 'اجتماع', 'starts_at' => now()->subDay(), 'ends_at' => now()->subDay()->addHour(), 'status' => 'confirmed']);
        $this->asUser($admin)->deleteJson("/api/v1/admin/rooms/{$room->id}")->assertOk()->assertJsonPath('meta.deactivated', true);
        $this->assertSame(1, RoomBooking::where('room_id', $room->id)->count());

        $this->asUser($admin)->get('/api/v1/admin/rooms/sync/template')->assertOk()->assertDownload('rooms-template.xlsx');
    }
}
