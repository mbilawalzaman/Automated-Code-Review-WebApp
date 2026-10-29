<?php

namespace App\Services\Api;

use App\Models\Repository;
use App\Models\Review;
use App\Services\Integrations\IntegrationCredentialService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TrelloService
{
    public function __construct(
        protected TrelloCardFormatter $formatter
    ) {}

    /**
     * Sync review execution results with Trello (TRL-001 to TRL-006).
     */
    public function syncReviewToTrello(Repository $repository, Review $review, array $findings = []): array
    {
        $cred = $this->getTrelloCredentials();
        if (! $cred || ! $repository->trello_board_id) {
            Log::info("Skipping Trello sync: Trello credentials or repository trello_board_id not configured.");

            return ['success' => false, 'trello_card_id' => null, 'trello_comment_id' => null];
        }

        $boardId = $repository->trello_board_id;
        $listId = $repository->trello_list_id;

        // Normalize status to plain string — handles backed enum (from model cast) and raw string safely.
        $reviewStatusStr = $review->status instanceof \UnitEnum
            ? $review->status->value
            : (string) $review->status;

        $reviewJob = $review->reviewJob;
        $prNumber = $review->pull_request_number;
        $prTitle = $review->pull_request_title;
        $branchName = $review->branch?->name;
        $commitSha = $review->commit_sha;
        $commitMessage = is_array($reviewJob?->payload)
            ? ($reviewJob->payload['commit_message'] ?? '')
            : '';

        $reportUrl = config('app.url') . '/admin/reports';

        // 1. Check database persistence first
        $cardId = $review->trello_card_id ?? $reviewJob?->trello_card_id;
        $commentId = $review->trello_comment_id ?? $reviewJob?->trello_comment_id;

        // 2. If no persisted card ID, orchestrate modular card matchers (TRL-002)
        if (! $cardId) {
            $cardId = $this->findMatchingCard($boardId, $commitMessage, $prTitle, $prNumber, $branchName, $commitSha);
        }

        // 3. If no card matched, create a new card on default list (TRL-003)
        if (! $cardId && $listId) {
            $cardTitle = $this->formatter->formatTitle($reviewStatusStr, $prTitle, $prNumber, $branchName);
            $cardDesc = "Automated code review card for repository {$repository->full_name}.";

            $createdCard = $this->createCard($listId, $cardTitle, $cardDesc);
            $cardId = $createdCard['id'] ?? null;
        }

        if (! $cardId) {
            Log::warning("Unable to match or create Trello card for Review #{$review->id}");

            return ['success' => false, 'trello_card_id' => null, 'trello_comment_id' => null];
        }

        // 4. Format comment body per Client SOP
        $commentText = $this->formatter->formatComment($reviewStatusStr, $review->summary, $findings, $reportUrl);

        // 5. Post or update single consolidated comment (TRL-004, TRL-005)
        $commentResult = $this->postOrUpdateComment($cardId, $commentId, $commentText);
        $newCommentId = $commentResult['id'] ?? $commentId;

        // 6. Persist card and comment IDs on Review & ReviewJob
        $review->update([
            'trello_card_id' => $cardId,
            'trello_comment_id' => $newCommentId,
        ]);

        if ($reviewJob) {
            $reviewJob->update([
                'trello_card_id' => $cardId,
                'trello_comment_id' => $newCommentId,
            ]);
        }

        Log::info("Synced Review #{$review->id} to Trello Card #{$cardId} (Comment #{$newCommentId})");

        return [
            'success' => true,
            'trello_card_id' => $cardId,
            'trello_comment_id' => $newCommentId,
        ];
    }

    /**
     * Modular Matcher Orchestrator (TRL-002).
     */
    public function findMatchingCard(
        string $boardId,
        string $commitMessage = '',
        ?string $prTitle = null,
        ?int $prNumber = null,
        ?string $branchName = null,
        ?string $commitSha = null
    ): ?string {
        // Priority 1: Match by explicit Trello card ID or reference (e.g. card:abcdef123456, TRELLO-55, ABC-123)
        if ($cardId = $this->matchByExplicitReference($boardId, $commitMessage, $prTitle)) {
            return $cardId;
        }

        // Priority 2: Match by Pull Request Number (#15)
        if ($prNumber && ($cardId = $this->matchByPullRequest($boardId, $prNumber))) {
            return $cardId;
        }

        // Priority 3: Match by Branch Name (feature/login)
        if ($branchName && ($cardId = $this->matchByBranch($boardId, $branchName))) {
            return $cardId;
        }

        // Priority 4: Match by Commit SHA or message
        if ($commitSha && ($cardId = $this->matchByCommit($boardId, $commitSha))) {
            return $cardId;
        }

        return null;
    }

    /**
     * Priority 1: Match explicit card reference or Trello shortlink in commit message or PR title.
     */
    protected function matchByExplicitReference(string $boardId, string $commitMessage, ?string $prTitle): ?string
    {
        $text = "{$commitMessage} {$prTitle}";

        // Regex for Trello card URL: trello.com/c/abcdef12
        if (preg_match('/trello\.com\/c\/([a-zA-Z0-9_-]+)/i', $text, $matches)) {
            return $matches[1];
        }

        // Regex for card:id pattern
        if (preg_match('/card:([a-zA-Z0-9_-]+)/i', $text, $matches)) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Priority 2: Match by Pull Request number.
     */
    protected function matchByPullRequest(string $boardId, int $prNumber): ?string
    {
        return $this->searchBoardCards($boardId, "PR #{$prNumber}");
    }

    /**
     * Priority 3: Match by Branch Name.
     */
    protected function matchByBranch(string $boardId, string $branchName): ?string
    {
        return $this->searchBoardCards($boardId, $branchName);
    }

    /**
     * Priority 4: Match by Commit SHA.
     */
    protected function matchByCommit(string $boardId, string $commitSha): ?string
    {
        $shortSha = substr($commitSha, 0, 7);

        return $this->searchBoardCards($boardId, $shortSha);
    }

    /**
     * Query Trello Search API for cards on board.
     */
    protected function searchBoardCards(string $boardId, string $query): ?string
    {
        $cred = $this->getTrelloCredentials();
        if (! $cred) {
            return null;
        }

        try {
            $response = Http::timeout(10)->get(config('services.trello.api_base').'/search', [
                'key' => $cred['key'],
                'token' => $cred['token'],
                'query' => $query,
                'idBoards' => $boardId,
                'modelTypes' => 'cards',
                'card_fields' => 'id,name',
            ]);

            if ($response->successful()) {
                $cards = $response->json('cards') ?? [];
                if (! empty($cards)) {
                    return $cards[0]['id'] ?? null;
                }
            }
        } catch (\Throwable $e) {
            Log::warning("Trello search card exception: " . $e->getMessage());
        }

        return null;
    }

    /**
     * Create a new card on Trello (disables cover images per Client SOP).
     */
    public function createCard(string $listId, string $title, string $description): array
    {
        $cred = $this->getTrelloCredentials();
        if (! $cred) {
            return [];
        }

        try {
            $response = Http::timeout(10)->post(config('services.trello.api_base').'/cards', [
                'key' => $cred['key'],
                'token' => $cred['token'],
                'idList' => $listId,
                'name' => $title,
                'desc' => $description,
                'pos' => 'top',
            ]);

            if ($response->successful()) {
                return $response->json() ?? [];
            }

            Log::warning("Trello create card failed [{$response->status()}]: " . $response->body());
        } catch (\Throwable $e) {
            Log::warning("Trello create card exception: " . $e->getMessage());
        }

        return [];
    }

    /**
     * Post new comment or update existing comment in-place (protects human comments using <!-- CodeReviewAutomation --> marker).
     */
    public function postOrUpdateComment(string $cardId, ?string $commentId, string $commentText): array
    {
        $cred = $this->getTrelloCredentials();
        if (! $cred) {
            return [];
        }

        // 1. If commentId is stored, attempt direct 1-call update (PUT /1/actions/{id}/text)
        if ($commentId) {
            $updated = $this->updateCommentText($commentId, $commentText);
            if ($updated) {
                return ['id' => $commentId];
            }
        }

        // 2. Fallback: Search card action comments for <!-- CodeReviewAutomation --> marker
        $foundCommentId = $this->findAutomationCommentId($cardId);
        if ($foundCommentId) {
            $updated = $this->updateCommentText($foundCommentId, $commentText);
            if ($updated) {
                return ['id' => $foundCommentId];
            }
        }

        // 3. Post a new comment
        try {
            $response = Http::timeout(10)->post(config('services.trello.api_base')."/cards/{$cardId}/actions/comments", [
                'key' => $cred['key'],
                'token' => $cred['token'],
                'text' => $commentText,
            ]);

            if ($response->successful()) {
                return $response->json() ?? [];
            }
        } catch (\Throwable $e) {
            Log::warning("Trello post comment exception: " . $e->getMessage());
        }

        return [];
    }

    /**
     * Direct 1-call comment text update (PUT /1/actions/{commentId}/text).
     */
    protected function updateCommentText(string $commentId, string $text): bool
    {
        $cred = $this->getTrelloCredentials();
        if (! $cred) {
            return false;
        }

        try {
            $response = Http::timeout(10)->put(config('services.trello.api_base')."/actions/{$commentId}/text", [
                'key' => $cred['key'],
                'token' => $cred['token'],
                'value' => $text,
            ]);

            return $response->successful();
        } catch (\Throwable $e) {
            Log::warning("Trello update comment exception: " . $e->getMessage());
        }

        return false;
    }

    /**
     * Find existing comment containing <!-- CodeReviewAutomation --> marker.
     */
    protected function findAutomationCommentId(string $cardId): ?string
    {
        $cred = $this->getTrelloCredentials();
        if (! $cred) {
            return null;
        }

        try {
            $response = Http::timeout(10)->get(config('services.trello.api_base')."/cards/{$cardId}/actions", [
                'key' => $cred['key'],
                'token' => $cred['token'],
                'filter' => 'commentCard',
                'limit' => 20,
            ]);

            if ($response->successful()) {
                $actions = $response->json() ?? [];
                foreach ($actions as $act) {
                    $text = $act['data']['text'] ?? '';
                    if (str_contains($text, '<!-- CodeReviewAutomation -->')) {
                        return $act['id'] ?? null;
                    }
                }
            }
        } catch (\Throwable $e) {
            Log::warning("Trello find automation comment exception: " . $e->getMessage());
        }

        return null;
    }

    /**
     * Retrieve Trello API credentials from encrypted integrations store or env/config.
     */
    protected function getTrelloCredentials(): ?array
    {
        $resolved = app(IntegrationCredentialService::class)->trello();
        if (! $resolved) {
            return null;
        }

        return [
            'key' => $resolved['key'],
            'token' => $resolved['token'],
        ];
    }
}
