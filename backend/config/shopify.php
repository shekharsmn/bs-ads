<?php

return [
    'enabled' => (bool) env('SHOPIFY_ENABLED', true),
    'api_version' => '2026-10',
    'queue' => 'shopify-sync',
    'http_timeout' => 20,
    'overlap_minutes' => 5,
    'event_retention_days' => 7,
];
