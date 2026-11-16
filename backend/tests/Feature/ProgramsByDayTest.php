<?php

namespace Tests\Feature;

use App\Models\CalendarDay;
use App\Models\Program;
use App\Models\Role;
use App\Models\Trainer;
use App\Models\TrainingRoom;
use Carbon\CarbonImmutable;
use Tests\TestCase;

/**
 * The programme list by day: `on` shows the programmes that train on a date, and the row of days counts them day by day, leaving
 * the days off out and keeping a working day without training (the one shown with its light off).
 */
class ProgramsByDayTest extends TestCase
{
    private const LIST = '/api/v1/admin/programs';

    private const DAYS = '/api/v1/admin/programs/days';

    /** Monday 16 November 2026, ten in the morning: Friday 20 and Saturday 21 are the weekend. */
    private function monday(): CarbonImmutable
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-16 10:00:00'));

        return CarbonImmutable::parse('2026-11-16');
    }

    private function program(string $code, array $extra = []): Program
    {
        return $this->makeProgram($extra + ['code' => $code, 'title_ar' => "برنامج {$code}", 'title_en' => "Programme {$code}"]);
    }

    /** @return list<string> the dates of the row */
    private function dates(array $window): array
    {
        return array_column($window['days'], 'date');
    }

    private function day(array $window, string $date): array
    {
        return collect($window['days'])->firstWhere('date', $date);
    }

    public function test_a_date_shows_the_programmes_that_train_on_it_in_the_order_of_the_day(): void
    {
        $mon = $this->monday();
        $admin = $this->makeUser(Role::CENTER_ADMIN);
        $room = TrainingRoom::create(['code' => 'R-7', 'name_ar' => 'قاعة الريادة', 'name_en' => 'Pioneer Hall', 'capacity' => 24, 'status' => 'active']);
        $trainer = Trainer::create(['name_ar' => 'سامر الكواري', 'name_en' => 'Samer', 'status' => 'active', 'source' => 'center']);

        $nine = $this->program('NINE');
        $this->makeSession($nine, $mon->setTime(9, 0))->update(['training_room_id' => $room->id, 'trainer_id' => $trainer->id]);
        $eight = $this->program('EIGHT', ['delivery_mode' => 'online']);
        $this->makeSession($eight, $mon->setTime(8, 0));
        $this->makeSession($eight, $mon->setTime(13, 0));
        $tuesday = $this->program('TUE');
        $this->makeSession($tuesday, $mon->addDay()->setTime(9, 0));
        $cancelled = $this->program('GONE');
        $this->makeSession($cancelled, $mon->setTime(9, 0))->update(['status' => 'cancelled']);
        $this->program('NO-SESSIONS');

        $res = $this->asUser($admin)->getJson(self::LIST.'?on=2026-11-16')->assertOk();
        $this->assertSame(['EIGHT', 'NINE'], array_column($res->json('data'), 'code'));
        $this->assertSame(['in_person' => 1, 'online' => 1, 'hybrid' => 0], $res->json('types'));

        // The sessions of the day come with each programme: hours, room and trainer.
        $this->assertCount(2, $res->json('data.0.day_sessions'));
        $res->assertJsonPath('data.1.day_sessions.0.room', 'قاعة الريادة')
            ->assertJsonPath('data.1.day_sessions.0.trainer', 'سامر الكواري')
            ->assertJsonPath('data.1.day_sessions.0.starts_at', $mon->setTime(9, 0)->toIso8601String());

        $this->asUser($admin)->getJson(self::LIST.'?on=2026-11-17')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.code', 'TUE');
        $this->asUser($admin)->getJson(self::LIST.'?on=2026-11-18')->assertOk()->assertJsonCount(0, 'data');
        $this->asUser($admin)->getJson(self::LIST.'?on=2026-11-16&delivery_mode=online')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.code', 'EIGHT');

        // Without a date the list is the whole list, and carries no sessions.
        $all = $this->asUser($admin)->getJson(self::LIST)->assertOk();
        $this->assertContains('NO-SESSIONS', array_column($all->json('data'), 'code'));
        $this->assertArrayNotHasKey('day_sessions', $all->json('data.0'));
    }

    public function test_the_row_of_days_leaves_the_weekend_out_and_keeps_a_day_without_training(): void
    {
        $mon = $this->monday();
        $admin = $this->makeUser(Role::CENTER_ADMIN);
        $a = $this->program('A');
        $this->makeSession($a, $mon->setTime(9, 0));
        $this->makeSession($a, $mon->addDay()->setTime(9, 0));
        $this->makeSession($this->program('B'), $mon->setTime(11, 0));

        $window = $this->asUser($admin)->getJson(self::DAYS.'?around=2026-11-16&before=3&after=5')->assertOk()->json('data');

        // Friday 13, Saturday 14, Friday 20 and Saturday 21 are not there.
        $this->assertSame(['2026-11-11', '2026-11-12', '2026-11-15', '2026-11-16', '2026-11-17', '2026-11-18', '2026-11-19', '2026-11-22', '2026-11-23'], $this->dates($window));
        $this->assertSame('2026-11-10', $window['prev']);
        $this->assertSame('2026-11-24', $window['next']);
        $this->assertSame('2026-11-16', $window['today']);

        $this->assertSame(2, $this->day($window, '2026-11-16')['programs']);
        $this->assertSame(1, $this->day($window, '2026-11-17')['programs']);
        // A working day with no programme stays in the row: it is the one shown with its light off.
        $this->assertSame(['programs' => 0, 'sessions' => 0, 'off' => false], array_intersect_key($this->day($window, '2026-11-18'), ['programs' => 0, 'sessions' => 0, 'off' => 0]));

        // Without a date the row stands around today.
        $this->assertSame('2026-11-16', $this->asUser($admin)->getJson(self::DAYS)->assertOk()->json('data.around'));
    }

    public function test_a_vacation_is_left_out_unless_it_has_training_and_a_long_one_is_skipped_in_one_go(): void
    {
        $mon = $this->monday();
        $admin = $this->makeUser(Role::CENTER_ADMIN);
        CalendarDay::create(['date' => '2026-11-18', 'type' => 'vacation', 'title_ar' => 'إجازة', 'title_en' => 'Holiday']);
        CalendarDay::create(['date' => '2026-11-19', 'type' => 'exam', 'title_ar' => 'اختبارات', 'title_en' => 'Exams']);
        foreach (['2026-11-22', '2026-11-23', '2026-11-24', '2026-11-25', '2026-11-26'] as $date) {
            CalendarDay::create(['date' => $date, 'type' => 'vacation', 'title_ar' => 'إجازة منتصف الفصل', 'title_en' => 'Mid-term break']);
        }

        $window = $this->asUser($admin)->getJson(self::DAYS.'?around=2026-11-16&before=0&after=3')->assertOk()->json('data');
        // Wednesday is a vacation, Thursday is an exam day (a working day, closed for training), then a week off: the row jumps to the 29th.
        $this->assertSame(['2026-11-16', '2026-11-17', '2026-11-19', '2026-11-29'], $this->dates($window));
        $this->assertSame('exam', $this->day($window, '2026-11-19')['kind']);
        $this->assertFalse($this->day($window, '2026-11-19')['off']);

        // Training approved on the vacation and on a Friday: both days come back, marked as days off.
        $this->makeSession($this->program('VAC'), $mon->addDays(2)->setTime(9, 0));
        $this->makeSession($this->program('FRI'), $mon->addDays(4)->setTime(9, 0));
        $window = $this->asUser($admin)->getJson(self::DAYS.'?around=2026-11-16&before=0&after=4')->assertOk()->json('data');
        $this->assertSame(['2026-11-16', '2026-11-17', '2026-11-18', '2026-11-19', '2026-11-20'], $this->dates($window));
        $this->assertSame(['programs' => 1, 'off' => true, 'kind' => 'vacation', 'title' => 'إجازة'], array_intersect_key($this->day($window, '2026-11-18'), ['programs' => 0, 'off' => 0, 'kind' => 0, 'title' => 0]));
        $this->assertSame(['programs' => 1, 'off' => true, 'kind' => 'weekend'], array_intersect_key($this->day($window, '2026-11-20'), ['programs' => 0, 'off' => 0, 'kind' => 0]));

        // A day off picked from the calendar is in the row all the same, so it can be shown as the chosen day.
        $window = $this->asUser($admin)->getJson(self::DAYS.'?around=2026-11-21&before=1&after=1')->assertOk()->json('data');
        $this->assertSame(['2026-11-20', '2026-11-21', '2026-11-29'], $this->dates($window));
        $this->assertSame(['programs' => 0, 'off' => true], array_intersect_key($this->day($window, '2026-11-21'), ['programs' => 0, 'off' => 0]));
    }

    public function test_the_row_follows_the_filters_of_the_list_and_counts_a_session_on_every_day_it_touches(): void
    {
        $mon = $this->monday();
        $admin = $this->makeUser(Role::CENTER_ADMIN);
        $leadership = $this->program('LDR-1', ['title_ar' => 'القيادة التربوية', 'delivery_mode' => 'online', 'status' => Program::STATUS_IN_PROGRESS]);
        $this->makeSession($leadership, $mon->setTime(9, 0));
        $this->makeSession($this->program('MATH-1', ['title_ar' => 'الرياضيات']), $mon->setTime(9, 0));
        // A camp from Tuesday evening to Wednesday morning counts on both days.
        $this->makeSession($this->program('CAMP'), $mon->addDay()->setTime(20, 0), 14);

        $count = fn (string $query, string $date) => $this->day($this->asUser($admin)->getJson(self::DAYS.'?around=2026-11-16&before=0&after=3'.$query)->assertOk()->json('data'), $date)['programs'];
        $this->assertSame(2, $count('', '2026-11-16'));
        $this->assertSame(1, $count('&q=LDR', '2026-11-16'));
        $this->assertSame(1, $count('&q='.urlencode('الرياضيات'), '2026-11-16'));
        $this->assertSame(1, $count('&delivery_mode=online', '2026-11-16'));
        $this->assertSame(1, $count('&status='.Program::STATUS_IN_PROGRESS, '2026-11-16'));
        $this->assertSame(1, $count('', '2026-11-17'));
        $this->assertSame(1, $count('', '2026-11-18'));
        $this->asUser($admin)->getJson(self::LIST.'?on=2026-11-18')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.code', 'CAMP');
    }

    public function test_a_trainer_sees_only_the_days_of_their_own_programmes(): void
    {
        $mon = $this->monday();
        $user = $this->makeUser(Role::TRAINER);
        $trainer = Trainer::create(['user_id' => $user->id, 'name_ar' => 'مدرب', 'name_en' => 'Trainer', 'status' => 'active', 'source' => 'center']);
        $mine = $this->program('MINE');
        $mine->trainers()->attach($trainer->id, ['role' => 'lead']);
        $this->makeSession($mine, $mon->setTime(9, 0));
        $this->makeSession($this->program('OTHER'), $mon->setTime(9, 0));
        $this->makeSession($this->program('OTHER-2'), $mon->addDay()->setTime(9, 0));

        $window = $this->asUser($user)->getJson(self::DAYS.'?around=2026-11-16&before=0&after=1')->assertOk()->json('data');
        $this->assertSame(1, $this->day($window, '2026-11-16')['programs']);
        $this->assertSame(0, $this->day($window, '2026-11-17')['programs']);
        $this->asUser($user)->getJson(self::LIST.'?on=2026-11-16')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.code', 'MINE');
    }

    public function test_a_wrong_date_is_refused_and_the_row_is_not_for_everyone(): void
    {
        $this->monday();
        $admin = $this->makeUser(Role::CENTER_ADMIN);
        $this->asUser($admin)->getJson(self::LIST.'?on=16/11/2026')->assertStatus(422);
        $this->asUser($admin)->getJson(self::DAYS.'?around=tomorrow')->assertStatus(422);
        $this->asUser($admin)->getJson(self::DAYS.'?before=99')->assertStatus(422);
        $this->asUser($admin)->getJson(self::DAYS.'?after=99')->assertStatus(422);

        $this->asUser($this->makeUser(Role::EMPLOYEE))->getJson(self::DAYS)->assertStatus(403);
    }
}
