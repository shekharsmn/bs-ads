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
        config()->set('services.google_workspace.allowed_domain', 'brandshark.com');
        config()->set('services.google_workspace.frontend_url', 'http://frontend.test');
        Cache::put('workspace_sso:valid', true);
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'token']),
            'https://openidconnect.googleapis.com/v1/userinfo' => Http::response(['sub' => 'google-1', 'email' => 'owner@brandshark.com', 'email_verified' => true, 'name' => 'Owner']),
        ]);

        $this->get('/api/auth/google/callback?state=valid&code=code')->assertRedirect('http://frontend.test');
        $this->assertAuthenticated();
        $this->assertSame('admin', User::first()->role->value);
    }

    public function test_non_workspace_domain_is_rejected(): void
    {
        config()->set('services.google_workspace.allowed_domain', 'brandshark.com');
        Cache::put('workspace_sso:valid', true);
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'token']),
            'https://openidconnect.googleapis.com/v1/userinfo' => Http::response(['sub' => 'google-2', 'email' => 'outsider@example.com', 'email_verified' => true, 'name' => 'Outsider']),
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
