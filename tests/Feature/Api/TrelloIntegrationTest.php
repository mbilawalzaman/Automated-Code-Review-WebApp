<?php

namespace Tests\Feature\Api;

use App\Enums\RepositoryStatus;
use App\Enums\ReviewJobStatus;
use App\Models\Branch;
use App\Models\Project;
use App\Models\Repository;
use App\Models\ReviewJob;
use App\Services\Api\RedactionService;
use App\Services\Api\TrelloCardFormatter;
use App\Services\Api\TrelloService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TrelloIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected Repository $repository;
    protected Branch $branch;
    protected string $workerToken = 'test-worker-token';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.worker.token' => $this->workerToken,
            'services.trello.key' => 'trello_fake_key_123',
            'services.trello.token' => 'trello_fake_token_456',
        ]);

        $project = Project::factory()->create();
        $this->repository = Repository::factory()->create([
            'project_id' => $project->id,
            'name' => 'Automated-Code-Review-WebApp',
            'full_name' => 'AlwaysWinningForever/Automated-Code-Review-WebApp',
            'status' => RepositoryStatus::Active,
            'trello_board_id' => 'board_abc_123',
            'trello_list_id' => 'list_xyz_789',
        ]);
        $this->branch = Branch::query()->create([
            'repository_id' => $this->repository->id,
            'name' => 'feature/login',
            'is_default' => false,
        ]);
    }

    public function test_formats_title_case_and_single_emoji_per_client_sop(): void
    {
        $formatter = new TrelloCardFormatter(new RedactionService());

        $failedTitle = $formatter->formatTitle('failed', 'AWS secret leaked in config', 15);
        $this->assertStringStartsWith('🔴', $failedTitle);
        $this->assertStringContainsString('Security Review:', $failedTitle);  // Short SOP title format

        $passedTitle = $formatter->formatTitle('passed', 'Feature PR #12', 12);
        $this->assertStringStartsWith('📊', $passedTitle);
        $this->assertStringContainsString('Code Review:', $passedTitle);  // Short SOP title format
    }

    public function test_matches_existing_trello_card_by_explicit_reference(): void
    {
        $trelloService = app(TrelloService::class);

        $cardId = $trelloService->findMatchingCard(
            'board_abc_123',
            'Fix bug card:trello_card_9999 in auth module',
            'PR Title'
        );

        $this->assertEquals('trello_card_9999', $cardId);
    }

    public function test_creates_new_card_when_no_match_and_persists_ids(): void
    {
        Http::fake([
            'https://api.trello.com/1/search*' => Http::response(['cards' => []], 200),
            'https://api.trello.com/1/cards' => Http::response(['id' => 'new_card_555'], 201),
            'https://api.trello.com/1/cards/new_card_555/actions/comments' => Http::response(['id' => 'new_comment_777'], 201),
        ]);

        $job = ReviewJob::query()->create([
            'repository_id' => $this->repository->id,
            'branch_id' => $this->branch->id,
            'idempotency_key' => 'key-trello-create-' . uniqid(),
            'status' => ReviewJobStatus::Processing->value,
            'commit_sha' => 'abc1234567890abcdef1234567890abcdef1234',
            'pull_request_number' => 88,
            'trigger_type' => 'webhook_pull_request',
            'attempts' => 1,
        ]);

        $payload = [
            'status' => 'failed',
            'summary' => 'Critical vulnerability found.',
            'pull_request_title' => 'Feature PR #88',
            'author' => 'Developer',
            'tools_used' => ['gitleaks'],
            'findings' => [
                [
                    'title' => 'Secret Leaked',
                    'severity' => 'critical',
                    'file_path' => 'config/services.php',
                    'line_number' => 12,
                    'description' => 'AKIAIOSFODNN7EXAMPLE detected in code',
                    'remediation' => 'Move secret to environment variables',
                    'tool_name' => 'gitleaks',
                ],
            ],
        ];

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->workerToken,
            'Accept' => 'application/json',
        ])->postJson(route('worker.jobs.result', $job->id), $payload);

        $response->assertStatus(200);

        $this->assertDatabaseHas('reviews', [
            'repository_id' => $this->repository->id,
            'review_job_id' => $job->id,
            'trello_card_id' => 'new_card_555',
            'trello_comment_id' => 'new_comment_777',
        ]);
    }

    public function test_executes_direct_1_call_comment_update_when_ids_already_exist(): void
    {
        Http::fake([
            'https://api.trello.com/1/actions/existing_comment_888/text' => Http::response(['id' => 'existing_comment_888'], 200),
        ]);

        $job = ReviewJob::query()->create([
            'repository_id' => $this->repository->id,
            'branch_id' => $this->branch->id,
            'idempotency_key' => 'key-trello-update-' . uniqid(),
            'status' => ReviewJobStatus::Processing->value,
            'commit_sha' => 'abc1234567890abcdef1234567890abcdef1234',
            'pull_request_number' => 99,
            'trello_card_id' => 'existing_card_111',
            'trello_comment_id' => 'existing_comment_888',
            'trigger_type' => 'webhook_pull_request',
            'attempts' => 1,
        ]);

        $payload = [
            'status' => 'passed',
            'summary' => 'All fixes verified cleanly.',
            'pull_request_title' => 'Feature PR #99',
            'author' => 'Developer',
            'tools_used' => ['semgrep'],
            'findings' => [],
        ];

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->workerToken,
            'Accept' => 'application/json',
        ])->postJson(route('worker.jobs.result', $job->id), $payload);

        $response->assertStatus(200);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/actions/existing_comment_888/text') &&
                   $request->method() === 'PUT';
        });
    }

    public function test_graceful_fault_isolation_when_trello_api_fails(): void
    {
        Http::fake([
            'https://api.trello.com/*' => Http::response(['message' => 'Trello API Error'], 500),
        ]);

        $job = ReviewJob::query()->create([
            'repository_id' => $this->repository->id,
            'branch_id' => $this->branch->id,
            'idempotency_key' => 'key-trello-fail-' . uniqid(),
            'status' => ReviewJobStatus::Processing->value,
            'commit_sha' => 'abc1234567890abcdef1234567890abcdef1234',
            'pull_request_number' => 100,
            'trigger_type' => 'webhook_pull_request',
            'attempts' => 1,
        ]);

        $payload = [
            'status' => 'passed',
            'summary' => 'Review completed cleanly.',
            'pull_request_title' => 'Feature PR #100',
            'author' => 'Developer',
            'tools_used' => ['semgrep'],
            'findings' => [],
        ];

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->workerToken,
            'Accept' => 'application/json',
        ])->postJson(route('worker.jobs.result', $job->id), $payload);

        $response->assertStatus(200);

        $this->assertDatabaseHas('reviews', [
            'repository_id' => $this->repository->id,
            'review_job_id' => $job->id,
            'status' => 'passed',
        ]);
    }
}
