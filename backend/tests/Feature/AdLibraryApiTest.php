<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Jobs\RunAdLibraryScrapeJob;
use App\Models\AdLibrarySearch;
use App\Models\Client;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AdLibraryApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.ad_library.enabled' => true]);
    }

    public function test_feature_flag_hides_endpoints(): void
    {
        config(['services.ad_library.enabled' => false]);
        $user = User::factory()->create(['role' => UserRole::Admin]);
        $this->actingAs($user)->getJson('/api/ad-library/searches')->assertNotFound();
    }

    public function test_manager_can_create_and_queue_competitor_search_without_a_client(): void
    {
        Queue::fake();
        $manager = User::factory()->create(['role' => UserRole::Marketer]);

        $search = $this->actingAs($manager)->postJson('/api/ad-library/searches', [
            'company_name' => 'Acme Running', 'company_type' => 'competitor', 'name' => 'Competitors', 'search_mode' => 'keyword',
            'query' => 'running shoes', 'region' => 'US', 'active_status' => 'active',
            'ad_type' => 'all', 'max_results' => 100,
        ])->assertCreated()->json();

        $run = $this->postJson('/api/ad-library/searches/'.$search['id'].'/runs')->assertAccepted()->json();
        $this->assertSame('pending', $run['status']);
        Queue::assertPushed(RunAdLibraryScrapeJob::class, fn ($job) => $job->runId === $run['id']);
    }

    public function test_manager_can_link_company_research_to_an_accessible_client(): void
    {
        $manager = User::factory()->create(['role' => UserRole::Marketer]);
        $client = Client::create(['name' => 'Northstar', 'slug' => 'northstar']);
        $client->users()->attach($manager);

        $search = $this->actingAs($manager)->postJson('/api/ad-library/searches', [
            'company_name' => 'Northstar Rival', 'company_type' => 'competitor', 'related_client_id' => $client->id,
            'name' => 'Northstar competitors', 'search_mode' => 'keyword', 'query' => 'running shoes',
        ])->assertCreated()->json();

        $this->assertSame($client->id, $search['related_client_id']);
        $this->getJson('/api/ad-library/searches')->assertOk()
            ->assertJsonPath('searches.0.related_client.name', 'Northstar');
    }

    public function test_manager_cannot_link_company_research_to_an_inaccessible_client(): void
    {
        $manager = User::factory()->create(['role' => UserRole::Marketer]);
        $hiddenClient = Client::create(['name' => 'Hidden', 'slug' => 'hidden']);

        $this->actingAs($manager)->postJson('/api/ad-library/searches', [
            'company_name' => 'Hidden Rival', 'company_type' => 'competitor', 'related_client_id' => $hiddenClient->id,
            'name' => 'Hidden competitors', 'search_mode' => 'keyword', 'query' => 'shoes',
        ])->assertForbidden();
    }

    public function test_viewer_can_read_but_cannot_mutate(): void
    {
        $viewer = User::factory()->create(['role' => UserRole::Viewer]);
        $search = $this->search($viewer);

        $this->actingAs($viewer)->getJson('/api/ad-library/searches')->assertOk();
        $this->postJson('/api/ad-library/searches/'.$search->id.'/runs')->assertForbidden();
        $this->deleteJson('/api/ad-library/searches/'.$search->id)->assertForbidden();
    }

    public function test_authenticated_viewer_can_read_shared_company_research(): void
    {
        $viewer = User::factory()->create(['role' => UserRole::Viewer]);
        $manager = User::factory()->create(['role' => UserRole::Marketer]);
        $run = $this->search($manager)->runs()->create(['requested_by' => $manager->id]);

        $this->actingAs($viewer)->getJson('/api/ad-library/searches')->assertOk()
            ->assertJsonPath('searches.0.company_name', 'Acme Running');
        $this->getJson('/api/ad-library/runs/'.$run->id)->assertOk();
        $this->getJson('/api/ad-library/runs/'.$run->id.'/json')->assertOk();
    }

    public function test_viewer_can_export_complete_stored_run_json(): void
    {
        $viewer = User::factory()->create(['role' => UserRole::Viewer]);
        $run = $this->search($viewer)->runs()->create([
            'requested_by' => $viewer->id,
            'status' => 'complete',
            'result_count' => 2,
            'reported_total' => 2,
            'stop_reason' => 'complete',
            'provider_metadata' => ['engine' => 'playwright'],
        ]);
        $run->results()->createMany([
            ['library_ad_id' => 'second', 'rank' => 2, 'raw_payload' => ['ad_id' => 'second', 'body' => 'Second']],
            ['library_ad_id' => 'first', 'rank' => 1, 'raw_payload' => ['ad_id' => 'first', 'body' => 'First']],
        ]);

        $response = $this->actingAs($viewer)->getJson('/api/ad-library/runs/'.$run->id.'/json');

        $response->assertOk()
            ->assertJsonPath('request_id', (string) $run->id)
            ->assertJsonPath('status', 'complete')
            ->assertJsonPath('metadata.engine', 'playwright')
            ->assertJsonPath('ads.0.ad_id', 'first')
            ->assertJsonPath('ads.1.ad_id', 'second');
    }

    public function test_results_group_duplicate_creatives_and_filter_by_media_type(): void
    {
        $viewer = User::factory()->create(['role' => UserRole::Viewer]);
        $run = $this->search($viewer)->runs()->create([
            'requested_by' => $viewer->id,
            'status' => 'complete',
            'result_count' => 3,
        ]);
        $run->results()->createMany([
            [
                'library_ad_id' => 'static-a', 'rank' => 1, 'advertiser_page_id' => '99',
                'display_format' => 'IMAGE', 'body' => 'Same creative',
                'image_urls' => ['https://cdn.example.com/creative.jpg?token=one'],
                'raw_payload' => ['ad_id' => 'static-a', 'collation_id' => 'group-1'],
            ],
            [
                'library_ad_id' => 'static-b', 'rank' => 2, 'advertiser_page_id' => '99',
                'display_format' => 'IMAGE', 'body' => 'Same  creative',
                'image_urls' => ['https://cdn.example.com/creative.jpg?token=two'],
                'raw_payload' => ['ad_id' => 'static-b', 'collation_id' => 'group-1'],
            ],
            [
                'library_ad_id' => 'video-a', 'rank' => 3, 'advertiser_page_id' => '99',
                'display_format' => 'VIDEO', 'body' => 'Video creative',
                'image_urls' => ['https://cdn.example.com/poster.jpg'],
                'video_urls' => ['https://cdn.example.com/creative.mp4?token=three'],
                'raw_payload' => ['ad_id' => 'video-a'],
            ],
        ]);

        $all = $this->actingAs($viewer)->getJson('/api/ad-library/runs/'.$run->id.'/ads');
        $all->assertOk()
            ->assertJsonPath('total', 2)
            ->assertJsonPath('raw_total', 3)
            ->assertJsonPath('duplicate_count', 1)
            ->assertJsonPath('data.0.media_type', 'static')
            ->assertJsonPath('data.0.variant_count', 2)
            ->assertJsonPath('data.0.variants.1.library_ad_id', 'static-b');

        $this->getJson('/api/ad-library/runs/'.$run->id.'/ads?media_type=static')
            ->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.media_type', 'static');
        $this->getJson('/api/ad-library/runs/'.$run->id.'/ads?media_type=video')
            ->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.media_type', 'video');
    }

    public function test_results_can_filter_by_minimum_age_and_sort_oldest_first(): void
    {
        $viewer = User::factory()->create(['role' => UserRole::Viewer]);
        $run = $this->search($viewer)->runs()->create([
            'requested_by' => $viewer->id,
            'status' => 'complete',
            'result_count' => 3,
        ]);
        $run->results()->createMany([
            ['library_ad_id' => 'recent', 'rank' => 1, 'body' => 'Recent creative', 'first_shown_at' => now()->subDays(6)->toDateString(), 'raw_payload' => ['ad_id' => 'recent']],
            ['library_ad_id' => 'month-old', 'rank' => 2, 'body' => 'Month-old creative', 'first_shown_at' => now()->subDays(40)->toDateString(), 'raw_payload' => ['ad_id' => 'month-old']],
            ['library_ad_id' => 'oldest', 'rank' => 3, 'body' => 'Oldest creative', 'first_shown_at' => now()->subDays(100)->toDateString(), 'raw_payload' => ['ad_id' => 'oldest']],
        ]);

        $this->actingAs($viewer)->getJson('/api/ad-library/runs/'.$run->id.'/ads?min_age_days=30&sort=oldest')
            ->assertOk()
            ->assertJsonPath('total', 2)
            ->assertJsonPath('data.0.library_ad_id', 'oldest')
            ->assertJsonPath('data.1.library_ad_id', 'month-old');
    }

    public function test_details_search_is_limited_to_fifty_results(): void
    {
        $manager = User::factory()->create(['role' => UserRole::Marketer]);
        $this->actingAs($manager)->postJson('/api/ad-library/searches', [
            'company_name' => 'Acme Running', 'company_type' => 'lead', 'name' => 'Detailed', 'search_mode' => 'keyword',
            'query' => 'shoes', 'details' => true, 'max_results' => 51,
        ])->assertUnprocessable();
    }

    public function test_terminal_run_can_be_deleted_but_running_run_cannot(): void
    {
        $manager = User::factory()->create(['role' => UserRole::Marketer]);
        $search = $this->search($manager);
        $running = $search->runs()->create(['requested_by' => $manager->id, 'status' => 'running']);
        $complete = $search->runs()->create(['requested_by' => $manager->id, 'status' => 'complete']);

        $this->actingAs($manager)->deleteJson('/api/ad-library/runs/'.$running->id)->assertStatus(409);
        $this->deleteJson('/api/ad-library/runs/'.$complete->id)->assertNoContent();
        $this->assertDatabaseMissing('ad_library_runs', ['id' => $complete->id]);
    }

    private function search(User $user): AdLibrarySearch
    {
        return AdLibrarySearch::create(['created_by' => $user->id, 'company_name' => 'Acme Running', 'company_type' => 'competitor', 'name' => 'Search', 'search_mode' => 'keyword', 'query' => 'shoes']);
    }
}
