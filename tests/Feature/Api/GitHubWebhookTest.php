<?php

namespace Tests\Feature\Api;

use App\Enums\RepositoryStatus;
use App\Enums\ReviewJobStatus;
use App\Models\Project;
use App\Models\Repository;
use App\Models\ReviewJob;

use Tests\TestCase;

class GitHubWebhookTest extends TestCase
{

    protected Repository $repository;

    protected string $secret;

    protected function setUp(): void
    {
        parent::setUp();

        $this->secret = (string) config('github.webhook_secret');

        $project = Project::create([
            'name' => 'GitHub Test Project',
            'slug' => 'github-test-project',
            'description' => 'Test project for GitHub webhook integration',
        ]);

        $this->repository = Repository::create([
            'project_id' => $project->id,
            'name' => 'Automated-Code-Review-WebApp',
            'full_name' => 'AlwaysWinningForever/Automated-Code-Review-WebApp',
            'url' => 'https://github.com/AlwaysWinningForever/Automated-Code-Review-WebApp',
            'default_branch' => 'main',
            'status' => RepositoryStatus::Active->value,
        ]);
    }

    public function test_push_event_with_valid_signature_creates_review_job(): void
    {
        $payload = [
            'ref' => 'refs/heads/main',
            'after' => '97ebf4a1234567890abcdef1234567890abcdef1',
            'repository' => [
                'full_name' => 'AlwaysWinningForever/Automated-Code-Review-WebApp',
                'html_url' => 'https://github.com/AlwaysWinningForever/Automated-Code-Review-WebApp',
            ],
            'pusher' => [
                'name' => 'mbilawalzaman',
            ],
        ];

        $jsonPayload = json_encode($payload);
        $signature = 'sha256=' . hash_hmac('sha256', $jsonPayload, $this->secret);

        $response = $this->call(
            'POST',
            '/api/v1/github/webhook',
            [],
            [],
            [],
            [
                'HTTP_X_HUB_SIGNATURE_256' => $signature,
                'HTTP_X_GITHUB_EVENT' => 'push',
                'CONTENT_TYPE' => 'application/json',
            ],
            $jsonPayload
        );

        $response->assertOk()
            ->assertJson([
                'handled' => true,
                'message' => 'Review job successfully queued',
            ]);

        $this->assertDatabaseHas('review_jobs', [
            'repository_id' => $this->repository->id,
            'trigger_type' => 'webhook_push',
            'commit_sha' => '97ebf4a1234567890abcdef1234567890abcdef1',
            'status' => ReviewJobStatus::Pending->value,
        ]);
    }

    public function test_pull_request_event_with_valid_signature_creates_review_job(): void
    {
        $payload = [
            'action' => 'opened',
            'number' => 42,
            'pull_request' => [
                'head' => [
                    'sha' => '0445c92abcdef1234567890abcdef1234567890',
                    'ref' => 'feature/github-integration',
                ],
                'user' => [
                    'login' => 'testdeveloper',
                ],
            ],
            'repository' => [
                'full_name' => 'AlwaysWinningForever/Automated-Code-Review-WebApp',
                'html_url' => 'https://github.com/AlwaysWinningForever/Automated-Code-Review-WebApp',
            ],
        ];

        $jsonPayload = json_encode($payload);
        $signature = 'sha256=' . hash_hmac('sha256', $jsonPayload, $this->secret);

        $response = $this->call(
            'POST',
            '/api/v1/github/webhook',
            [],
            [],
            [],
            [
                'HTTP_X_HUB_SIGNATURE_256' => $signature,
                'HTTP_X_GITHUB_EVENT' => 'pull_request',
                'CONTENT_TYPE' => 'application/json',
            ],
            $jsonPayload
        );

        $response->assertOk()
            ->assertJson([
                'handled' => true,
            ]);

        $this->assertDatabaseHas('review_jobs', [
            'repository_id' => $this->repository->id,
            'trigger_type' => 'webhook_pull_request',
            'commit_sha' => '0445c92abcdef1234567890abcdef1234567890',
            'pull_request_number' => 42,
            'status' => ReviewJobStatus::Pending->value,
        ]);
    }

