<?php

namespace Tests\Feature;

use App\Models\AdAccount;
use App\Models\AdLibraryAudit;
use App\Models\Client;
use App\Models\PlatformConnection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AuditPermissionTest extends TestCase
{
    use RefreshDatabase;

    public function test_performance_audit_permission_is_independent_and_client_scoped(): void
    {
        Queue::fake();
        $user = User::factory()->create(['role' => 'viewer']);
        [$client, $account] = $this->account('Assigned');
        [, $hiddenAccount] = $this->account('Hidden');
        $client->users()->attach($user);

        $this->actingAs($user)->postJson('/api/reports', ['platform' => 'meta', 'ad_account_id' => $account->id])->assertForbidden();
        $user->update(['run_performance_audits' => true]);
        $this->postJson('/api/reports', ['platform' => 'meta', 'ad_account_id' => $account->id])->assertAccepted();
        $this->postJson('/api/reports', ['platform' => 'meta', 'ad_account_id' => $hiddenAccount->id])->assertForbidden();
    }

    public function test_content_audit_permission_is_independent_and_client_scoped(): void
    {
        config(['services.ad_library.enabled' => true, 'ad_library_audit.enabled' => true]);
        $user = User::factory()->create(['role' => 'viewer', 'run_content_audits' => true]);
        $client = Client::create(['name' => 'Assigned', 'slug' => 'assigned']);
        $hidden = Client::create(['name' => 'Hidden', 'slug' => 'hidden']);
        $client->users()->attach($user);
        $allowedAudit = $this->audit($user, $client, 'Allowed');
        $hiddenAudit = $this->audit($user, $hidden, 'Hidden');

        $this->actingAs($user)->putJson('/api/ad-library/audits/'.$allowedAudit->id, ['name' => 'Updated'])->assertOk()->assertJsonPath('name', 'Updated');
        $this->putJson('/api/ad-library/audits/'.$hiddenAudit->id, ['name' => 'Blocked'])->assertForbidden();
        $user->update(['run_content_audits' => false]);
        $this->putJson('/api/ad-library/audits/'.$allowedAudit->id, ['name' => 'Blocked'])->assertForbidden();
    }

    private function account(string $name): array
    {
        $client = Client::create(['name' => $name, 'slug' => strtolower($name)]);
        $connection = PlatformConnection::create(['client_id' => $client->id, 'platform' => 'meta', 'name' => $name, 'credentials' => [], 'status' => 'healthy']);
        $account = AdAccount::create(['client_id' => $client->id, 'platform_connection_id' => $connection->id, 'platform' => 'meta', 'external_id' => strtolower($name), 'name' => $name, 'currency' => 'USD', 'timezone' => 'UTC', 'status' => 'active']);
        return [$client, $account];
    }

    private function audit(User $user, Client $client, string $name): AdLibraryAudit
    {
        return AdLibraryAudit::create(['company_name' => $name, 'company_type' => 'client', 'created_by' => $user->id, 'related_client_id' => $client->id, 'name' => $name, 'provider' => 'gemini', 'model' => 'gemini-2.5-flash-lite', 'model_key' => 'gemini:gemini-2.5-flash-lite', 'status' => 'context_review']);
    }
}
