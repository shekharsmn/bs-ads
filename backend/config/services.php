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

    'gemini' => [
        'api_key' => env('GEMINI_API_KEY'),
    ],

    'ad_library' => [
        'enabled' => env('AD_LIBRARY_ENABLED', false),
        'url' => env('AD_LIBRARY_SCRAPER_URL', 'http://127.0.0.1:8090'),
        'token' => env('AD_LIBRARY_SCRAPER_TOKEN'),
        'connect_timeout' => (int) env('AD_LIBRARY_CONNECT_TIMEOUT', 5),
        'read_timeout' => (int) env('AD_LIBRARY_READ_TIMEOUT', 780),
        'max_response_bytes' => (int) env('AD_LIBRARY_MAX_RESPONSE_BYTES', 26214400),
    ],

    'google_workspace' => [
        'client_id' => env('GOOGLE_WORKSPACE_CLIENT_ID', env('GOOGLE_OAUTH_CLIENT_ID')),
        'client_secret' => env('GOOGLE_WORKSPACE_CLIENT_SECRET', env('GOOGLE_OAUTH_CLIENT_SECRET')),
        'redirect_uri' => env('GOOGLE_WORKSPACE_REDIRECT_URI', 'http://localhost:5173/api/auth/google/callback'),
        'frontend_url' => env('FRONTEND_URL', 'http://localhost:5173'),
        'allowed_domains' => array_values(array_filter(array_map(
            static fn (string $domain): string => strtolower(trim($domain)),
            explode(',', env('GOOGLE_WORKSPACE_DOMAINS', env('GOOGLE_WORKSPACE_DOMAIN', 'brandshark.com')))
        ))),
    ],

];
