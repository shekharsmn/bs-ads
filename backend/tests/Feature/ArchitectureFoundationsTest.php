<?php

namespace Tests\Feature;

use App\Jobs\CollectAccountMetricsJob;
use App\Jobs\GenerateReportSnapshotJob;
use App\Jobs\RunGoogleAuditJob;
use App\Jobs\SendNotificationJob;
use App\Models\Client;
use App\Models\User;
use App\Models\WorkflowActivity;
use App\Services\WorkflowCoordinator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ArchitectureFoundationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_workflow_attempts_are_append_only_and_old_attempts_cannot_finish_newer_work(): void
    {
        $activity = WorkflowActivity::create(['type' => 'deep_audit', 'entity_type' => 'report', 'entity_id' => '1', 'title' => 'Audit', 'status' => 'pending']);
        $coordinator = app(WorkflowCoordinator::class);

        $first = $coordinator->start($activity, 'fetching', 'audit:1:first');
        $second = $coordinator->start($activity->fresh(), 'retrying', 'audit:1:second');
        $coordinator->complete($first);

        $this->assertSame('running', $activity->fresh()->status);
        $coordinator->complete($second);
        $this->assertSame('complete', $activity->fresh()->status);
        $this->assertSame(2, $activity->attempts()->count());
        $this->assertSame(2, $activity->fresh()->attempt_count);
    }

    public function test_workflow_attempt_number_recovers_from_a_stale_activity_counter(): void
    {
        $activity = WorkflowActivity::create(['type' => 'content_audit', 'entity_type' => 'audit', 'entity_id' => '1', 'title' => 'Audit', 'status' => 'pending']);
        $coordinator = app(WorkflowCoordinator::class);

        $first = $coordinator->start($activity, 'context', 'audit:1:context');
        $activity->update(['attempt_count' => 0]);

        $second = $coordinator->start($activity->fresh(), 'personas', 'audit:1:personas');

        $this->assertSame(1, $first->attempt_number);
        $this->assertSame(2, $second->attempt_number);
        $this->assertSame(2, $activity->fresh()->attempt_count);
    }

    public function test_activity_attempt_history_honors_client_access(): void
    {
        $client = Client::create(['name' => 'Scoped', 'slug' => 'scoped', 'timezone' => 'UTC', 'currency' => 'USD']);
        $allowed = User::factory()->create(['role' => 'marketer']);
        $blocked = User::factory()->create(['role' => 'marketer']);
        $client->users()->attach($allowed);
        $activity = WorkflowActivity::create(['type' => 'metric_collection', 'entity_type' => 'account', 'entity_id' => '1', 'client_id' => $client->id, 'title' => 'Metrics', 'status' => 'pending']);
        app(WorkflowCoordinator::class)->start($activity, 'collecting', 'metrics:scoped');

        $this->actingAs($allowed)->getJson("/api/activities/{$activity->id}/attempts")->assertOk()->assertJsonCount(1, 'data');
        $this->actingAs($blocked)->getJson("/api/activities/{$activity->id}/attempts")->assertForbidden();
    }

    public function test_workloads_use_their_isolated_queues(): void
    {
        $this->assertSame(config('workloads.queues.collection'), (new CollectAccountMetricsJob(1))->queue);
        $this->assertSame(config('workloads.queues.reports'), (new GenerateReportSnapshotJob(1))->queue);
        $this->assertSame(config('workloads.queues.audits'), (new RunGoogleAuditJob(1))->queue);
        $this->assertSame(config('workloads.queues.notifications'), (new SendNotificationJob(1))->queue);
    }

    public function test_readiness_command_is_warning_only(): void
    {
        $this->artisan('operations:readiness')->assertSuccessful();
    }

    public function test_readiness_details_are_admin_only(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $marketer = User::factory()->create(['role' => 'marketer']);

        $this->actingAs($admin)->getJson('/api/operations/readiness')->assertOk()->assertJsonStructure(['status', 'warnings', 'checks', 'checked_at']);
        $this->actingAs($marketer)->getJson('/api/operations/readiness')->assertForbidden();
    }
}
