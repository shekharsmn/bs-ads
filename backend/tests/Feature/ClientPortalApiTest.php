<?php

namespace Tests\Feature;

use App\Models\AdAccount;
use App\Models\Campaign;
use App\Models\Client;
use App\Models\ClientLoginToken;
use App\Models\ClientPortalAccountScope;
use App\Models\MetricSnapshot;
use App\Models\PlatformConnection;
use App\Models\User;
use App\Models\UserInvitation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ClientPortalApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        config()->set('services.google_workspace.frontend_url', 'http://frontend.test');
        config()->set('services.google_workspace.allowed_domains', ['brandshark.com']);
    }

    public function test_external_client_invitation_and_single_use_magic_link_create_a_portal_user(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $client = Client::create(['name' => 'Acme', 'slug' => 'acme']);

        $response = $this->actingAs($admin)->postJson('/api/user-management/invitations', [
            'name' => 'Client Person', 'email' => 'person@outside.example', 'role' => 'client', 'client_ids' => [$client->id],
        ])->assertCreated()->assertJsonPath('role', 'client');
        $invitation = UserInvitation::findOrFail($response->json('id'));

        $this->postJson('/api/auth/client/magic-link', ['email' => $invitation->email])->assertAccepted()
            ->assertJsonPath('message', 'If that email has client access, a secure sign-in link has been sent.');
        $this->assertDatabaseCount('client_login_tokens', 1);

        ClientLoginToken::query()->delete();
        $rawToken = str_repeat('client-token-', 5);
        ClientLoginToken::create([
            'user_invitation_id' => $invitation->id, 'email' => $invitation->email,
            'token_hash' => hash('sha256', $rawToken), 'expires_at' => now()->addMinutes(15),
        ]);
        $this->postJson('/api/auth/client/magic-link/consume', ['token' => $rawToken])
            ->assertOk()->assertJsonPath('user.role', 'client')->assertJsonPath('redirect', '/portal');

        $user = User::where('email', $invitation->email)->firstOrFail();
        $this->assertTrue($user->clients()->whereKey($client->id)->exists());
        $this->assertNotNull($invitation->fresh()->accepted_at);
        $this->postJson('/api/auth/client/magic-link/consume', ['token' => $rawToken])->assertUnprocessable();
    }

    public function test_magic_link_request_is_neutral_for_unknown_and_staff_emails(): void
    {
        User::factory()->create(['role' => 'viewer', 'email' => 'staff@brandshark.com']);
        foreach (['missing@example.com', 'staff@brandshark.com'] as $email) {
            $this->postJson('/api/auth/client/magic-link', ['email' => $email])->assertAccepted()
                ->assertJsonPath('message', 'If that email has client access, a secure sign-in link has been sent.');
        }
        $this->assertDatabaseCount('client_login_tokens', 0);
    }

    public function test_selected_campaign_scope_filters_totals_rows_and_direct_drilldowns(): void
    {
        [$user, $client, $account, $allowed, $blocked] = $this->portalFixture();
        $dates = '?start_date=2026-11-01&end_date=2026-11-03';

        $this->actingAs($user)->getJson('/api/clients')->assertForbidden();
        $this->actingAs($user)->postJson('/api/performance/refresh')->assertForbidden();
        $this->actingAs($user)->getJson('/api/portal/clients')->assertOk()->assertJsonCount(1);
        $this->actingAs($user)->getJson("/api/portal/clients/{$client->id}/performance{$dates}")
            ->assertOk()->assertJsonPath('accounts.0.metrics.spend', 100)->assertJsonPath('currency_groups.0.metrics.spend', 100);
        $this->actingAs($user)->getJson("/api/portal/accounts/{$account->id}/overview{$dates}")
            ->assertOk()->assertJsonPath('metrics.spend', 100)->assertJsonPath('visibility_mode', 'selected_campaigns');
        $this->actingAs($user)->getJson("/api/portal/accounts/{$account->id}/campaigns{$dates}")
            ->assertOk()->assertJsonCount(1, 'campaigns')->assertJsonPath('campaigns.0.campaign.id', $allowed->id);
        $this->actingAs($user)->getJson("/api/portal/accounts/{$account->id}/campaigns/{$blocked->id}/ad-groups{$dates}")->assertNotFound();
    }

    public function test_hidden_accounts_are_not_discoverable_and_admin_policy_validation_is_atomic(): void
    {
        [$user, $client, $account, $allowed] = $this->portalFixture();
        $admin = User::factory()->create(['role' => 'admin']);
        $otherClient = Client::create(['name' => 'Other', 'slug' => 'other']);
        $otherAccount = AdAccount::create([
            'client_id' => $otherClient->id, 'platform_connection_id' => $account->platform_connection_id, 'platform' => 'google',
            'external_id' => 'other', 'name' => 'Other Ads', 'currency' => 'INR', 'timezone' => 'UTC', 'status' => 'active',
        ]);
        $otherCampaign = Campaign::create(['ad_account_id' => $otherAccount->id, 'external_id' => 'other-c', 'name' => 'Other campaign']);

        $this->actingAs($admin)->putJson("/api/clients/{$client->id}/portal-access", [
            'portal_enabled' => true,
            'accounts' => [['ad_account_id' => $account->id, 'visibility_mode' => 'selected_campaigns', 'campaign_ids' => [$otherCampaign->id]]],
        ])->assertUnprocessable();
        $this->assertSame([$allowed->id], ClientPortalAccountScope::where('ad_account_id', $account->id)->firstOrFail()->campaigns()->pluck('campaigns.id')->all());

        ClientPortalAccountScope::where('ad_account_id', $account->id)->update(['visibility_mode' => 'hidden']);
        $dates = '?start_date=2026-11-01&end_date=2026-11-03';
        $this->actingAs($user)->getJson("/api/portal/accounts/{$account->id}/overview{$dates}")->assertNotFound();
    }

    public function test_filtered_pdf_excel_and_csv_exports_download(): void
    {
        [$user, , $account] = $this->portalFixture();
        $dates = '?start_date=2026-11-01&end_date=2026-11-03';
        $this->actingAs($user)->get("/api/portal/accounts/{$account->id}/export/pdf{$dates}")
            ->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->actingAs($user)->get("/api/portal/accounts/{$account->id}/export/xlsx{$dates}")
            ->assertOk()->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $csv = $this->actingAs($user)->get("/api/portal/accounts/{$account->id}/export/csv{$dates}");
        $csv->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8')
            ->assertDownload('acme-acme-ads-2026-11-01-2026-11-03.csv');
        $this->assertStringContainsString('Allowed', $csv->streamedContent());
        $this->assertStringNotContainsString('Blocked', $csv->streamedContent());
    }

    private function portalFixture(): array
    {
        $user = User::factory()->create(['role' => 'client']);
        $client = Client::create(['name' => 'Acme', 'slug' => 'acme', 'currency' => 'INR', 'portal_enabled' => true]);
        $client->users()->attach($user);
        $connection = PlatformConnection::create(['platform' => 'google', 'name' => 'MCC', 'scope' => 'agency', 'credentials' => ['refresh_token' => 'secret'], 'status' => 'healthy']);
        $account = AdAccount::create(['client_id' => $client->id, 'platform_connection_id' => $connection->id, 'platform' => 'google', 'external_id' => 'acme', 'name' => 'Acme Ads', 'currency' => 'INR', 'timezone' => 'Asia/Kolkata', 'status' => 'active']);
        $allowed = Campaign::create(['ad_account_id' => $account->id, 'external_id' => 'allowed', 'name' => 'Allowed']);
        $blocked = Campaign::create(['ad_account_id' => $account->id, 'external_id' => 'blocked', 'name' => 'Blocked']);
        $scope = ClientPortalAccountScope::create(['client_id' => $client->id, 'ad_account_id' => $account->id, 'visibility_mode' => 'selected_campaigns']);
        $scope->campaigns()->attach($allowed);
        foreach ([[$allowed, 100, 5], [$blocked, 900, 45]] as [$campaign, $spend, $leads]) {
            MetricSnapshot::create([
                'ad_account_id' => $account->id, 'campaign_id' => $campaign->id, 'scope' => 'campaign', 'scope_key' => 'campaign:'.$campaign->id,
                'metric_date' => '2026-11-02', 'currency' => 'INR', 'spend' => $spend, 'leads' => $leads, 'collected_at' => now(),
            ]);
        }

        return [$user, $client, $account, $allowed, $blocked];
    }
}
