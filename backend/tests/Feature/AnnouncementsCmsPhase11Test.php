<?php

namespace Tests\Feature;

use App\Models\Announcement;
use App\Models\AnnouncementRsvp;
use App\Models\AppNotification;
use App\Models\MinistryExport;
use App\Models\PageVersion;
use App\Models\Program;
use App\Models\Role;
use App\Services\Cms\HtmlSanitizer;
use App\Services\Communication\MinistryExporter;
use App\Services\Communication\MinistrySiteSettings;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AnnouncementsCmsPhase11Test extends TestCase
{
    private function trainingHead()
    {
        return $this->makeUser(Role::TRAINING_HEAD);
    }

    private function visitor(): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeader('Authorization', '');
    }

    private function item(array $extra = []): Announcement
    {
        return Announcement::create($extra + ['type' => 'news', 'title_ar' => 'خبر', 'title_en' => 'News', 'body_ar' => 'نص', 'body_en' => 'Text', 'audience' => 'all', 'is_public' => true, 'status' => 'published', 'published_at' => now()->subHour()]);
    }

    public function test_windows_open_and_close_and_only_live_items_are_public(): void
    {
        $head = $this->trainingHead();
        $later = $this->asUser($head)->postJson('/api/v1/admin/announcements', ['title_ar' => 'قادم', 'title_en' => 'Coming', 'is_public' => true, 'starts_at' => now()->addHours(2)->toIso8601String(), 'ends_at' => now()->addDays(2)->toIso8601String(), 'publish' => true])->assertCreated();
        $this->assertSame('scheduled', $later->json('data.status'));
        $this->assertSame(0, $later->json('meta.recipients'));
        $this->getJson('/api/v1/public/news')->assertOk()->assertJsonCount(0, 'data');

        Announcement::whereKey($later->json('data.id'))->update(['starts_at' => now()->subMinute()]);
        $this->artisan('tedc:announcements-tick')->assertSuccessful();
        $this->assertSame('published', Announcement::find($later->json('data.id'))->status);
        $this->getJson('/api/v1/public/news')->assertOk()->assertJsonCount(1, 'data');

        Announcement::whereKey($later->json('data.id'))->update(['ends_at' => now()->subMinute()]);
        $this->artisan('tedc:announcements-tick')->assertSuccessful();
        $this->assertSame('expired', Announcement::find($later->json('data.id'))->status);
        $this->getJson('/api/v1/public/news')->assertOk()->assertJsonCount(0, 'data');

        // Several can be live together.
        $this->item(['title_en' => 'A']);
        $this->item(['title_en' => 'B']);
        $this->getJson('/api/v1/public/news')->assertJsonCount(2, 'data');
    }

    public function test_pinned_items_come_first_in_their_order_and_the_archive_is_searchable(): void
    {
        $head = $this->trainingHead();
        $a = $this->item(['title_en' => 'Alpha', 'title_ar' => 'ألفا']);
        $b = $this->item(['title_en' => 'Beta', 'title_ar' => 'بيتا']);
        $c = $this->item(['title_en' => 'Gamma', 'title_ar' => 'جاما']);
        $this->asUser($head)->postJson("/api/v1/admin/announcements/{$c->id}/pin", ['pinned' => true])->assertOk();
        $this->asUser($head)->postJson("/api/v1/admin/announcements/{$b->id}/pin", ['pinned' => true])->assertOk();
        $this->assertSame(['جاما', 'بيتا', 'ألفا'], array_column($this->visitor()->getJson('/api/v1/public/news')->json('data'), 'title') ?: []);   // pin order 1, 2, then the rest

        $this->asUser($head)->putJson('/api/v1/admin/announcements/pins/order', ['ids' => [$b->id, $c->id]])->assertOk();
        $this->assertSame('بيتا', $this->visitor()->getJson('/api/v1/public/news')->json('data.0.title'));

        $this->asUser($head)->postJson("/api/v1/admin/announcements/{$a->id}/archive")->assertOk()->assertJsonPath('data.status', 'archived');
        $this->assertCount(2, $this->getJson('/api/v1/public/news')->json('data'));
        $found = $this->asUser($head)->getJson('/api/v1/admin/announcements/archive?q=ALPHA')->assertOk();   // upper and lower case match
        $this->assertSame([$a->id], array_column($found->json('data'), 'id'));
        $this->asUser($head)->getJson('/api/v1/admin/announcements/archive?q='.rawurlencode('ألفا'))->assertOk()->assertJsonCount(1, 'data');
        $this->asUser($head)->getJson('/api/v1/admin/announcements/archive?q=nothing')->assertJsonCount(0, 'data');
        $this->asUser($head)->getJson('/api/v1/admin/announcements/archive?type=event')->assertJsonCount(0, 'data');
    }

    public function test_republish_copies_the_media_and_resets_the_window(): void
    {
        $head = $this->trainingHead();
        $old = $this->item(['media' => ['audio' => [['title' => 'Clip', 'url' => 'https://cdn.test/a.mp3']], 'images' => [['title' => 'p', 'path' => 'x.jpg']]], 'starts_at' => now()->subMonth(), 'ends_at' => now()->subWeek(), 'status' => 'expired', 'is_pinned' => true, 'export_to_ministry' => true]);
        $start = now()->addDay();
        $r = $this->asUser($head)->postJson("/api/v1/admin/announcements/{$old->id}/republish", ['starts_at' => $start->toIso8601String(), 'ends_at' => now()->addDays(10)->toIso8601String()])->assertCreated();
        $copy = Announcement::find($r->json('data.id'));
        $this->assertNotSame($old->id, $copy->id);
        $this->assertSame($old->id, $copy->republished_from_id);
        $this->assertSame($old->media, $copy->media);
        $this->assertSame('scheduled', $copy->status);              // the window starts tomorrow
        $this->assertFalse($copy->is_pinned);
        $this->assertTrue($copy->ends_at->isFuture());
        $this->assertSame('expired', $old->fresh()->status);
    }

    public function test_media_upload_checks_the_kind_and_supports_audio(): void
    {
        Storage::fake('local');
        $head = $this->trainingHead();
        $a = $this->item();
        $this->asUser($head)->postJson("/api/v1/admin/announcements/{$a->id}/media", ['kind' => 'audio', 'url' => 'https://cdn.test/talk.mp3', 'title' => 'Talk'])->assertOk()->assertJsonPath('data.media.audio.0.title', 'Talk');
        $this->asUser($head)->post("/api/v1/admin/announcements/{$a->id}/media", ['kind' => 'audio', 'file' => UploadedFile::fake()->create('x.pdf', 10, 'application/pdf')], ['Accept' => 'application/json'])->assertStatus(422);
        $this->asUser($head)->postJson("/api/v1/admin/announcements/{$a->id}/media", ['kind' => 'video'])->assertStatus(422)->assertJsonPath('code', 'media_required');
        $this->asUser($head)->deleteJson("/api/v1/admin/announcements/{$a->id}/media", ['kind' => 'audio', 'index' => 0])->assertOk()->assertJsonPath('data.media.audio', []);
        $this->visitor()->getJson('/api/v1/public/news/'.$a->id)->assertOk()->assertJsonPath('data.media.audio', []);
    }

    public function test_events_take_registrations_up_to_capacity_with_a_waiting_list(): void
    {
        $head = $this->trainingHead();
        $ev = $this->asUser($head)->postJson('/api/v1/admin/announcements', [
            'type' => 'event', 'title_ar' => 'ملتقى', 'title_en' => 'Forum', 'is_public' => true, 'publish' => true,
            'event' => ['starts_at' => now()->addDays(3)->toIso8601String(), 'venue_en' => 'Hall', 'venue_ar' => 'القاعة', 'rsvp' => true, 'capacity' => 1],
        ])->assertCreated()->json('data.id');
        $u1 = $this->makeUser();
        $u2 = $this->makeUser();

        $this->asUser($u1)->postJson("/api/v1/me/events/{$ev}/rsvp", ['going' => true])->assertOk()->assertJsonPath('data.status', 'going');
        $this->asUser($u2)->postJson("/api/v1/me/events/{$ev}/rsvp", ['going' => true])->assertOk()->assertJsonPath('data.status', 'waitlisted');
        $this->assertSame(1, AppNotification::where('user_id', $u1->id)->where('type', 'event.rsvp_confirmed')->count());

        $this->asUser($u1)->postJson("/api/v1/me/events/{$ev}/rsvp", ['going' => false])->assertOk();     // a seat frees up → the waiting person is confirmed
        $this->assertSame('going', AnnouncementRsvp::where(['announcement_id' => $ev, 'user_id' => $u2->id])->value('status'));
        $this->asUser($head)->getJson("/api/v1/admin/announcements/{$ev}/rsvps")->assertOk()->assertJsonPath('meta.going', 1);

        // A plain announcement does not take registrations.
        $plain = $this->item();
        $this->asUser($u1)->postJson("/api/v1/me/events/{$plain->id}/rsvp", ['going' => true])->assertStatus(422)->assertJsonPath('code', 'rsvp_closed');

        // The reminder goes once, about a day before.
        Announcement::whereKey($ev)->update(['event' => json_encode(['starts_at' => now()->addHours(10)->toIso8601String(), 'rsvp' => true, 'capacity' => 1])]);
        $this->artisan('tedc:announcements-tick')->assertSuccessful();
        $this->assertSame(1, AppNotification::where('user_id', $u2->id)->where('type', 'event.reminder')->count());
        $this->artisan('tedc:announcements-tick')->assertSuccessful();
        $this->assertSame(1, AppNotification::where('user_id', $u2->id)->where('type', 'event.reminder')->count());

        $ics = $this->get("/api/v1/public/events/{$ev}/event.ics")->assertOk();
        $this->assertStringContainsString('BEGIN:VEVENT', $ics->getContent());
        $this->getJson('/api/v1/public/events')->assertOk()->assertJsonPath('data.0.title', fn ($t) => in_array($t, ['ملتقى', 'Forum'], true));
    }

    public function test_feeds_contain_only_items_flagged_for_the_ministry_and_are_valid(): void
    {
        $this->item(['title_en' => 'Exported', 'title_ar' => 'مصدّر', 'export_to_ministry' => true, 'cover_path' => '/img/c.jpg']);
        $this->item(['title_en' => 'Internal', 'export_to_ministry' => false]);
        $this->item(['title_en' => 'Not public', 'export_to_ministry' => true, 'is_public' => false]);
        $this->item(['type' => 'event', 'title_en' => 'Exported event', 'export_to_ministry' => true, 'event' => ['starts_at' => now()->addDay()->toIso8601String(), 'venue_en' => 'Hall']]);

        $news = $this->getJson('/api/v1/public/feeds/news.json')->assertOk();
        $this->assertSame(['Exported'], array_column(array_column($news->json('items'), 'title'), 'en'));
        $this->assertSame(['ar', 'en'], array_keys($news->json('items.0.title')));
        $this->assertStringStartsWith('http', $news->json('items.0.image'));
        $this->assertSame(['Exported event'], array_column(array_column($this->getJson('/api/v1/public/feeds/events.json')->json('items'), 'title'), 'en'));

        foreach (['rss.xml' => 'rss', 'atom.xml' => 'feed'] as $name => $root) {
            $xml = $this->get("/api/v1/public/feeds/{$name}?lang=en")->assertOk()->getContent();
            $doc = simplexml_load_string($xml);
            $this->assertNotFalse($doc, "{$name} must be well-formed XML");
            $this->assertSame($root, $doc->getName());
            $this->assertStringContainsString('Exported', $xml);
            $this->assertStringNotContainsString('Internal', $xml);
        }
        $this->assertStringContainsString('Exported', $this->get('/api/v1/public/feeds/news.csv')->getContent());
    }

    public function test_the_ministry_push_flow(): void
    {
        $admin = $this->makeUser(Role::CENTER_ADMIN);
        app(MinistrySiteSettings::class)->update(['enabled' => true, 'endpoint' => 'https://ministry.test/ingest', 'api_key' => 'secret-key', 'auto_export' => true]);
        $a = $this->item(['export_to_ministry' => true]);
        $exporter = app(MinistryExporter::class);

        Http::fake(['ministry.test/*' => Http::sequence()->push('down', 503)->push(['ok' => true], 200)]);
        $exporter->enqueue($a);
        $this->assertSame(['sent' => 0, 'failed' => 0], $exporter->run());
        $row = MinistryExport::where('announcement_id', $a->id)->first();
        $this->assertSame('queued', $row->status);
        $this->assertSame(1, $row->attempts);
        $this->assertStringContainsString('503', $row->last_error);
        $this->assertTrue($row->next_attempt_at->isFuture());
        $this->assertSame(['sent' => 0, 'failed' => 0], $exporter->run());     // not before its retry time
        Http::assertSentCount(1);

        MinistryExport::whereKey($row->id)->update(['next_attempt_at' => now()->subMinute()]);
        $this->assertSame(['sent' => 1, 'failed' => 0], $exporter->run());
        Http::assertSent(fn ($r) => $r->hasHeader('X-Api-Key', 'secret-key') && $r['item']['title']['en'] === 'News');
        $this->assertSame('sent', $row->fresh()->status);
        $this->assertNotNull($a->fresh()->exported_at);

        // Always failing: three retries, then it is given up and the administrators are told once.
        Http::fake(['ministry.test/*' => Http::response('no', 500)]);
        $b = $this->item(['title_en' => 'Second', 'export_to_ministry' => true]);
        $exporter->enqueue($b);
        for ($i = 0; $i < 4; $i++) {
            MinistryExport::where('announcement_id', $b->id)->update(['next_attempt_at' => now()->subMinute()]);
            $exporter->run();
        }
        $this->assertSame('failed', MinistryExport::where('announcement_id', $b->id)->value('status'));
        $this->assertSame(1, AppNotification::where('user_id', $admin->id)->where('type', 'ministry_export.failed')->count());
    }

    public function test_the_homepage_blocks_publish_roll_back_and_the_public_sees_only_visible_ones(): void
    {
        $head = $this->trainingHead();
        $this->getJson('/api/v1/public/pages/home')->assertOk()->assertJsonPath('data.published', false);   // nothing published yet: the built-in layout stays
        $this->getJson('/api/v1/public/pages/unknown')->assertNotFound();

        $draft = $this->asUser($head)->getJson('/api/v1/admin/pages/home/blocks')->assertOk()->json('data');
        $this->assertSame('hero_slider', $draft[0]['type']);          // the usual layout is offered to start from

        $blocks = [
            ['type' => 'rich_text', 'config' => ['title' => ['ar' => 'مرحبا', 'en' => 'Welcome'], 'body' => ['ar' => '<p>أهلا</p><script>alert(1)</script>', 'en' => '<p onclick="x()">Hi <a href="javascript:alert(1)">bad</a></p>']]],
            ['type' => 'cta', 'config' => ['label' => ['ar' => 'ابدأ', 'en' => 'Start'], 'url' => 'javascript:alert(1)']],
            ['type' => 'stats', 'config' => []],
            ['type' => 'news', 'config' => ['limit' => 2], 'is_visible' => false],
            ['type' => 'cta', 'config' => ['label' => ['en' => 'Members only']], 'audience' => 'signed_in'],
            ['type' => 'cta', 'config' => ['label' => ['en' => 'Expired']], 'ends_at' => now()->subDay()->toIso8601String()],
            ['type' => 'cta', 'config' => ['label' => ['en' => 'Not yet']], 'starts_at' => now()->addDay()->toIso8601String()],
        ];
        $saved = $this->asUser($head)->putJson('/api/v1/admin/pages/home/blocks', ['blocks' => $blocks])->assertOk()->json('data');
        $this->assertCount(7, $saved);
        $this->assertStringNotContainsString('script', json_encode($saved));
        $this->assertStringNotContainsString('javascript:', json_encode($saved));
        $this->assertStringNotContainsString('onclick', json_encode($saved));
        $this->asUser($head)->putJson('/api/v1/admin/pages/home/blocks', ['blocks' => [['type' => 'nope']]])->assertStatus(422);

        $this->visitor()->getJson('/api/v1/public/pages/home')->assertJsonPath('data.published', false);               // saved is not published
        $v1 = $this->asUser($head)->postJson('/api/v1/admin/pages/home/publish', ['note' => 'first'])->assertCreated()->json('data.version');
        $this->assertSame(1, $v1);

        $public = $this->visitor()->getJson('/api/v1/public/pages/home')->assertOk();
        $types = array_column($public->json('data.blocks'), 'type');
        $this->assertSame(['rich_text', 'cta', 'stats'], $types);                 // hidden, expired, future and signed-in blocks are not shown to visitors
        $this->assertNotEmpty($public->json('data.blocks.2.data'));               // the stats block carries its numbers
        $signed = $this->asUser($this->makeUser())->getJson('/api/v1/public/pages/home')->assertOk();
        $this->assertCount(4, $signed->json('data.blocks'));                       // + the signed-in block

        // Change the page, publish again, then roll back to the first version.
        $this->asUser($head)->putJson('/api/v1/admin/pages/home/blocks', ['blocks' => [['type' => 'rich_text', 'config' => ['title' => ['en' => 'Changed']]]]])->assertOk();
        $this->asUser($head)->postJson('/api/v1/admin/pages/home/publish')->assertCreated()->assertJsonPath('data.version', 2);
        $this->assertCount(1, $this->visitor()->getJson('/api/v1/public/pages/home')->json('data.blocks'));
        $this->asUser($head)->postJson('/api/v1/admin/pages/home/rollback/1')->assertCreated()->assertJsonPath('data.version', 3);
        $this->assertSame(['rich_text', 'cta', 'stats'], array_column($this->visitor()->getJson('/api/v1/public/pages/home')->json('data.blocks'), 'type'));
        $this->assertSame(3, PageVersion::where('page', 'home')->count());
        $this->asUser($head)->getJson('/api/v1/admin/pages/home/versions')->assertOk()->assertJsonCount(3, 'data');
        $this->asUser($head)->postJson('/api/v1/admin/pages/home/rollback/99')->assertNotFound();
        $this->asUser($this->makeUser())->putJson('/api/v1/admin/pages/home/blocks', ['blocks' => []])->assertForbidden();
    }

    public function test_public_statistics_compute_from_their_sources_and_accept_centre_values(): void
    {
        $head = $this->trainingHead();
        $this->makeProgram(['status' => Program::STATUS_PUBLISHED]);
        $list = $this->asUser($head)->getJson('/api/v1/admin/public-stats')->assertOk();
        $this->assertNotEmpty($list->json('data'));

        $this->asUser($head)->putJson('/api/v1/admin/public-stats', ['stats' => [
            ['key' => 'programs', 'label_ar' => 'برامج', 'label_en' => 'Programs', 'source' => 'programs_total'],
            ['key' => 'awards', 'label_ar' => 'جوائز', 'label_en' => 'Awards', 'source' => 'custom_value', 'value' => '12'],
            ['key' => 'hidden', 'label_ar' => 'خفي', 'label_en' => 'Hidden', 'source' => 'schools_covered', 'is_visible' => false],
        ]])->assertOk();
        $this->asUser($head)->putJson('/api/v1/admin/public-stats', ['stats' => [['key' => 'x', 'label_ar' => 'س', 'label_en' => 'x', 'source' => 'custom_query_key', 'value' => 'drop table']]])->assertStatus(422);

        Cache::flush();
        $stats = $this->getJson('/api/v1/public/defined-stats')->assertOk()->json('data');
        $this->assertSame(['programs', 'awards'], array_column($stats, 'key'));
        $this->assertGreaterThanOrEqual(1, $stats[0]['value']);
        $this->assertSame('12', $stats[1]['value']);
    }

    public function test_the_sanitiser_keeps_formatting_and_removes_anything_active(): void
    {
        $clean = HtmlSanitizer::clean('<h2>Title</h2><p dir="rtl" style="x" onclick="a()">Hi <strong>you</strong> <a href="https://ok.test" onmouseover="b()">link</a> <img src="javascript:x" alt="i"><img src="/p.png" alt="p"></p><iframe src="https://evil"></iframe><style>p{}</style>');
        $this->assertStringContainsString('<h2>Title</h2>', $clean);
        $this->assertStringContainsString('<strong>you</strong>', $clean);
        $this->assertStringContainsString('href="https://ok.test"', $clean);
        $this->assertStringContainsString('src="/p.png"', $clean);
        foreach (['onclick', 'onmouseover', 'javascript:', 'iframe', 'style', '<script'] as $bad) {
            $this->assertStringNotContainsString($bad, $clean);
        }
        $this->assertSame('', HtmlSanitizer::clean('  '));
    }
}
