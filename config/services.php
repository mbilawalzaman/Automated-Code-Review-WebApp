<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'trello' => [
        'api_base' => rtrim((string) env('TRELLO_API_BASE', 'https://api.trello.com/1'), '/'),
        'key' => env('TRELLO_KEY', env('TRELLO_API_KEY')),
        'token' => env('TRELLO_TOKEN', env('TRELLO_API_TOKEN')),
    ],

    'worker' => [
        'token' => env('WORKER_API_TOKEN'),
    ],

    /*
    | Google Drive access for SRS documents (SRS-001). Restricted to a single
    | dedicated project-documents folder per SEC-005: files outside that folder
    | are refused even if the service account could otherwise read them.
    */
    'google_drive' => [
        'api_base' => rtrim((string) env('GOOGLE_DRIVE_API_BASE', 'https://www.googleapis.com/drive/v3'), '/'),
        'token_endpoint' => rtrim((string) env('GOOGLE_DRIVE_TOKEN_ENDPOINT', 'https://oauth2.googleapis.com/token'), '/'),
        'web_base' => rtrim((string) env('GOOGLE_DRIVE_WEB_BASE', 'https://drive.google.com'), '/'),
        'scope' => env('GOOGLE_DRIVE_SCOPE', 'https://www.googleapis.com/auth/drive.readonly'),
        'service_account' => env('GOOGLE_DRIVE_SERVICE_ACCOUNT_JSON'),
        'folder_id' => env('GOOGLE_DRIVE_FOLDER_ID'),
        'subject' => env('GOOGLE_DRIVE_IMPERSONATE_EMAIL'),
    ],

    /*
    | Comma-separated recipients for Critical review email alerts (NOT-001).
    | Example: pm@company.com,security@company.com
    | Mail transport is configured via MAIL_* (Laravel Mail / SMTP).
    */
    'alerts' => [
        'critical_emails' => env('CRITICAL_ALERT_EMAILS', ''),
    ],

];
