<?php

namespace App\Services\Api;

use App\Models\ReviewJob;

/**
 * Decide whether the Mac worker may execute repository tests (WF-007 / AC-009).
 *
 * Static analysis always runs. Test/Playwright execution requires authorization.
 */
class ExecutionAuthorizationService
{
    /**
     * @return array{authorized: bool, reason: string}
     */
    public function forJob(ReviewJob $job): array
    {
        $payload = is_array($job->payload) ? $job->payload : [];

        if (array_key_exists('execution_authorized', $payload)) {
            $authorized = (bool) $payload['execution_authorized'];

            return [
                'authorized' => $authorized,
                'reason' => $authorized
                    ? (string) ($payload['execution_authorization_reason'] ?? 'payload_flag')
                    : 'payload_denied',
            ];
        }

        $trigger = (string) $job->trigger_type;
        $pmTriggers = array_filter(array_map(
            'trim',
            explode(',', (string) config('worker.execution_authorized_triggers', 'manual_review_now,baseline_activation'))
        ));

        if (in_array($trigger, $pmTriggers, true) && (bool) config('worker.authorize_pm_triggered_jobs', true)) {
            return [
                'authorized' => true,
                'reason' => 'pm_triggered_'.$trigger,
            ];
        }

        $author = strtolower(trim((string) ($payload['author'] ?? '')));
        $trusted = array_values(array_filter(array_map(
            static fn ($value) => strtolower(trim($value)),
            (array) config('worker.trusted_authors', [])
        )));

        if ($author !== '' && in_array($author, $trusted, true)) {
            return [
                'authorized' => true,
                'reason' => 'trusted_author',
            ];
        }

        $repository = $job->repository;
        $rules = is_array($repository?->review_rules) ? $repository->review_rules : [];
        $repoAuthors = array_values(array_filter(array_map(
            static fn ($value) => strtolower(trim((string) $value)),
            (array) ($rules['trusted_authors'] ?? [])
        )));

        if ($author !== '' && in_array($author, $repoAuthors, true)) {
            return [
                'authorized' => true,
                'reason' => 'repository_trusted_author',
            ];
        }

        if (! empty($rules['always_authorize_execution'])) {
            return [
                'authorized' => true,
                'reason' => 'repository_policy',
            ];
        }

        return [
            'authorized' => false,
            'reason' => 'static_first_untrusted',
        ];
    }
}
