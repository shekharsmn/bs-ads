<?php

namespace Tests\Feature;

use App\Models\Ad;
use App\Models\AdAccount;
use App\Models\AdGroup;
use App\Models\Campaign;
use App\Models\Client;
use App\Models\CreativeMediaSetting;
use App\Models\PlatformConnection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CreativeMediaApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_configure_proxy_without_reading_the_saved_key(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->putJson('/api/settings/creative-media-proxy', [
            'enabled' => true, 'api_key' => 'secret-cors-key-1234',
        ])->assertOk()
            ->assertJsonPath('enabled', true)
            ->assertJsonPath('configured', true)
            ->assertJsonPath('api_key_hint', 'Saved - ending in 1234')
            ->assertJsonMissing(['api_key' => 'secret-cors-key-1234']);

        $this->assertSame('secret-cors-key-1234', CreativeMediaSetting::firstOrFail()->api_key);
        $viewer = User::factory()->create(['role' => 'viewer']);
        $this->actingAs($viewer)->getJson('/api/settings/creative-media-proxy')->assertForbidden();
    }

    public function test_media_proxy_url_is_resource_scoped_host_checked_and_not_cached(): void
    {
        [$viewer, $account, $ad] = $this->fixture('https://video.xx.fbcdn.net/creative.mp4');
        CreativeMediaSetting::create(['provider' => 'corsproxy_io', 'enabled' => true, 'api_key' => 'proxy-key']);

        $response = $this->actingAs($viewer)->postJson("/api/performance/accounts/{$account->id}/ads/{$ad->id}/media/0/proxy-url")
            ->assertOk()
            ->assertJsonPath('filename', 'video-creative-1.mp4');
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('https://corsproxy.io/?', $response->json('url'));
        $this->assertStringContainsString('key=proxy-key', $response->json('url'));
        $this->assertStringContainsString(rawurlencode('https://video.xx.fbcdn.net/creative.mp4'), $response->json('url'));

        $ad->update(['creative' => ['media' => [['type' => 'video', 'source_url' => 'https://example.com/private.mp4', 'downloadable' => true]]]]);
        $this->actingAs($viewer)->postJson("/api/performance/accounts/{$account->id}/ads/{$ad->id}/media/0/proxy-url")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('media');

        [, $otherAccount] = $this->fixture('https://video.xx.fbcdn.net/other.mp4', 'other');
        $this->actingAs($viewer)->postJson("/api/performance/accounts/{$otherAccount->id}/ads/{$ad->id}/media/0/proxy-url")->assertForbidden();
    }

    private function fixture(string $url, string $suffix = 'main'): array
    {
        $viewer = User::factory()->create(['role' => 'viewer']);
        $client = Client::create(['name' => 'Client '.$suffix, 'slug' => 'creative-'.$suffix, 'currency' => 'USD']);
        $client->users()->attach($viewer);
        $connection = PlatformConnection::create(['platform' => 'meta', 'name' => 'Meta '.$suffix, 'scope' => 'agency', 'credentials' => ['access_token' => 'token'], 'status' => 'healthy']);
        $account = AdAccount::create(['client_id' => $client->id, 'platform_connection_id' => $connection->id, 'platform' => 'meta', 'external_id' => 'act-'.$suffix, 'name' => 'Account '.$suffix, 'currency' => 'USD', 'timezone' => 'UTC', 'status' => 'active']);
        $campaign = Campaign::create(['ad_account_id' => $account->id, 'external_id' => 'campaign-'.$suffix, 'name' => 'Campaign', 'status' => 'ACTIVE']);
        $group = AdGroup::create(['campaign_id' => $campaign->id, 'external_id' => 'group-'.$suffix, 'name' => 'Group', 'status' => 'ACTIVE']);
        $ad = Ad::create(['ad_group_id' => $group->id, 'external_id' => 'ad-'.$suffix, 'name' => 'Video creative', 'status' => 'ACTIVE', 'creative' => ['media' => [['type' => 'video', 'source_url' => $url, 'downloadable' => true]]]]);

        return [$viewer, $account, $ad];
    }
}
