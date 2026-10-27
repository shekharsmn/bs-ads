<?php

namespace Tests\Feature;

use App\Jobs\GenerateReportSnapshotJob;
use App\Models\AdAccount;
use App\Models\Client;
use App\Models\MetricSnapshot;
use App\Models\PlatformConnection;
use App\Models\ReportRun;
use App\Models\User;
use App\Services\GoogleChatNotifier;
use App\Services\MetricsQueryService;
use App\Services\NotificationDispatcher;
use App\Services\PerformanceCollectionService;
use App\Services\WorkflowActivityService;
use App\Services\WorkflowCoordinator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class InstantReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_manager_can_generate_an_instant_report_using_the_client_timezone(): void
    {
        Queue::fake();
        Carbon::setTestNow('2026-10-16 01:30:00 UTC');
        $admin = User::factory()->create(['role' => 'admin']);
        $client = Client::create(['name' => 'Acme', 'slug' => 'acme', 'timezone' => 'Pacific/Honolulu']);

        $response = $this->actingAs($admin)->postJson('/api/clients/'.$client->id.'/report-runs', [
            'frequency' => 'instant',
            'preset' => 'last_7d',
        ])->assertAccepted()
            ->assertJsonPath('frequency', 'instant')
            ->assertJsonPath('period_start', '2026-10-09T00:00:00.000000Z')
            ->assertJsonPath('period_end', '2026-10-15T00:00:00.000000Z')
            ->assertJsonPath('status', 'pending');

        Queue::assertPushed(GenerateReportSnapshotJob::class, fn ($job) => $job->reportRunId === $response->json('id'));
    }

    public function test_viewer_cannot_generate_an_instant_report(): void
    {
        Queue::fake();
        $viewer = User::factory()->create(['role' => 'viewer']);
        $client = Client::create(['name' => 'Acme', 'slug' => 'acme']);
        $client->users()->attach($viewer);

        $this->actingAs($viewer)->postJson('/api/clients/'.$client->id.'/report-runs', [
            'frequency' => 'instant',
            'preset' => 'last_30d',
        ])->assertForbidden();

        Queue::assertNothingPushed();
    }

    public function test_first_report_collects_an_assigned_account_before_building_its_snapshot(): void
    {
        Queue::fake();
        Carbon::setTestNow('2026-10-16 01:30:00 UTC');
        $admin = User::factory()->create(['role' => 'admin']);
        $client = Client::create(['name' => 'Acme', 'slug' => 'acme', 'timezone' => 'UTC', 'currency' => 'USD']);
        $connection = PlatformConnection::create([
            'platform' => 'google',
            'name' => 'Agency MCC',
            'scope' => 'agency',
            'credentials' => ['refresh_token' => 'secret'],
            'status' => 'healthy',
        ]);
        $account = AdAccount::create([
            'client_id' => $client->id,
            'platform_connection_id' => $connection->id,
            'platform' => 'google',
            'external_id' => '1234567890',
            'name' => 'Acme Ads',
            'currency' => 'USD',
            'timezone' => 'UTC',
            'status' => 'active',
        ]);
        $response = $this->actingAs($admin)->postJson('/api/clients/'.$client->id.'/report-runs', [
            'frequency' => 'instant',
            'preset' => 'last_7d',
        ])->assertAccepted();
        $pendingRun = ReportRun::findOrFail($response->json('id'));
        $expectedStart = $pendingRun->period_start->toDateString();
        $expectedEnd = $pendingRun->period_end->toDateString();

        $collector = $this->mock(PerformanceCollectionService::class);
        $collector->shouldReceive('collect')->once()->withArgs(fn (AdAccount $candidate, string $start, string $end) => $candidate->is($account) && $start === $expectedStart && $end === $expectedEnd)
            ->andReturnUsing(function (AdAccount $candidate): void {
                MetricSnapshot::create([
                    'ad_account_id' => $candidate->id,
                    'scope' => 'account',
                    'scope_key' => 'account:'.$candidate->id,
                    'metric_date' => '2026-10-15',
                    'currency' => 'USD',
                    'spend' => 125.50,
                    'impressions' => 2000,
                    'clicks' => 100,
                    'conversions' => 10,
                    'collected_at' => now(),
                ]);
                $candidate->update(['last_collected_at' => now()]);
            });

        (new GenerateReportSnapshotJob($response->json('id')))->handle(
            app(MetricsQueryService::class),
            app(GoogleChatNotifier::class),
            app(NotificationDispatcher::class),
            app(WorkflowActivityService::class),
            app(WorkflowCoordinator::class),
            $collector,
        );

        $run = ReportRun::findOrFail($response->json('id'));
        $this->assertSame('complete', $run->status);
        $this->assertSame('USD', data_get($run->snapshot, 'summary.currencies.0.currency'));
        $this->assertSame(125.5, (float) data_get($run->snapshot, 'summary.currencies.0.metrics.spend'));
    }
}
