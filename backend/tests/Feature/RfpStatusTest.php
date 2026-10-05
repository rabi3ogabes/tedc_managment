<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Services\RfpRegister;
use Tests\TestCase;

class RfpStatusTest extends TestCase
{
    private const SAMPLE = <<<'MD'
### Phase 01 — Roles, Scopes & UX Essentials  (2)

- [ ] **UX-03** — Lusail

## Full register by RFP module

### UX · User Interface & Experience — واجهة المستخدم

| ID | Requirement | Status | Evidence / gap | Ph |
|---|---|---|---|---:|
| UX-01 | Simple UI | ✅ Available | Design system | — |
| UX-03 | Lusail typeface | 🟡 Partial | Ships Tajawal | 1 |
| UX-08 ★ | Role switching | 🔴 Missing | None; permissions | merged | 1 |

## The 31 mandatory items (المتطلبات الرئيسية)

| # | Requirement | Status | Notes |
|---:|---|---|---|
| 1 | Ready product | ⚪ Vendor | Vendor qualification. |
| 7 | Bilingual and roles | 🟡 Partial | Except role switching (UX-08). |
MD;

    public function test_the_register_is_read_into_modules_items_phases_and_totals(): void
    {
        $r = app(RfpRegister::class)->parse(self::SAMPLE);

        $this->assertSame(['total' => 3, 'available' => 1, 'partial' => 1, 'missing' => 1, 'mandatory' => 1, 'open' => 2], $r['totals']);
        $this->assertSame('UX', $r['modules'][0]['code']);
        $this->assertSame('User Interface & Experience', $r['modules'][0]['title_en']);
        $this->assertSame('واجهة المستخدم', $r['modules'][0]['title_ar']);

        $items = collect($r['modules'][0]['items'])->keyBy('id');
        $this->assertTrue($items['UX-08']['mandatory']);
        $this->assertSame('missing', $items['UX-08']['status']);
        $this->assertSame('None; permissions | merged', $items['UX-08']['evidence'], 'a pipe inside the evidence text is kept');
        $this->assertSame(1, $items['UX-08']['phase']);
        $this->assertNull($items['UX-01']['phase']);
        $this->assertSame('Roles, Scopes & UX Essentials', collect($r['phases'])->firstWhere('phase', 1)['title']);
        $this->assertSame(['vendor', 'partial'], array_column($r['mandatory31'], 'status'));
    }

    public function test_the_real_register_has_the_278_requirements_of_the_audit_and_unique_ids(): void
    {
        $r = app(RfpRegister::class)->parse(file_get_contents(base_path('../docs/rfp/gap-register.md')));
        $ids = collect($r['modules'])->flatMap(fn ($m) => array_column($m['items'], 'id'));

        $this->assertSame($ids->count(), $ids->unique()->count(), 'no requirement id appears twice');
        $this->assertSame(278, $r['totals']['total']);
        $this->assertSame($r['totals']['total'], $r['totals']['available'] + $r['totals']['partial'] + $r['totals']['missing']);
        $this->assertCount(31, $r['mandatory31']);
        $this->assertCount(19, $r['phases']);
    }

    public function test_the_shipped_status_file_is_in_step_with_the_register(): void
    {
        $fresh = app(RfpRegister::class)->build(base_path('../docs/rfp/gap-register.md'));
        $shipped = json_decode(file_get_contents(resource_path('rfp/status.json')), true);

        unset($fresh['generated_at'], $shipped['generated_at']);
        $this->assertSame($fresh, $shipped, 'run `php artisan tedc:rfp-status` after changing docs/rfp/gap-register.md');
    }

    public function test_only_settings_managers_can_read_the_compliance_status(): void
    {
        $this->asUser($this->makeEmployee()->user)->getJson('/api/v1/admin/rfp-status')->assertForbidden();

        $res = $this->asUser($this->makeUser(Role::SUPER_ADMIN))->getJson('/api/v1/admin/rfp-status')->assertOk();
        $this->assertGreaterThan(200, $res->json('data.totals.total'));
        $this->assertNotEmpty($res->json('data.modules'));
    }
}
