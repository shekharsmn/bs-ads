<?php

namespace Tests\Feature;

use App\Jobs\GenerateReportSnapshotJob;
use App\Models\Client;
use App\Models\User;
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
}
