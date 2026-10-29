<?php

namespace Tests\Feature\Api;

use App\Enums\RepositoryStatus;
use App\Models\Branch;
use App\Models\Project;
use App\Models\Repository;
use App\Services\Api\GitHubBranchProtectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GitHubBranchProtectionTest extends TestCase
{
    use RefreshDatabase;

    protected Repository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'github.token' => 'ghp_fake_test_token_12345',
            'github.check_run_name' => 'Automated Code Review',
            'github.protected_branches' => ['main', 'master', 'staging'],
        ]);

        $project = Project::factory()->create();
        $this->repository = Repository::factory()->create([
            'project_id' => $project->id,
            'full_name' => 'acme/payments-api',
            'default_branch' => 'main',
            'status' => RepositoryStatus::Active,
        ]);

        Branch::query()->create([
            'repository_id' => $this->repository->id,
            'name' => 'main',
            'is_default' => true,
            'is_protected' => false,
        ]);
        Branch::query()->create([
            'repository_id' => $this->repository->id,
            'name' => 'staging',
            'is_default' => false,
            'is_protected' => false,
        ]);
        Branch::query()->create([
            'repository_id' => $this->repository->id,
            'name' => 'feature/x',
            'is_default' => false,
            'is_protected' => false,
        ]);
    }

    public function test_preview_lists_main_and_staging_with_required_check(): void
    {
        $preview = app(GitHubBranchProtectionService::class)->preview($this->repository);

        $this->assertEqualsCanonicalizing(['main', 'staging'], $preview['branches']);
        $this->assertTrue($preview['require_pull_request']);
        $this->assertTrue($preview['block_direct_push']);
        $this->assertContains('Automated Code Review', $preview['required_status_checks']);
        $this->assertFalse($preview['allow_force_pushes']);
    }

    public function test_apply_puts_protection_and_merges_existing_status_checks(): void
    {
        Http::fake([
            'https://api.github.com/repos/acme/payments-api/branches/main' => Http::response(['name' => 'main'], 200),
            'https://api.github.com/repos/acme/payments-api/branches/staging' => Http::response(['name' => 'staging'], 200),
            'https://api.github.com/repos/acme/payments-api/branches/main/protection' => Http::sequence()
                ->push([
                    'required_status_checks' => [
                        'strict' => true,
                        'contexts' => ['ci/travis'],
                        'checks' => [['context' => 'ci/travis']],
                    ],
                    'enforce_admins' => ['enabled' => false],
                    'required_pull_request_reviews' => [
                        'required_approving_review_count' => 2,
                        'dismiss_stale_reviews' => false,
                        'require_code_owner_reviews' => true,
                        'require_last_push_approval' => true,
                        'dismissal_restrictions' => [
                            'users' => [['login' => 'octocat']],
                            'teams' => [['slug' => 'justice-league']],
                        ],
                        'bypass_pull_request_allowances' => [
                            'users' => [['login' => 'bot-user']],
                            'teams' => [],
                        ],
                    ],
                    'restrictions' => [
                        'users' => [['login' => 'releaser']],
                        'teams' => [['slug' => 'release-team']],
                        'apps' => [['slug' => 'deploy-app']],
                    ],
                    'required_linear_history' => ['enabled' => true],
                    'required_conversation_resolution' => ['enabled' => true],
                    'allow_force_pushes' => ['enabled' => true],
                    'allow_deletions' => ['enabled' => true],
                ], 200)
                ->push(['url' => 'https://api.github.com/repos/acme/payments-api/branches/main/protection'], 200),
            'https://api.github.com/repos/acme/payments-api/branches/staging/protection' => Http::sequence()
                ->push(['message' => 'Branch not protected'], 404)
                ->push(['url' => 'https://api.github.com/repos/acme/payments-api/branches/staging/protection'], 200),
        ]);

        $result = app(GitHubBranchProtectionService::class)->applyForRepository($this->repository);

        $this->assertEqualsCanonicalizing(['main', 'staging'], $result['applied']);
        $this->assertSame([], $result['skipped']);

        Http::assertSent(function ($request) {
            if ($request->method() !== 'PUT' || ! str_contains($request->url(), '/branches/main/protection')) {
                return false;
            }

            $data = $request->data();

            return collect($data['required_status_checks']['checks'] ?? [])
                    ->pluck('context')
                    ->contains('ci/travis')
                && collect($data['required_status_checks']['checks'] ?? [])
                    ->pluck('context')
                    ->contains('Automated Code Review')
                && ! array_key_exists('contexts', $data['required_status_checks'] ?? [])
                && ($data['required_pull_request_reviews']['required_approving_review_count'] ?? 0) === 2
                && ($data['required_pull_request_reviews']['require_code_owner_reviews'] ?? false) === true
                && ($data['required_pull_request_reviews']['dismissal_restrictions']['users'] ?? []) === ['octocat']
                && ($data['required_pull_request_reviews']['bypass_pull_request_allowances']['users'] ?? []) === ['bot-user']
                && ($data['restrictions']['users'] ?? []) === ['releaser']
                && ($data['restrictions']['apps'] ?? []) === ['deploy-app']
                && ($data['required_linear_history'] ?? false) === true
                && ($data['required_conversation_resolution'] ?? false) === true
                && ($data['allow_force_pushes'] ?? true) === false
                && ($data['allow_deletions'] ?? true) === false;
        });

        $this->assertDatabaseHas('branches', [
            'repository_id' => $this->repository->id,
            'name' => 'main',
            'is_protected' => true,
        ]);
        $this->assertDatabaseHas('branches', [
            'repository_id' => $this->repository->id,
            'name' => 'staging',
            'is_protected' => true,
        ]);
    }

    public function test_second_apply_is_idempotent_and_skips_put_when_compliant(): void
    {
        Http::fake([
            'https://api.github.com/repos/acme/payments-api/branches/main' => Http::response(['name' => 'main'], 200),
            'https://api.github.com/repos/acme/payments-api/branches/staging' => Http::response(['message' => 'Not Found'], 404),
            'https://api.github.com/repos/acme/payments-api/branches/main/protection' => Http::response([
                'required_status_checks' => [
                    'strict' => true,
                    'contexts' => ['ci/travis', 'Automated Code Review'],
                    'checks' => [
                        ['context' => 'ci/travis'],
                        ['context' => 'Automated Code Review'],
                    ],
                ],
                'required_pull_request_reviews' => [
                    'required_approving_review_count' => 1,
                ],
                'allow_force_pushes' => ['enabled' => false],
                'allow_deletions' => ['enabled' => false],
            ], 200),
        ]);

        $result = app(GitHubBranchProtectionService::class)->applyForRepository($this->repository);

        $this->assertSame(['main'], $result['applied']);
        Http::assertNotSent(fn ($request) => $request->method() === 'PUT');
    }

    public function test_missing_administration_permission_is_logged_clearly(): void
    {
        Http::fake([
            'https://api.github.com/repos/acme/payments-api/branches/main' => Http::response(['name' => 'main'], 200),
            'https://api.github.com/repos/acme/payments-api/branches/staging' => Http::response(['message' => 'Not Found'], 404),
            'https://api.github.com/repos/acme/payments-api/branches/main/protection' => Http::sequence()
                ->push(['message' => 'Branch not protected'], 404)
                ->push(['message' => 'Resource not accessible by integration'], 403),
        ]);

        \Illuminate\Support\Facades\Log::spy();

        $result = app(GitHubBranchProtectionService::class)->applyForRepository($this->repository);

        $this->assertSame([], $result['applied']);
        $this->assertSame('missing_administration_permission', $result['skipped'][0]['reason']);

        \Illuminate\Support\Facades\Log::shouldHaveReceived('warning')
            ->withArgs(fn ($message) => str_contains((string) $message, 'missing Administration permission'))
            ->atLeast()
            ->once();
    }

    public function test_apply_skips_missing_github_branch(): void
    {
        Http::fake([
            'https://api.github.com/repos/acme/payments-api/branches/main' => Http::response(['name' => 'main'], 200),
            'https://api.github.com/repos/acme/payments-api/branches/staging' => Http::response(['message' => 'Not Found'], 404),
            'https://api.github.com/repos/acme/payments-api/branches/main/protection' => Http::sequence()
                ->push(['message' => 'Branch not protected'], 404)
                ->push(['url' => 'ok'], 200),
        ]);

        $result = app(GitHubBranchProtectionService::class)->applyForRepository($this->repository);

        $this->assertSame(['main'], $result['applied']);
        $this->assertCount(1, $result['skipped']);
        $this->assertSame('staging', $result['skipped'][0]['branch']);
        $this->assertSame('branch_not_found', $result['skipped'][0]['reason']);
    }

    public function test_apply_skips_when_github_credentials_missing(): void
    {
        config(['github.token' => null, 'github.app_id' => null]);

        Http::fake();

        $result = app(GitHubBranchProtectionService::class)->applyForRepository($this->repository);

        $this->assertSame([], $result['applied']);
        $this->assertNotEmpty($result['skipped']);
        Http::assertNothingSent();
    }
}
