<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Api\GitHubWebhookService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class GitHubWebhookController extends Controller
{
    public function __construct(
        protected GitHubWebhookService $webhookService
    ) {}

    /**
     * Handle incoming GitHub webhook.
     */
    public function __invoke(Request $request): JsonResponse
    {
        $event = $request->header('X-GitHub-Event');

        if (! $event) {
            return response()->json([
                'error' => 'Missing X-GitHub-Event header',
            ], Response::HTTP_BAD_REQUEST);
        }

        $payload = $request->json()->all();
        $result = $this->webhookService->handleWebhook($event, $payload);

        $statusCode = $result['handled'] ? Response::HTTP_OK : Response::HTTP_ACCEPTED;

        return response()->json($result, $statusCode);
    }
}
