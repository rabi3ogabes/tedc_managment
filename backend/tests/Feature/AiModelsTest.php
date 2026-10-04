<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\SiteSetting;
use App\Services\Ai\AiGateway;
use App\Services\Ai\AiModelSettings;
use App\Services\Kits\ImageGenerator;
use App\Services\Kits\KitAi;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AiModelsTest extends TestCase
{
    private function connect($admin, string $key = 'sk-or-secret-1234'): array
    {
        $c = $this->asUser($admin)->postJson('/api/v1/admin/settings/ai-models/connections', ['name' => 'OpenRouter', 'driver' => 'openrouter', 'api_key' => $key])->assertCreated();
        $m = $this->asUser($admin)->postJson('/api/v1/admin/settings/ai-models/models', ['connection_id' => $c->json('id'), 'model' => 'anthropic/claude-sonnet', 'label' => 'Claude', 'tasks' => ['text']])->assertCreated();

        return [$c->json('id'), $m->json('id')];
    }

    public function test_the_key_is_stored_encrypted_and_never_returned(): void
    {
        $admin = $this->makeUser(Role::SUPER_ADMIN);
        $this->connect($admin);

        $res = $this->asUser($admin)->getJson('/api/v1/admin/settings/ai-models')->assertOk();
        $this->assertStringNotContainsString('sk-or-secret', $res->getContent());
        $this->assertTrue($res->json('data.connections.0.has_key'));
        $this->assertSame('••••1234', $res->json('data.connections.0.key_hint'));
        $this->assertStringNotContainsString('sk-or-secret', json_encode(SiteSetting::find('ai_models')->value));

        // Editing without a key keeps the one saved.
        $id = $res->json('data.connections.0.id');
        $this->asUser($admin)->putJson("/api/v1/admin/settings/ai-models/connections/{$id}", ['name' => 'Main', 'driver' => 'openrouter'])->assertOk();
        $this->assertSame('sk-or-secret-1234', app(AiModelSettings::class)->key($id));
    }

    public function test_only_settings_managers_can_open_it(): void
    {
        $this->asUser($this->makeEmployee()->user)->getJson('/api/v1/admin/settings/ai-models')->assertForbidden();
    }

    public function test_an_assigned_model_answers_for_content_and_drives_the_kit_ai(): void
    {
        $admin = $this->makeUser(Role::SUPER_ADMIN);
        [, $model] = $this->connect($admin);

        Http::fake(['openrouter.ai/api/v1/chat/completions' => Http::response(['choices' => [['message' => ['content' => "```json\n{\"title\":\"مرحبا\"}\n```"]]]])]);
        $this->asUser($admin)->putJson('/api/v1/admin/settings/ai-models/assignments', ['text' => $model])->assertOk()->assertJsonPath('data.assignments.text', $model);

        $out = app(KitAi::class)->json('sys', 'prompt', ['type' => 'object']);
        $this->assertSame(['title' => 'مرحبا'], $out);
        Http::assertSent(fn ($r) => $r->hasHeader('Authorization', 'Bearer sk-or-secret-1234') && $r['model'] === 'anthropic/claude-sonnet');
    }

    public function test_a_model_cannot_be_assigned_to_work_it_is_not_for(): void
    {
        $admin = $this->makeUser(Role::SUPER_ADMIN);
        [, $model] = $this->connect($admin);
        $this->asUser($admin)->putJson('/api/v1/admin/settings/ai-models/assignments', ['image' => $model])->assertOk()->assertJsonPath('data.assignments.image', null);
    }

    public function test_images_come_from_the_assigned_openrouter_model_and_fall_back_when_it_fails(): void
    {
        $admin = $this->makeUser(Role::SUPER_ADMIN);
        [$conn] = $this->connect($admin);
        $img = $this->asUser($admin)->postJson('/api/v1/admin/settings/ai-models/models', ['connection_id' => $conn, 'model' => 'google/gemini-image', 'tasks' => ['image']])->json('id');
        $this->asUser($admin)->putJson('/api/v1/admin/settings/ai-models/assignments', ['image' => $img])->assertOk();
        $this->assertSame('model', app(ImageGenerator::class)->provider());

        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
        Http::fake(['openrouter.ai/*' => Http::sequence()
            ->push(['choices' => [['message' => ['images' => [['image_url' => ['url' => 'data:image/png;base64,'.base64_encode($png)]]]]]]])
            ->push(['error' => ['message' => 'no credit']], 402)]);
        $this->assertSame('image/png', app(AiGateway::class)->image('a pattern')['mime']);
        $this->assertNull(app(AiGateway::class)->image('a pattern'), 'a failing provider never breaks the studio');
    }

    public function test_the_test_button_reports_success_and_failure(): void
    {
        $admin = $this->makeUser(Role::SUPER_ADMIN);
        [, $model] = $this->connect($admin);

        Http::fake(['openrouter.ai/*' => Http::sequence()->push(['choices' => [['message' => ['content' => 'Hello!']]]])->push(['error' => ['message' => 'invalid key']], 401)]);
        $this->asUser($admin)->postJson("/api/v1/admin/settings/ai-models/models/{$model}/test")->assertOk()->assertJsonPath('data.ok', true)->assertJsonPath('data.sample', 'Hello!');

        $this->asUser($admin)->postJson("/api/v1/admin/settings/ai-models/models/{$model}/test")->assertOk()->assertJsonPath('data.ok', false)
            ->assertJsonPath('data.models.0.last_test.ok', false);
    }

    public function test_the_catalog_is_read_from_the_provider_and_tagged_by_kind(): void
    {
        $admin = $this->makeUser(Role::SUPER_ADMIN);
        [$conn] = $this->connect($admin);
        Http::fake(['openrouter.ai/api/v1/models' => Http::response(['data' => [
            ['id' => 'a/text', 'name' => 'Text', 'context_length' => 8000, 'pricing' => ['prompt' => '0', 'completion' => '0'], 'architecture' => ['input_modalities' => ['text', 'image'], 'output_modalities' => ['text']]],
            ['id' => 'b/img', 'name' => 'Img', 'pricing' => ['prompt' => '1', 'completion' => '1'], 'architecture' => ['output_modalities' => ['image', 'text']]],
        ]])]);

        $rows = collect($this->asUser($admin)->getJson("/api/v1/admin/settings/ai-models/connections/{$conn}/catalog")->assertOk()->json('data'))->keyBy('id');
        $this->assertTrue($rows['a/text']['free']);
        $this->assertContains('image', $rows['b/img']['tasks']);
        $this->assertTrue($rows['a/text']['vision']);
    }

    public function test_deleting_a_connection_removes_its_models_and_assignments(): void
    {
        $admin = $this->makeUser(Role::SUPER_ADMIN);
        [$conn, $model] = $this->connect($admin);
        $this->asUser($admin)->putJson('/api/v1/admin/settings/ai-models/assignments', ['text' => $model])->assertOk();
        $res = $this->asUser($admin)->deleteJson("/api/v1/admin/settings/ai-models/connections/{$conn}")->assertOk();

        $this->assertSame([], $res->json('data.models'));
        $this->assertNull($res->json('data.assignments.text'));
    }
}
