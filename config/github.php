<?php

return [

    /*
    |--------------------------------------------------------------------------
    | GitHub Integration
    |--------------------------------------------------------------------------
    |
    | Webhook verification, App/PAT auth, Check Runs, and branch protection.
    | Read these via config('github.*') — do not call env() outside this file.
    |
    */

    'webhook_secret' => env('GITHUB_WEBHOOK_SECRET'),

    /*
    | REST API host (override for GitHub Enterprise Server, e.g. https://github.example.com/api/v3).
    */
    'api_base' => rtrim((string) env('GITHUB_API_BASE', 'https://api.github.com'), '/'),

    /*
    | Public web host used for clone/html links (override for GHE).
    */
    'web_base' => rtrim((string) env('GITHUB_WEB_BASE', 'https://github.com'), '/'),

    'token' => env('GITHUB_TOKEN'),

    'app_id' => env('GITHUB_APP_ID'),

    'private_key' => env('GITHUB_APP_PRIVATE_KEY'),

    'installation_id' => env('GITHUB_APP_INSTALLATION_ID'),

    /*
    | Must match the Check Run name created by GitHubCheckRunService (WF-004).
    */
    'check_run_name' => env('GITHUB_CHECK_RUN_NAME', 'Automated Code Review'),

    /*
    | Branches protected on activate / Protect branches (comma-separated in .env).
    */
    'protected_branches' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('GITHUB_PROTECTED_BRANCHES', 'main,master,staging'))
    ))),

];
