<?php

namespace Tests\Feature;

use App\Models\Program;
use App\Models\ProgramCategory;
use App\Models\Role;
use App\Services\LabelSettings;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/** Settings → Programme catalogue: codes stamped from the type's prefix, names and prefixes managed by the administrator, mode names reaching the labels. */
class ProgramCatalogTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_codes_are_generated_from_the_type_and_the_catalogue_is_managed_from_the_settings(): void
    {
        $admin = $this->makeUser(Role::CENTER_ADMIN);

        // Defaults: the five RFP types with their prefixes, the modes, the format; the first licensing code.
        $o = $this->asUser($admin)->getJson('/api/v1/admin/settings/program-catalog')->assertOk()->json('data');
        $this->assertSame(['LIC', 'EMP', 'PRO', 'SPE', 'ELE'], array_column($o['types'], 'prefix'));
        $this->assertSame('LIC-00001', collect($o['types'])->firstWhere('id', 'licensing')['next_code']);
        $this->assertSame('PRG-00001', $o['default_next_code']);
        $this->assertSame('حضوري', $o['settings']['modes']['in_person']['name_ar']);
        $this->asUser($this->makeUser(Role::TRAINER))->getJson('/api/v1/admin/settings/program-catalog')->assertForbidden();

        // The form asks for the next code of a type; creating without a code stamps it; the next one follows.
        $this->assertSame('LIC-00001', $this->asUser($admin)->getJson('/api/v1/admin/programs/next-code?program_type=licensing')->assertOk()->json('data.code'));
        $a = $this->asUser($admin)->postJson('/api/v1/admin/programs', ['title_ar' => 'برنامج الرخصة', 'program_type' => 'licensing', 'total_hours' => 10, 'capacity' => 20])->assertCreated()->json('data');
        $this->assertSame('LIC-00001', $a['code']);
        $this->assertSame('LIC-00002', $this->asUser($admin)->getJson('/api/v1/admin/programs/next-code?program_type=licensing')->json('data.code'));
        $b = $this->asUser($admin)->postJson('/api/v1/admin/programs', ['title_ar' => 'برنامج تمهين', 'program_type' => 'professionalisation', 'total_hours' => 10, 'capacity' => 20])->assertCreated()->json('data');
        $this->assertSame('PRO-00001', $b['code']);
        // A category's type gives the prefix when the programme has none of its own; no type at all gives the default prefix; an explicit code is kept.
        $cat = ProgramCategory::create(['slug' => 'lead', 'name_ar' => 'قيادة', 'name_en' => 'Leadership', 'program_type' => 'empowerment']);
        $this->assertSame('EMP-00001', $this->asUser($admin)->postJson('/api/v1/admin/programs', ['title_ar' => 'برنامج تمكين', 'category_id' => $cat->id, 'total_hours' => 10, 'capacity' => 20])->assertCreated()->json('data.code'));
        $this->assertSame('PRG-00001', $this->asUser($admin)->postJson('/api/v1/admin/programs', ['title_ar' => 'بلا نوع', 'total_hours' => 10, 'capacity' => 20])->assertCreated()->json('data.code'));
        $this->assertSame('MY-7', $this->asUser($admin)->postJson('/api/v1/admin/programs', ['code' => 'MY-7', 'title_ar' => 'يدوي', 'program_type' => 'licensing', 'total_hours' => 10, 'capacity' => 20])->assertCreated()->json('data.code'));
        Program::where('code', 'LIC-00001')->delete();
        $this->assertSame('LIC-00002', $this->asUser($admin)->getJson('/api/v1/admin/programs/next-code?program_type=licensing')->json('data.code')); // a deleted programme keeps its number

        // The administrator renames a type, changes its prefix and the format, and renames a mode; the pickers and the labels follow.
        $this->asUser($admin)->putJson('/api/v1/admin/settings/program-catalog', ['types' => ['licensing' => ['name_ar' => 'الرخصة المهنية', 'name_en' => 'Professional Licence', 'prefix' => 'lc'], 'electives' => ['enabled' => false]],
            'code' => ['digits' => 4, 'separator' => '-', 'default_prefix' => 'GEN'], 'modes' => ['online' => ['name_ar' => 'عن بُعد (مباشر)', 'name_en' => 'Live online']]])->assertOk()
            ->assertJsonPath('data.settings.types.licensing.prefix', 'LC')->assertJsonPath('data.settings.types.licensing.name_ar', 'الرخصة المهنية')->assertJsonPath('data.settings.code.digits', 4)->assertJsonPath('data.default_next_code', 'GEN-0001');
        $types = $this->asUser($admin)->getJson('/api/v1/admin/program-types')->assertOk()->json('data');
        $this->assertSame('Professional Licence', collect($types)->firstWhere('id', 'licensing')['name_en']);
        $this->assertFalse(collect($types)->firstWhere('id', 'electives')['enabled']);
        $this->assertSame('LC-0001', $this->asUser($admin)->getJson('/api/v1/admin/programs/next-code?program_type=licensing')->json('data.code'));
        $labels = app(LabelSettings::class)->all();
        $this->assertSame(['عن بُعد (مباشر)', 'Live online'], [$labels['ar']['modes.online'], $labels['en']['modes.online']]);
        $this->assertArrayNotHasKey('modes.in_person', $labels['ar']); // unchanged names add no override
        $this->assertSame('عن بُعد (مباشر)', $this->getJson('/api/v1/public/labels')->assertOk()->json('data.ar')['modes.online']); // the key itself holds a dot

        // Two types may not share a prefix; a bad prefix is refused.
        $this->asUser($admin)->putJson('/api/v1/admin/settings/program-catalog', ['types' => ['empowerment' => ['prefix' => 'LC']]])->assertStatus(422);
        $this->asUser($admin)->putJson('/api/v1/admin/settings/program-catalog', ['types' => ['empowerment' => ['prefix' => 'E1']]])->assertStatus(422);
        $this->asUser($admin)->putJson('/api/v1/admin/settings/program-catalog', ['code' => ['default_prefix' => 'PRO']])->assertStatus(422);
    }
}
