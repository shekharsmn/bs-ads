<?php

namespace App\Services;

use App\Jobs\CollectAccountMetricsJob;
use App\Jobs\QueueHeartbeatJob;
use App\Models\AdAccount;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

final class WorkloadQueue
{
    public function collection(int $account, ?string $start = null, ?string $end = null, ?string $refresh = null, bool $sync = false): bool
    {
        $job = new CollectAccountMetricsJob($account, $start, $end, $refresh);
        $jobs = $this->jobs();
        if ($jobs === null && ! $sync) {
            dispatch($job);

            return true;
        }

        // This also excludes synchronous CLI collection while an account has queued work.
        $lock = Cache::lock('operations:collection-admission:'.$account, $sync ? 1800 : 15);
        if (! $lock->get()) {
            return false;
        }

        try {
            $enqueue = function () use ($job, $jobs, $account, $start, $end, $sync) {
                if (! AdAccount::query()->whereKey($account)->lockForUpdate()->first(['id'])) {
                    return false;
                }
                if ($jobs !== null) {
                    foreach ($jobs->where('queue', $job->queue)->where('payload->displayName', CollectAccountMetricsJob::class)->select('payload')->cursor() as $row) {
                        $fields = self::fields($row->payload, CollectAccountMetricsJob::class);
                        if (($fields['adAccountId'] ?? null) === $account &&
                            ($sync || ($fields['startDate'] === $start && $fields['endDate'] === $end))) {
                            return false;
                        }
                    }
                }
                if (! $sync) {
                    // Insert on the same database connection while the account row is locked.
                    Queue::push($job->beforeCommit(), queue: $job->queue);
                }

                return true;
            };
            $accepted = DB::transaction($enqueue, 3);
            if ($accepted && $sync) {
                CollectAccountMetricsJob::dispatchSync($account, $start, $end, $refresh);
            }

            return $accepted;
        } finally {
            $lock->release();
        }
    }

    public function heartbeat(string $workload, string $queue): void
    {
        $jobs = $this->jobs();
        if ($jobs !== null) {
            foreach ($jobs->where('queue', $queue)->where('payload->displayName', QueueHeartbeatJob::class)->select('payload')->cursor() as $row) {
                if ((self::fields($row->payload, QueueHeartbeatJob::class)['workload'] ?? null) === $workload) {
                    return;
                }
            }
        }
        QueueHeartbeatJob::dispatch($workload, $queue);
    }

    public function jobs(): ?Builder
    {
        $connection = config('queue.default');
        $settings = config('queue.connections.'.$connection);
        if (($settings['driver'] ?? null) !== 'database') {
            return null;
        }
        if (DB::connection($settings['connection'] ?? null) !== DB::connection()) {
            throw new \LogicException('Workload admission requires the queue and application to share a database connection.');
        }

        return DB::table($settings['table']);
    }

    public static function fields(string $payload, string $class): ?array
    {
        $data = json_decode($payload, true);
        if (($data['displayName'] ?? null) !== $class || ! is_string($data['data']['command'] ?? null)) {
            return null;
        }
        // Inspect only known payloads without instantiating any serialized classes.
        $fields = (array) @unserialize($data['data']['command'], ['allowed_classes' => false, 'max_depth' => 8]);
        if (($fields['__PHP_Incomplete_Class_Name'] ?? null) !== $class) {
            return null;
        }
        if ($class === QueueHeartbeatJob::class && ! is_string($fields['workload'] ?? null)) {
            return null;
        }
        if ($class === CollectAccountMetricsJob::class &&
            (! is_int($fields['adAccountId'] ?? null) || ! array_key_exists('startDate', $fields) || ! array_key_exists('endDate', $fields) ||
                ($fields['startDate'] !== null && ! is_string($fields['startDate'])) ||
                ($fields['endDate'] !== null && ! is_string($fields['endDate'])))) {
            return null;
        }

        return $fields;
    }
}
