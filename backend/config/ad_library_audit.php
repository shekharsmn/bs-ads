<?php

return [
    'enabled' => env('AD_LIBRARY_AUDITS_ENABLED', false),
    'max_creatives' => (int) env('AD_LIBRARY_AUDIT_MAX_CREATIVES', 50),
    'providers' => [
        'gemini' => ['api_key' => env('GEMINI_API_KEY'), 'base_url' => env('GEMINI_API_BASE', 'https://generativelanguage.googleapis.com/v1beta')],
        'openai' => ['api_key' => env('OPENAI_API_KEY'), 'base_url' => env('OPENAI_API_BASE', 'https://api.openai.com/v1')],
        'anthropic' => ['api_key' => env('ANTHROPIC_API_KEY'), 'base_url' => env('ANTHROPIC_API_BASE', 'https://api.anthropic.com/v1')],
    ],
    'crawl' => [
        'max_pages' => 6,
        'max_bytes' => 1_000_000,
        'timeout' => 10,
        'user_agent' => env('AD_LIBRARY_AUDIT_CRAWL_USER_AGENT', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36'),
    ],
    'media' => ['max_image_bytes' => 10_000_000, 'max_video_bytes' => 50_000_000, 'frames' => 6],
];
