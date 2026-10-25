<?php

return [
    'queues' => [
        'notifications' => env('QUEUE_NOTIFICATIONS', 'notifications'),
        'collection' => env('QUEUE_COLLECTION', 'collection'),
        'reports' => env('QUEUE_REPORTS', 'reports'),
        'audits' => env('QUEUE_AUDITS', 'audits'),
        'ad_library' => env('QUEUE_AD_LIBRARY', 'ad-library'),
        'ad_library_analysis' => env('QUEUE_AD_LIBRARY_ANALYSIS', 'ad-library-analysis'),
        'default' => env('QUEUE_DEFAULT', 'default'),
    ],
    'policies' => [
        'notifications' => ['timeout' => 30, 'tries' => 3, 'backoff' => [30, 120, 600]],
        'collection' => ['timeout' => 300, 'tries' => 1, 'backoff' => 60],
        'reports' => ['timeout' => 120, 'tries' => 3, 'backoff' => [30, 120]],
        'audits' => ['timeout' => 600, 'tries' => 3, 'backoff' => [60, 180]],
        'ad_library' => ['timeout' => 900, 'tries' => 2, 'backoff' => 60],
        'ad_library_analysis' => ['timeout' => 900, 'tries' => 3, 'backoff' => [60, 180]],
    ],
    'heartbeat_ttl_seconds' => (int) env('QUEUE_HEARTBEAT_TTL_SECONDS', 180),
    'attempt_stale_minutes' => (int) env('WORKFLOW_ATTEMPT_STALE_MINUTES', 20),
    'heartbeats_required' => (bool) env('OPERATIONS_HEARTBEATS_REQUIRED', false),
    'metric_retention_days' => (int) env('METRIC_RETENTION_DAYS', 400),
];
