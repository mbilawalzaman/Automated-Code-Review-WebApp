<?php

namespace App\Http\Controllers\Api\V1\Worker;

use App\Http\Controllers\Controller;
use App\Services\Api\WorkerService;
use App\Support\WorkerFindingNormalizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

class WorkerJobController extends Controller
{
    public function __construct(
        protected WorkerService $workerService
    ) {}

    /**
     * Claim the next available pending ReviewJob for processing.
     */
    public function claimNextJob(): JsonResponse
    {
        $result = $this->workerService->fetchNextJob();

        $statusCode = $result['has_job'] ? Response::HTTP_OK : Response::HTTP_NO_CONTENT;

        return response()->json($result, $statusCode);
    }

    /**
     * Submit review execution results and findings for a claimed job (single-shot).
     */
    public function submitResult(Request $request, int $job): JsonResponse
    {
        $input = $request->all();
        if (isset($input['findings']) && is_array($input['findings'])) {
            $input['findings'] = WorkerFindingNormalizer::normalizeList($input['findings']);
            $request->replace($input);
        }

        $payload = $request->validate($this->resultMetaRules(includeFindings: true));

        return $this->respondWithResult(fn () => $this->workerService->submitJobResult($job, $payload));
    }

    /**
     * Begin chunked result ingest (metadata only; no findings body).
     */
    public function beginResult(Request $request, int $job): JsonResponse
    {
        $payload = $request->validate(array_merge($this->resultMetaRules(includeFindings: false), [
            'findings_total' => ['required', 'integer', 'min:0'],
            'chunk_size' => ['nullable', 'integer', 'min:1', 'max:500'],
            'findings_metrics' => ['nullable', 'array'],
            'change_classes' => ['nullable', 'array'],
            'impact_analysis' => ['nullable', 'array'],
            'tool_executions' => ['nullable', 'array'],
        ]));

        return $this->respondWithResult(fn () => $this->workerService->beginJobResult($job, $payload));
    }

    /**
     * Append one findings chunk for an active ingest session.
     */
    public function appendResultChunk(Request $request, int $job): JsonResponse
    {
        $input = $request->all();
        if (isset($input['findings']) && is_array($input['findings'])) {
            $input['findings'] = WorkerFindingNormalizer::normalizeList($input['findings']);
            $request->replace($input);
        }

        $payload = $request->validate([
            'chunk_index' => ['required', 'integer', 'min:0'],
            'findings' => ['required', 'array'],
            'findings.*.severity' => ['required_with:findings', 'string'],
            'findings.*.file_path' => ['required_with:findings', 'string'],
            'findings.*.line_number' => ['required_with:findings', 'integer'],
            'findings.*.title' => ['required_with:findings', 'string'],
            'findings.*.evidence' => ['nullable', 'string'],
            'findings.*.recommendation' => ['nullable', 'string'],
            'findings.*.rule_id' => ['nullable', 'string'],
            'findings.*.source' => ['nullable', 'string'],
            'findings.*.is_blocking' => ['nullable', 'boolean'],
            'findings.*.objective_id' => ['nullable', 'integer'],
            'findings.*.objective_impact' => ['nullable', 'string'],
            'findings.*.fingerprint' => ['nullable', 'string', 'max:64'],
        ]);

        return $this->respondWithResult(
            fn () => $this->workerService->appendJobResultChunk(
                $job,
                (int) $payload['chunk_index'],
                $payload['findings']
            )
        );
    }

    /**
     * Finalize a chunked ingest session.
     */
    public function completeResult(int $job): JsonResponse
    {
        return $this->respondWithResult(fn () => $this->workerService->completeJobResult($job));
    }

    /**
     * @return array<string, mixed>
     */
    protected function resultMetaRules(bool $includeFindings): array
    {
        $rules = [
            'status' => ['required', 'string'],
            'pull_request_title' => ['nullable', 'string'],
            'author' => ['nullable', 'string'],
            'summary' => ['required', 'string'],
            'tools_used' => ['nullable', 'array'],
            'github_check_run_id' => ['nullable', 'string'],
            'error_message' => ['nullable', 'string'],
            'objectives_evaluated' => ['nullable', 'integer'],
            'stacks' => ['nullable', 'array'],
            'stacks.*' => ['string'],
            'test_summary' => ['nullable', 'array'],
            'coverage_percentage' => ['nullable', 'numeric'],
            'changed_code_coverage' => ['nullable', 'numeric'],
            'execution_authorized' => ['nullable', 'boolean'],
            'change_classes' => ['nullable', 'array'],
            'impact_analysis' => ['nullable', 'array'],
            'tool_executions' => ['nullable', 'array'],
            'findings_metrics' => ['nullable', 'array'],
        ];

        if ($includeFindings) {
            $rules = array_merge($rules, [
                'findings' => ['nullable', 'array'],
                'findings.*.severity' => ['required_with:findings', 'string'],
                'findings.*.file_path' => ['required_with:findings', 'string'],
                'findings.*.line_number' => ['required_with:findings', 'integer'],
                'findings.*.title' => ['required_with:findings', 'string'],
                'findings.*.evidence' => ['nullable', 'string'],
                'findings.*.recommendation' => ['nullable', 'string'],
                'findings.*.rule_id' => ['nullable', 'string'],
                'findings.*.source' => ['nullable', 'string'],
                'findings.*.is_blocking' => ['nullable', 'boolean'],
                'findings.*.objective_id' => ['nullable', 'integer'],
                'findings.*.objective_impact' => ['nullable', 'string'],
                'findings.*.fingerprint' => ['nullable', 'string', 'max:64'],
            ]);
        }

        return $rules;
    }

    /**
     * @param  callable(): array<string, mixed>  $callback
     */
    protected function respondWithResult(callable $callback): JsonResponse
    {
        try {
            $result = $callback();

            return response()->json($result, Response::HTTP_OK);
        } catch (ConflictHttpException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], Response::HTTP_CONFLICT);
        } catch (NotFoundHttpException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], Response::HTTP_NOT_FOUND);
        } catch (UnprocessableEntityHttpException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
    }
}
