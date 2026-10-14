<?php

namespace Tests\Feature;

use App\Integrations\Contracts\ContractFixtures;
use App\Integrations\Contracts\ContractStore;
use App\Models\Announcement;
use App\Services\Communication\MinistryExporter;
use App\Services\Communication\MinistrySiteSettings;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** The push of news and events to the Ministry website on the contract kit: the intake address, the key header and the item come from the website settings and the contract. */
class MinistrySiteContractTest extends TestCase
{
    private function item(): Announcement
    {
        return Announcement::create(['type' => 'news', 'title_ar' => 'افتتاح التسجيل', 'title_en' => 'Registration opens', 'body_ar' => 'نص', 'body_en' => 'Text', 'status' => 'published', 'is_public' => true, 'export_to_ministry' => true, 'published_at' => now()]);
    }

    private function site(array $extra = []): void
    {
        app(MinistrySiteSettings::class)->update($extra + ['enabled' => true, 'endpoint' => 'https://ministry.test/api/tedc-feed', 'api_key' => 'SITE-KEY-1', 'site_name' => 'tedc-prod']);
    }

    public function test_the_item_goes_to_the_intake_address_with_the_key_header_and_the_source_name(): void
    {
        $this->site(['auth_header' => 'X-Ministry-Key']);
        ContractFixtures::fake('ministry_site', 'https://ministry.test', settings: ['publish_path' => '/api/tedc-feed', 'auth_header' => 'X-Ministry-Key']);

        $this->assertNull(app(MinistryExporter::class)->push($this->item()));

        Http::assertSent(fn (Request $r) => $r->url() === 'https://ministry.test/api/tedc-feed' && $r->method() === 'POST' && $r->hasHeader('X-Ministry-Key', 'SITE-KEY-1')
            && $r['source'] === 'tedc-prod' && $r['item']['title']['en'] === 'Registration opens');
    }

    public function test_the_default_header_is_used_when_none_is_set_and_a_query_on_the_address_is_kept(): void
    {
        $this->site(['endpoint' => 'https://ministry.test/ingest?channel=tedc', 'auth_header' => '']);
        ContractFixtures::fake('ministry_site', 'https://ministry.test', settings: ['publish_path' => '/ingest']);

        $this->assertNull(app(MinistryExporter::class)->push($this->item()));

        Http::assertSent(fn (Request $r) => $r->url() === 'https://ministry.test/ingest?channel=tedc' && $r->hasHeader('X-Api-Key', 'SITE-KEY-1'));
    }

    public function test_a_refusal_and_a_failure_come_back_as_a_reason_without_the_key(): void
    {
        $this->site();
        ContractFixtures::fake('ministry_site', 'https://ministry.test', ['publish_item' => 'unauthorised'], settings: ['publish_path' => '/api/tedc-feed']);
        $reason = app(MinistryExporter::class)->push($this->item());
        $this->assertStringContainsString('refused the credentials', (string) $reason);
        $this->assertStringContainsString('401', (string) $reason);

        Http::swap(new Factory);
        ContractFixtures::fake('ministry_site', 'https://ministry.test', ['publish_item' => 'server_error'], settings: ['publish_path' => '/api/tedc-feed']);
        $reason = app(MinistryExporter::class)->push($this->item());
        $this->assertStringContainsString('500', (string) $reason);
        $this->assertStringNotContainsString('SITE-KEY-1', (string) $reason);
    }

    public function test_an_address_that_is_not_an_address_is_a_reason_not_a_crash(): void
    {
        $this->site(['endpoint' => 'not an address']);
        $this->assertStringContainsString('address', (string) app(MinistryExporter::class)->push($this->item()));
    }

    public function test_an_override_of_the_contract_adapts_the_item_without_a_deploy(): void
    {
        $this->site();
        $store = app(ContractStore::class);
        $doc = $store->active('ministry_site')->document();
        $doc['operations']['publish_item']['body']['template'] = ['origin' => '{{ source }}', 'payload' => '{{ item }}'];
        $store->save('ministry_site', 'contract', $doc, null);
        ContractFixtures::fake('ministry_site', 'https://ministry.test', settings: ['publish_path' => '/api/tedc-feed']);

        $this->assertNull(app(MinistryExporter::class)->push($this->item()));
        Http::assertSent(fn (Request $r) => $r['origin'] === 'tedc-prod' && $r['payload']['title']['ar'] === 'افتتاح التسجيل');
    }
}
