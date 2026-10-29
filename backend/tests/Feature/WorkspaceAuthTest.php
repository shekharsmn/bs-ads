<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Client;
use App\Models\UserInvitation;
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
        config()->set('services.google_workspace.bootstrap_admin_email', 'owner@brandshark.com');
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

    public function test_first_user_stays_viewer_when_they_are_not_the_configured_bootstrap_admin(): void
    {
        config()->set('services.google_workspace.allowed_domains', ['brandshark.com']);
        config()->set('services.google_workspace.bootstrap_admin_email', 'admin@brandshark.com');
        config()->set('services.google_workspace.frontend_url', 'http://frontend.test');
        Cache::put('workspace_sso:valid', true);
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'token']),
            'https://openidconnect.googleapis.com/v1/userinfo' => Http::response(['sub' => 'google-viewer', 'email' => 'viewer@brandshark.com', 'email_verified' => true, 'hd' => 'brandshark.com', 'name' => 'Viewer']),
        ]);

        $this->get('/api/auth/google/callback?state=valid&code=code')->assertRedirect('http://frontend.test');

        $this->assertSame('viewer', User::first()->role->value);
        $this->assertFalse(User::first()->run_performance_audits);
        $this->assertFalse(User::first()->run_content_audits);
        $this->assertFalse(User::first()->use_data_inspector);
        $this->assertCount(0, User::first()->clients);
    }

    public function test_pending_invitation_is_applied_atomically_on_first_sign_in(): void
    {
        config()->set('services.google_workspace.allowed_domains', ['brandshark.com']);
        config()->set('services.google_workspace.frontend_url', 'http://frontend.test');
        $admin = User::factory()->create(['role' => 'admin']);
        $client = Client::create(['name' => 'Acme', 'slug' => 'acme']);
        $invitation = UserInvitation::create(['email' => 'invitee@brandshark.com', 'name' => 'Invitee', 'role' => 'marketer', 'run_content_audits' => true, 'use_data_inspector' => true, 'invited_by' => $admin->id, 'invited_at' => now()]);
        $invitation->clients()->attach($client);
        Cache::put('workspace_sso:valid', true);
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'token']),
            'https://openidconnect.googleapis.com/v1/userinfo' => Http::response(['sub' => 'google-invitee', 'email' => 'invitee@brandshark.com', 'email_verified' => true, 'hd' => 'brandshark.com', 'name' => 'Invitee']),
        ]);

        $this->get('/api/auth/google/callback?state=valid&code=code')->assertRedirect('http://frontend.test');
        $user = User::where('email', 'invitee@brandshark.com')->firstOrFail();
        $this->assertSame('marketer', $user->role->value);
        $this->assertTrue($user->run_content_audits);
        $this->assertFalse($user->run_performance_audits);
        $this->assertTrue($user->use_data_inspector);
        $this->assertTrue($user->clients()->whereKey($client->id)->exists());
        $this->assertNotNull($invitation->fresh()->accepted_at);
    }

    public function test_revoked_invitation_blocks_sign_in_until_reinvited(): void
    {
        config()->set('services.google_workspace.allowed_domains', ['brandshark.com']);
        UserInvitation::create(['email' => 'revoked@brandshark.com', 'role' => 'viewer', 'invited_at' => now(), 'revoked_at' => now()]);
        Cache::put('workspace_sso:valid', true);
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'token']),
            'https://openidconnect.googleapis.com/v1/userinfo' => Http::response(['sub' => 'google-revoked', 'email' => 'revoked@brandshark.com', 'email_verified' => true, 'hd' => 'brandshark.com', 'name' => 'Revoked']),
        ]);

        $this->get('/api/auth/google/callback?state=valid&code=code')->assertForbidden();
        $this->assertDatabaseMissing('users', ['email' => 'revoked@brandshark.com']);
    }

    public function test_workspace_oauth_failure_does_not_expose_provider_response_content(): void
    {
        config()->set('services.google_workspace.frontend_url', 'http://frontend.test');
        Cache::put('workspace_sso:valid', true);
        Http::fake(['https://oauth2.googleapis.com/token' => Http::response('provider-secret-detail', 500)]);

        $response = $this->get('/api/auth/google/callback?state=valid&code=code')->assertRedirect();
        $location = (string) $response->headers->get('Location');

        $this->assertStringContainsString('Reference%3A', $location);
        $this->assertStringNotContainsString('provider-secret-detail', $location);
    }

    public function test_bootstrap_email_does_not_override_roles_after_an_admin_exists(): void
    {
        User::factory()->create(['role' => 'admin']);
        config()->set('services.google_workspace.allowed_domains', ['brandshark.com']);
        config()->set('services.google_workspace.bootstrap_admin_email', 'bootstrap@brandshark.com');
        config()->set('services.google_workspace.frontend_url', 'http://frontend.test');
        Cache::put('workspace_sso:valid', true);
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'token']),
            'https://openidconnect.googleapis.com/v1/userinfo' => Http::response(['sub' => 'google-bootstrap', 'email' => 'bootstrap@brandshark.com', 'email_verified' => true, 'hd' => 'brandshark.com', 'name' => 'Bootstrap']),
        ]);

        $this->get('/api/auth/google/callback?state=valid&code=code')->assertRedirect('http://frontend.test');

        $this->assertSame('viewer', User::where('email', 'bootstrap@brandshark.com')->firstOrFail()->role->value);
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
