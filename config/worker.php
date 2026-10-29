<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Expected Worker Nodes (OPS-001)
    |--------------------------------------------------------------------------
    |
    | Comma-separated worker IDs that are expected to be enrolled and polling
    | (the WORKER_ID configured on each Mac mini). Workers listed here are
    | surfaced on /admin/health as "Expected (Never Connected)" until their
    | first heartbeat arrives, and are eligible for outage alerts.
    |
    | Leave empty to only monitor workers that have connected at least once.
    | Example: EXPECTED_WORKER_IDS=mac-mini-primary,mac-mini-secondary
    |
    */

    'expected_ids' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('EXPECTED_WORKER_IDS', ''))
    ))),

    /*
    |--------------------------------------------------------------------------
    | Liveness & Sweeper Thresholds
    |--------------------------------------------------------------------------
    |
    | offline_after_minutes: heartbeat age after which a worker is offline.
    | stuck_job_after_minutes: time in processing/running before a review job
    | is swept and finalized as a terminal timeout.
    | outage_alert_retry_minutes: cooldown before retrying a failed alert email.
    | scheduler_stale_after_minutes: age of the scheduler heartbeat signal
    | after which the crontab is presumed to be missing or stalled.
    |
    */

    'offline_after_minutes' => (int) env('WORKER_OFFLINE_AFTER_MINUTES', 15),

    'stuck_job_after_minutes' => (int) env('REVIEW_JOB_STUCK_AFTER_MINUTES', 30),

    'outage_alert_retry_minutes' => (int) env('WORKER_OUTAGE_ALERT_RETRY_MINUTES', 15),

    'scheduler_stale_after_minutes' => (int) env('SCHEDULER_STALE_AFTER_MINUTES', 3),

    /*
    |--------------------------------------------------------------------------
    | Test Execution Trust Gate (WF-007)
    |--------------------------------------------------------------------------
    |
    | Static scanners always run. Repository test suites and Playwright only run
    | when execution is authorized via trusted authors, PM-triggered jobs, or an
    | explicit payload flag.
    |
    */

    /*
    | Trusted GitHub authors for WF-007 test execution. Prefer per-repository
    | review_rules.trusted_authors (Admin → Repositories → Trusted authors).
    | This env list remains an optional organisation-wide fallback.
    */
    'trusted_authors' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('TRUSTED_REVIEW_AUTHORS', ''))
    ))),

    'authorize_pm_triggered_jobs' => filter_var(
        env('AUTHORIZE_PM_TRIGGERED_JOBS', true),
        FILTER_VALIDATE_BOOL
    ),

    'execution_authorized_triggers' => (string) env(
        'EXECUTION_AUTHORIZED_TRIGGERS',
        'manual_review_now,baseline_activation'
    ),

    /*
    |--------------------------------------------------------------------------
    | Coverage Gate (TST-004)
    |--------------------------------------------------------------------------
    */

    'changed_coverage_min_percent' => (float) env('CHANGED_COVERAGE_MIN_PERCENT', 70),

    /*
    |--------------------------------------------------------------------------
    | Large Result Ingest
    |--------------------------------------------------------------------------
    |
    | Workers may submit findings in chunks when the payload exceeds
    | result_single_shot_max. Chunks are staged then finalized on complete.
    |
    */

    'result_chunk_size' => (int) env('WORKER_RESULT_CHUNK_SIZE', 100),

    'result_single_shot_max' => (int) env('WORKER_RESULT_SINGLE_SHOT_MAX', 75),

    /*
    |--------------------------------------------------------------------------
    | Same-SHA Requeue Cooldown
    |--------------------------------------------------------------------------
    |
    | Prevent Review Now / force-new from immediately re-queueing the same
    | repository+commit after a recent timeout or while a job is still open.
    |
    */

    'same_sha_requeue_cooldown_minutes' => (int) env('REVIEW_SAME_SHA_COOLDOWN_MINUTES', 60),

    /*
    |--------------------------------------------------------------------------
    | Destructive Change Backup Gate (CHG-002)
    |--------------------------------------------------------------------------
    |
    | PM override of a destructive_migration review requires a backup that
    | passes BackupService::verifyBackup and was created within this window.
    |
    */

    'destructive_change_backup_max_age_hours' => (int) env('DESTRUCTIVE_CHANGE_BACKUP_MAX_AGE_HOURS', 24),

];
