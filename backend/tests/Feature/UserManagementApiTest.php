<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\User;
use App\Models\UserInvitation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class UserManagementApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.google_workspace.allowed_domains', ['brandshark.com']);
        config()->set('services.google_workspace.frontend_url', 'http://frontend.test');
        Mail::fake();
    }

    public function test_admin_can_invite_edit_resend_and_revoke_a_user(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $client = Client::create(['name' => 'Acme', 'slug' => 'acme']);

        $response = $this->actingAs($admin)->postJson('/api/user-management/invitations', [
            'name' => 'Morgan Lee', 'email' => '  MORGAN@brandshark.com ', 'role' => 'viewer',
            'client_ids' => [$client->id], 'run_performance_audits' => true, 'run_content_audits' => false, 'use_data_inspector' => true,
        ])->assertCreated()->assertJsonPath('email', 'morgan@brandshark.com')->assertJsonPath('status', 'pending');
        $id = $response->json('id');
        $this->assertDatabaseHas('client_user_invitation', ['user_invitation_id' => $id, 'client_id' => $client->id]);
        $this->assertDatabaseHas('audit_events', ['action' => 'access.invitation_created', 'entity_id' => (string) $id]);

        $this->actingAs($admin)->putJson('/api/user-management/invitations/'.$id, [
            'role' => 'marketer', 'client_ids' => [], 'run_performance_audits' => false, 'run_content_audits' => true, 'use_data_inspector' => false,
        ])->assertOk()->assertJsonPath('role', 'marketer')->assertJsonPath('run_content_audits', true)->assertJsonPath('use_data_inspector', false);
        $this->actingAs($admin)->postJson('/api/user-management/invitations/'.$id.'/resend')->assertOk();
        $this->actingAs($admin)->postJson('/api/user-management/invitations/'.$id.'/revoke')->assertOk()->assertJsonPath('status', 'revoked');
    }

    public function test_invites_are_domain_limited_unique_and_admin_only(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $viewer = User::factory()->create(['role' => 'viewer']);
        $payload = ['email' => 'person@brandshark.com', 'role' => 'viewer'];

        $this->actingAs($viewer)->postJson('/api/user-management/invitations', $payload)->assertForbidden();
        $this->actingAs($admin)->postJson('/api/user-management/invitations', ['email' => 'person@example.com', 'role' => 'viewer'])->assertUnprocessable();
        $this->actingAs($admin)->postJson('/api/user-management/invitations', $payload)->assertCreated();
        $this->actingAs($admin)->postJson('/api/user-management/invitations', $payload)->assertUnprocessable();
    }

    public function test_user_permissions_are_independent_and_admin_permissions_are_forced_on(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $viewer = User::factory()->create(['role' => 'viewer']);

        $this->actingAs($admin)->putJson('/api/governance/users/'.$viewer->id, [
            'run_performance_audits' => true, 'run_content_audits' => false, 'use_data_inspector' => true,
        ])->assertOk()->assertJsonPath('run_performance_audits', true)->assertJsonPath('run_content_audits', false)->assertJsonPath('use_data_inspector', true);

        $this->actingAs($admin)->putJson('/api/governance/users/'.$viewer->id, [
            'role' => 'admin', 'run_performance_audits' => false, 'run_content_audits' => false, 'use_data_inspector' => false,
        ])->assertOk()->assertJsonPath('run_performance_audits', true)->assertJsonPath('run_content_audits', true)->assertJsonPath('use_data_inspector', true);
    }

    public function test_directory_combines_users_and_pending_invitations(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        UserInvitation::create(['email' => 'pending@brandshark.com', 'name' => 'Pending', 'role' => 'viewer', 'invited_by' => $admin->id, 'invited_at' => now()]);

        $this->actingAs($admin)->getJson('/api/user-management?status=pending')->assertOk()
            ->assertJsonPath('data.0.type', 'invitation')->assertJsonPath('summary.pending', 1);
    }

    public function test_directory_includes_the_signed_in_administrator(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'email' => 'admin@brandshark.com']);

        $this->actingAs($admin)->getJson('/api/user-management')->assertOk()
            ->assertJsonPath('data.0.id', $admin->id)
            ->assertJsonPath('data.0.email', 'admin@brandshark.com')
            ->assertJsonPath('data.0.use_data_inspector', true);
    }
}
