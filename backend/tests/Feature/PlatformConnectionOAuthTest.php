<?php

namespace Tests\Feature;

use App\Models\PlatformConnection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PlatformConnectionOAuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_google_oauth_creates_an_encrypted_operational_connection(): void
    {
        config()->set('google_ads.developer_token', 'developer-token');
        config()->set('google_ads.client_id', 'client-id');
        config()->set('google_ads.client_secret', 'client-secret');
        config()->set('google_ads.oauth_redirect_uri', 'http://frontend.test/api/oauth/google/callback');
        config()->set('services.google_workspace.frontend_url', 'http://frontend.test');
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['refresh_token' => 'refresh-secret']),
        ]);
        $admin = User::factory()->create(['role' => 'admin']);

        $redirect = $this->actingAs($admin)->get('/api/connections/google/redirect?scope=agency&name=Agency%20MCC')->assertRedirect();
        parse_str((string) parse_url($redirect->headers->get('Location'), PHP_URL_QUERY), $query);

        $this->get('/api/oauth/google/callback?state='.$query['state'].'&code=oauth-code')
            ->assertRedirectContains('http://frontend.test/connections?connected=google&connection_id=');

        $connection = PlatformConnection::firstOrFail();
        $this->assertSame('Agency MCC', $connection->name);
        $this->assertSame('refresh-secret', $connection->credentials['refresh_token']);
        $this->assertStringNotContainsString('refresh-secret', $connection->getRawOriginal('credentials'));
    }

    public function test_non_admin_cannot_start_an_operational_google_connection(): void
    {
        $viewer = User::factory()->create(['role' => 'viewer']);

        $this->actingAs($viewer)->get('/api/connections/google/redirect?scope=agency')->assertForbidden();
    }

    public function test_google_oauth_failure_redirect_does_not_expose_provider_response_content(): void
    {
        config()->set('google_ads.developer_token', 'developer-token');
        config()->set('google_ads.client_id', 'client-id');
        config()->set('google_ads.client_secret', 'client-secret');
        config()->set('google_ads.oauth_redirect_uri', 'http://frontend.test/api/oauth/google/callback');
        config()->set('services.google_workspace.frontend_url', 'http://frontend.test');
        Http::fake(['https://oauth2.googleapis.com/token' => Http::response('provider-secret-detail', 500)]);
        $admin = User::factory()->create(['role' => 'admin']);
        $redirect = $this->actingAs($admin)->get('/api/connections/google/redirect?scope=agency')->assertRedirect();
        parse_str((string) parse_url($redirect->headers->get('Location'), PHP_URL_QUERY), $query);

        $response = $this->get('/api/oauth/google/callback?state='.$query['state'].'&code=oauth-code')->assertRedirect();
        $location = (string) $response->headers->get('Location');

        $this->assertStringContainsString('Reference%3A', $location);
        $this->assertStringNotContainsString('provider-secret-detail', $location);
    }
}
