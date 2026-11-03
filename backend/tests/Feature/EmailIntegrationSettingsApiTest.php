<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\EmailIntegration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class EmailIntegrationSettingsApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_admin_can_manage_email_integration_settings(): void
    {
        $viewer = User::factory()->create(['role' => UserRole::Viewer]);

        $this->actingAs($viewer)->getJson('/api/settings/email-integration')->assertForbidden();
        $this->putJson('/api/settings/email-integration', $this->payload())->assertForbidden();
        $this->postJson('/api/settings/email-integration/test', ['recipient' => 'ops@example.com'])->assertForbidden();
    }

    public function test_admin_can_save_encrypted_amazon_ses_smtp_settings_without_exposing_secrets(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);

        $this->actingAs($admin)->putJson('/api/settings/email-integration', $this->payload())
            ->assertOk()
            ->assertJsonPath('provider', 'amazon_ses')
            ->assertJsonPath('configured', true)
            ->assertJsonPath('endpoint', 'email-smtp.ap-south-1.amazonaws.com')
            ->assertJsonPath('smtp_username_hint', '••••USER')
            ->assertJsonMissingPath('smtp_username')
            ->assertJsonMissingPath('smtp_password');

        $stored = DB::table('email_integrations')->first();
        $this->assertNotSame('AKIASESUSER', $stored->smtp_username);
        $this->assertNotSame('ses-secret-password', $stored->smtp_password);
        $this->assertSame('smtp', config('mail.default'));
        $this->assertSame('email-smtp.ap-south-1.amazonaws.com', config('mail.mailers.smtp.host'));
    }

    public function test_blank_credentials_keep_the_existing_encrypted_values(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->actingAs($admin)->putJson('/api/settings/email-integration', $this->payload())->assertOk();
        $before = EmailIntegration::query()->firstOrFail();

        $this->putJson('/api/settings/email-integration', $this->payload([
            'smtp_username' => '',
            'smtp_password' => '',
            'from_name' => 'SharkAds Operations',
        ]))->assertOk()->assertJsonPath('from_name', 'SharkAds Operations');

        $after = EmailIntegration::query()->firstOrFail();
        $this->assertSame($before->smtp_username, $after->smtp_username);
        $this->assertSame($before->smtp_password, $after->smtp_password);
    }

    public function test_admin_can_send_a_test_email_with_the_saved_integration(): void
    {
        Mail::fake();
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->actingAs($admin)->putJson('/api/settings/email-integration', $this->payload())->assertOk();

        $this->postJson('/api/settings/email-integration/test', ['recipient' => 'ops@example.com'])
            ->assertOk()
            ->assertJsonPath('message', 'Test email sent.')
            ->assertJsonPath('integration.last_test_status', 'success');

        $this->assertDatabaseHas('email_integrations', ['last_test_status' => 'success']);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'enabled' => true,
            'region' => 'ap-south-1',
            'port' => 587,
            'smtp_username' => 'AKIASESUSER',
            'smtp_password' => 'ses-secret-password',
            'from_address' => 'notifications@example.com',
            'from_name' => 'SharkAds',
        ], $overrides);
    }
}
