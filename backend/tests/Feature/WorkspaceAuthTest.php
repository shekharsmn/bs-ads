<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WorkspaceAuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_first_verified_workspace_user_becomes_admin(): void
    {
        config()->set('services.google_workspace.allowed_domains', ['brandshark.com']);
        config()->set('services.google_workspace.frontend_url', 'http://frontend.test');
        Cache::put('workspace_sso:valid', true);
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'token']),
            'https://openidconnect.googleapis.com/v1/userinfo' => Http::response(['sub' => 'google-1', 'email' => 'owner@brandshark.com', 'email_verified' => true, 'hd' => 'brandshark.com', 'name' => 'Owner']),
        ]);

        $this->get('/api/auth/google/callback?state=valid&code=code')->assertRedirect('http://frontend.test');
        $this->assertAuthenticated();
        $this->assertSame('admin', User::first()->role->value);
    }

    public function test_non_workspace_domain_is_rejected(): void
    {
        config()->set('services.google_workspace.allowed_domains', ['brandshark.com']);
        Cache::put('workspace_sso:valid', true);
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'token']),
            'https://openidconnect.googleapis.com/v1/userinfo' => Http::response(['sub' => 'google-2', 'email' => 'outsider@example.com', 'email_verified' => true, 'hd' => 'example.com', 'name' => 'Outsider']),
        ]);

        $this->get('/api/auth/google/callback?state=valid&code=code')->assertForbidden();
        $this->assertDatabaseCount('users', 0);
    }

    public function test_verified_user_from_any_allowed_workspace_domain_can_sign_in(): void
    {
        config()->set('services.google_workspace.allowed_domains', ['brandshark.com', 'brandshark.ae']);
        config()->set('services.google_workspace.frontend_url', 'http://frontend.test');
        Cache::put('workspace_sso:valid', true);
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'token']),
            'https://openidconnect.googleapis.com/v1/userinfo' => Http::response(['sub' => 'google-3', 'email' => 'owner@brandshark.ae', 'email_verified' => true, 'hd' => 'brandshark.ae', 'name' => 'Owner']),
        ]);

        $this->get('/api/auth/google/callback?state=valid&code=code')->assertRedirect('http://frontend.test');
        $this->assertAuthenticated();
        $this->assertDatabaseHas('users', ['email' => 'owner@brandshark.ae']);
    }

    public function test_redirect_uses_workspace_hint_when_multiple_domains_are_allowed(): void
    {
        config()->set('services.google_workspace.client_id', 'client-id');
        config()->set('services.google_workspace.allowed_domains', ['brandshark.com', 'brandshark.ae']);

        $response = $this->get('/api/auth/google/redirect')->assertRedirect();
        parse_str((string) parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);

        $this->assertSame('*', $query['hd']);
    }

    public function test_consumer_google_account_without_hosted_domain_claim_is_rejected(): void
    {
        config()->set('services.google_workspace.allowed_domains', ['brandshark.com']);
        Cache::put('workspace_sso:valid', true);
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'token']),
            'https://openidconnect.googleapis.com/v1/userinfo' => Http::response(['sub' => 'google-4', 'email' => 'owner@brandshark.com', 'email_verified' => true, 'name' => 'Owner']),
        ]);

        $this->get('/api/auth/google/callback?state=valid&code=code')->assertForbidden();
        $this->assertDatabaseCount('users', 0);
    }

    public function test_password_registration_routes_are_removed(): void
    {
        $this->postJson('/api/register', [])->assertNotFound();
        $this->postJson('/api/login', [])->assertNotFound();
    }
}
