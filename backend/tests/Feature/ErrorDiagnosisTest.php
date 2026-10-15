<?php

namespace Tests\Feature;

use App\Models\ErrorLog;
use App\Models\Role;
use App\Services\Ai\AiGateway;
use App\Services\Ai\AiModelSettings;
use PHPUnit\Framework\Assert;
use Tests\TestCase;

/** The error log asks the assigned AI model for a diagnosis and keeps it on the entry. */
class ErrorDiagnosisTest extends TestCase
{
    private function entry(): ErrorLog
    {
        return ErrorLog::create([
            'fingerprint' => sha1('x'), 'source' => 'server', 'level' => 'error', 'message' => 'Call to undefined method App\\Models\\Program::seats()',
            'exception' => 'Error', 'location' => 'app/Services/SeatAllocationService.php:88', 'method' => 'POST', 'url' => '/api/v1/admin/groups/1/seats', 'status_code' => 500,
            'occurrences' => 3, 'users_count' => 2, 'first_seen_at' => now()->subDay(), 'last_seen_at' => now(), 'status' => 'open',
        ]);
    }

    private function gateway(?array $answer, bool $has = true): void
    {
        $this->app->instance(AiGateway::class, new class(app(AiModelSettings::class), $answer, $has) extends AiGateway
        {
            public function __construct(AiModelSettings $s, private readonly ?array $answer, private readonly bool $hasModel)
            {
                parent::__construct($s);
            }

            public function has(string $task): bool
            {
                return $this->hasModel;
            }

            public function json(string $system, string $prompt, array $schema, ?int $maxTokens = null): ?array
            {
                // The prompt carries the scrubbed error, never the people affected.
                Assert::assertStringContainsString('SeatAllocationService.php:88', $prompt);
                Assert::assertStringNotContainsString('@', $prompt);

                return $this->answer;
            }
        });
    }

    public function test_the_diagnosis_is_asked_cleaned_and_kept_on_the_entry(): void
    {
        $admin = $this->makeUser(Role::SUPER_ADMIN);
        $log = $this->entry();
        $this->gateway(['cause' => 'The method was renamed to allocations() in Phase 4.', 'steps' => ['Call allocations() instead', '', 'Add a test'], 'files' => ['app/Services/SeatAllocationService.php'], 'verification' => 'php artisan test --filter=SeatAllocation', 'confidence' => 'high', 'extra' => 'ignored']);

        $r = $this->asUser($admin)->postJson("/api/v1/admin/error-logs/{$log->id}/diagnose")->assertOk();
        $r->assertJsonPath('data.ai_diagnosis.cause', 'The method was renamed to allocations() in Phase 4.')
            ->assertJsonPath('data.ai_diagnosis.steps', ['Call allocations() instead', 'Add a test'])
            ->assertJsonPath('data.ai_diagnosis.confidence', 'high')
            ->assertJsonMissingPath('data.ai_diagnosis.extra');
        $this->assertNotNull($log->fresh()->ai_diagnosed_at);
        $this->asUser($admin)->getJson("/api/v1/admin/error-logs/{$log->id}")->assertOk()->assertJsonPath('data.ai_diagnosis.files.0', 'app/Services/SeatAllocationService.php');
    }

    public function test_without_an_assigned_model_the_answer_points_to_the_settings_and_a_silent_model_is_reported(): void
    {
        $admin = $this->makeUser(Role::SUPER_ADMIN);
        $log = $this->entry();

        $this->gateway(null, has: false);
        $this->asUser($admin)->postJson("/api/v1/admin/error-logs/{$log->id}/diagnose")->assertStatus(409)->assertJsonPath('code', 'ai_unassigned');

        $this->gateway(null);
        $this->asUser($admin)->postJson("/api/v1/admin/error-logs/{$log->id}/diagnose")->assertStatus(502)->assertJsonPath('code', 'ai_failed');
        $this->assertNull($log->fresh()->ai_diagnosis);

        // Only the system administrator sees the error log at all.
        $this->asUser($this->makeUser(Role::CENTER_ADMIN))->postJson("/api/v1/admin/error-logs/{$log->id}/diagnose")->assertStatus(403);
    }
}