    public function test_missing_signature_header_returns_unauthorized(): void
    {
        $response = $this->postJson('/api/v1/github/webhook', ['test' => 'data'], [
            'X-GitHub-Event' => 'push',
        ]);

        $response->assertUnauthorized()
            ->assertJson([
                'error' => 'Missing X-Hub-Signature-256 header',
            ]);
    }

    public function test_invalid_signature_returns_unauthorized(): void
    {
        $response = $this->call(
            'POST',
            '/api/v1/github/webhook',
            [],
            [],
            [],
            [
                'HTTP_X_HUB_SIGNATURE_256' => 'sha256=invalid_signature_hash',
                'HTTP_X_GITHUB_EVENT' => 'push',
                'CONTENT_TYPE' => 'application/json',
            ],
            json_encode(['test' => 'data'])
        );

        $response->assertUnauthorized()
            ->assertJson([
                'error' => 'Invalid webhook signature',
            ]);
    }

    public function test_inactive_repository_webhook_returns_accepted_with_message(): void
    {
        $this->repository->update(['status' => RepositoryStatus::Disconnected->value]);

        $payload = [
            'ref' => 'refs/heads/main',
            'after' => '97ebf4a1234567890abcdef1234567890abcdef1',
            'repository' => [
                'full_name' => 'AlwaysWinningForever/Automated-Code-Review-WebApp',
                'html_url' => 'https://github.com/AlwaysWinningForever/Automated-Code-Review-WebApp',
            ],
        ];

        $jsonPayload = json_encode($payload);
        $signature = 'sha256=' . hash_hmac('sha256', $jsonPayload, $this->secret);

        $response = $this->call(
            'POST',
            '/api/v1/github/webhook',
            [],
            [],
            [],
            [
                'HTTP_X_HUB_SIGNATURE_256' => $signature,
                'HTTP_X_GITHUB_EVENT' => 'push',
                'CONTENT_TYPE' => 'application/json',
            ],
            $jsonPayload
        );

        $response->assertStatus(202)
            ->assertJson([
                'handled' => false,
                'message' => 'Repository is currently inactive',
            ]);

        $this->assertEquals(0, ReviewJob::count());
    }

    public function test_unknown_repository_webhook_does_not_fallback_to_another_repo(): void
    {
        $otherProject = Project::create([
            'name' => 'Other Project',
            'slug' => 'other-project',
            'description' => 'Should not receive this webhook',
        ]);

        Repository::create([
            'project_id' => $otherProject->id,
            'name' => 'other-repo',
            'full_name' => 'SomeOrg/other-repo',
            'default_branch' => 'main',
            'status' => RepositoryStatus::Active->value,
        ]);

        $payload = [
            'ref' => 'refs/heads/main',
            'after' => 'deadbeef1234567890abcdef1234567890abcdef',
            'repository' => [
                'full_name' => 'UnknownOrg/does-not-exist',
                'html_url' => 'https://github.com/UnknownOrg/does-not-exist',
            ],
            'pusher' => [
                'name' => 'outsider',
            ],
        ];

        $jsonPayload = json_encode($payload);
        $signature = 'sha256=' . hash_hmac('sha256', $jsonPayload, $this->secret);

        $response = $this->call(
            'POST',
            '/api/v1/github/webhook',
            [],
            [],
            [],
            [
                'HTTP_X_HUB_SIGNATURE_256' => $signature,
                'HTTP_X_GITHUB_EVENT' => 'push',
                'CONTENT_TYPE' => 'application/json',
            ],
            $jsonPayload
        );

        $response->assertStatus(202)
            ->assertJson([
                'handled' => false,
                'message' => 'No matching registered repository found',
            ]);

        $this->assertEquals(0, ReviewJob::count());
    }
}
