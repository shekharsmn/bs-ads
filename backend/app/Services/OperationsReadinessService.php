<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class OperationsReadinessService
{
    public function inspect(): array
    {
        $warnings = [];
        $production = app()->environment('production');
        if ($production && config('app.debug')) {
            $warnings[] = 'APP_DEBUG must be false in production.';
        }
        if (! config('app.key')) {
            $warnings[] = 'APP_KEY is not configured.';
        }
        if ($production && ! str_starts_with((string) config('app.frontend_url'), 'https://')) {
            $warnings[] = 'FRONTEND_URL must use HTTPS in production.';
        }
        if ($production && ! config('session.secure')) {
            $warnings[] = 'SESSION_SECURE_COOKIE must be enabled in production.';
        }
        if ($production && config('queue.default') === 'sync') {
            $warnings[] = 'QUEUE_CONNECTION cannot be sync in production.';
        }
        if ($production && config('cache.default') === 'array') {
            $warnings[] = 'The array cache is not durable enough for production.';
        }

        $frontend = rtrim((string) config('app.frontend_url'), '/');
        $origins = config('cors.allowed_origins', []);
        if ($production && (! in_array($frontend, $origins, true) || in_array('*', $origins, true))) {
            $warnings[] = 'CORS origins must exactly include FRONTEND_URL and must not use a wildcard.';
        }

        $database = $this->probe(fn () => DB::selectOne('select 1'));
        $cache = $this->probe(fn () => Cache::put('operations:readiness-probe', now()->timestamp, 10));
        $heartbeats = ['scheduler' => $this->heartbeat('operations:scheduler-heartbeat')];
        foreach (config('workloads.queues', []) as $name => $queue) {
            $heartbeats[$name] = $this->heartbeat('operations:queue-heartbeat:'.$name, $queue);
        }
        if ($production || config('workloads.heartbeats_required')) {
            foreach ($heartbeats as $name => $heartbeat) {
                if (! $heartbeat['fresh']) {
                    $warnings[] = ucfirst(str_replace('_', ' ', $name)).' heartbeat is missing or stale.';
                }
            }
        }

        return ['status' => $warnings === [] && $database['ok'] && $cache['ok'] ? 'ready' : 'warning', 'warnings' => $warnings, 'checks' => ['database' => $database, 'cache' => $cache, 'heartbeats' => $heartbeats], 'checked_at' => now()->toIso8601String()];
    }

    private function heartbeat(string $key, ?string $queue = null): array
    {
        $value = Cache::get($key);
        $age = $value ? now()->diffInSeconds($value) : null;

        return ['queue' => $queue, 'last_seen_at' => $value, 'age_seconds' => $age, 'fresh' => $age !== null && $age <= config('workloads.heartbeat_ttl_seconds')];
    }

    private function probe(callable $callback): array
    {
        try {
            $callback();

            return ['ok' => true];
        } catch (\Throwable $error) {
            return ['ok' => false, 'error' => $error::class];
        }
    }
}
