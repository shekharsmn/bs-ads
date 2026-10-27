<?php

namespace App\Services;

use App\Models\WorkflowActivity;
use App\Models\WorkflowAttempt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class WorkflowCoordinator
{
    private const TRANSITIONS = [
        'pending' => ['running', 'cancelled'],
        'running' => ['complete', 'failed', 'cancel_requested', 'cancelled', 'paused'],
        'cancel_requested' => ['cancelled', 'failed'],
        'paused' => ['running', 'cancelled'],
        'failed' => ['pending', 'running'],
        'complete' => [],
        'cancelled' => [],
    ];

    public function start(WorkflowActivity $activity, string $stage, ?string $idempotencyKey = null, array $metadata = []): WorkflowAttempt
    {
        return DB::transaction(function () use ($activity, $stage, $idempotencyKey, $metadata) {
            $locked = WorkflowActivity::query()->lockForUpdate()->findOrFail($activity->id);
            $key = $idempotencyKey ?: $locked->type.':'.$locked->entity_type.':'.$locked->entity_id.':'.Str::uuid();
            $existing = WorkflowAttempt::where('idempotency_key', $key)->first();
            if ($existing) {
                return $existing;
            }

            $persistedAttemptNumber = (int) $locked->attempts()->max('attempt_number');
            $attemptNumber = max((int) $locked->attempt_count, $persistedAttemptNumber) + 1;
            $jobUuid = Str::afterLast($key, ':');
            $attempt = $locked->attempts()->create([
                'attempt_number' => $attemptNumber,
                'idempotency_key' => $key,
                'job_uuid' => Str::isUuid($jobUuid) ? $jobUuid : null,
                'status' => 'running',
                'stage' => $stage,
                'metadata' => $metadata ?: null,
                'started_at' => now(),
                'heartbeat_at' => now(),
            ]);
            $locked->update([
                'attempt_count' => $attemptNumber,
                'status' => 'running',
                'stage' => $stage,
                'error_message' => null,
                'started_at' => $locked->started_at ?: now(),
                'finished_at' => null,
                'last_heartbeat_at' => now(),
            ]);

            return $attempt;
        });
    }

    public function heartbeat(WorkflowAttempt $attempt, ?string $stage = null, array $metadata = []): void
    {
        if ($attempt->status !== 'running') {
            return;
        }
        $values = ['heartbeat_at' => now()];
        if ($stage !== null) {
            $values['stage'] = $stage;
        }
        if ($metadata !== []) {
            $values['metadata'] = array_merge($attempt->metadata ?? [], $metadata);
        }
        $attempt->update($values);
        $attempt->activity()->update([
            'stage' => $stage ?? $attempt->stage,
            'last_heartbeat_at' => now(),
        ]);
    }

    public function complete(WorkflowAttempt $attempt, string $stage = 'complete', array $metadata = [], bool $projectToActivity = true): void
    {
        $this->finish($attempt, 'complete', $stage, null, $metadata, $projectToActivity);
    }

    public function fail(WorkflowAttempt $attempt, \Throwable $error, ?string $stage = null, bool $projectToActivity = true): void
    {
        $this->finish($attempt, 'failed', $stage ?? 'failed', $error, [], $projectToActivity);
    }

    public function cancel(WorkflowAttempt $attempt): void
    {
        $this->finish($attempt, 'cancelled', 'cancelled');
    }

    public function transition(WorkflowActivity $activity, string $to, ?string $stage = null): WorkflowActivity
    {
        return DB::transaction(function () use ($activity, $to, $stage) {
            $locked = WorkflowActivity::query()->lockForUpdate()->findOrFail($activity->id);
            $from = $this->commonStatus($locked->status);
            if (! in_array($to, self::TRANSITIONS[$from] ?? [], true)) {
                throw ValidationException::withMessages(['status' => "Workflow cannot transition from {$locked->status} to {$to}."]);
            }
            $locked->update(['status' => $to, 'stage' => $stage ?? $to]);

            return $locked->fresh();
        });
    }

    private function finish(WorkflowAttempt $attempt, string $status, string $stage, ?\Throwable $error = null, array $metadata = [], bool $projectToActivity = true): void
    {
        DB::transaction(function () use ($attempt, $status, $stage, $error, $metadata, $projectToActivity) {
            $locked = WorkflowAttempt::query()->lockForUpdate()->findOrFail($attempt->id);
            if ($locked->status !== 'running') {
                return;
            }
            $message = $error ? $this->safe($error->getMessage()) : null;
            $locked->update([
                'status' => $status,
                'stage' => $stage,
                'error_class' => $error ? $error::class : null,
                'error_message' => $message,
                'metadata' => $metadata === [] ? $locked->metadata : array_merge($locked->metadata ?? [], $metadata),
                'heartbeat_at' => now(),
                'finished_at' => now(),
            ]);
            $activityValues = ['stage' => $stage, 'last_heartbeat_at' => now()];
            if ($projectToActivity) {
                $activityValues += ['status' => $status, 'error_message' => $message, 'finished_at' => now()];
            }
            $locked->activity()->where('attempt_count', $locked->attempt_number)->update($activityValues);
        });
    }

    private function commonStatus(string $status): string
    {
        return match ($status) {
            'completed', 'succeeded' => 'complete',
            'creative_paused' => 'paused',
            default => $status,
        };
    }

    private function safe(string $message): string
    {
        return mb_substr(preg_replace('/https?:\/\/\S+|Bearer\s+\S+|(key|token|secret)=[^\s&]+/i', '[redacted]', $message) ?: 'Workflow failed.', 0, 1000);
    }
}
