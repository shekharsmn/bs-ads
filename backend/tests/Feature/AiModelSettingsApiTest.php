<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\AdLibraryAudit;
use App\Models\AiModel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AiModelSettingsApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'ad_library_audit.providers.gemini.api_key' => 'test-gemini-key',
            'ad_library_audit.providers.openai.api_key' => null,
        ]);
    }

    public function test_only_admin_can_manage_ai_model_settings(): void
    {
        $viewer = User::factory()->create(['role' => UserRole::Viewer]);
        $this->actingAs($viewer)->getJson('/api/settings/ai-models')->assertForbidden();
        $this->postJson('/api/settings/ai-models', $this->payload())->assertForbidden();

        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->actingAs($admin)->getJson('/api/settings/ai-models')
            ->assertOk()
            ->assertJsonPath('models.0.key', 'gemini:gemini-2.5-flash-lite')
            ->assertJsonPath('providers.0.configured', true);
    }

    public function test_admin_can_add_edit_and_make_a_model_default(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);

        $created = $this->actingAs($admin)->postJson('/api/settings/ai-models', $this->payload())
            ->assertCreated()
            ->assertJsonPath('key', 'gemini:gemini-3.1-flash-lite')
            ->json();

        $this->putJson('/api/settings/ai-models/'.$created['id'], $this->payload([
            'label' => 'Gemini 3.1 Flash Lite Updated',
            'is_default' => true,
        ]))->assertOk()->assertJsonPath('is_default', true);

        $this->assertDatabaseHas('ai_models', ['id' => $created['id'], 'label' => 'Gemini 3.1 Flash Lite Updated', 'is_default' => true]);
        $this->assertDatabaseHas('ai_models', ['model' => 'gemini-2.5-flash-lite', 'is_default' => false]);
    }

    public function test_enabled_database_models_drive_the_audit_selector(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        AiModel::create($this->payload());
        config(['ad_library_audit.enabled' => true]);

        $this->actingAs($admin)->getJson('/api/ad-library/audit-models?media_depth=full_media')
            ->assertOk()
            ->assertJsonCount(2, 'models')
            ->assertJsonPath('models.1.key', 'gemini:gemini-3.1-flash-lite');

        AiModel::query()->where('model', 'gemini-3.1-flash-lite')->update(['enabled' => false]);
        $this->getJson('/api/ad-library/audit-models?media_depth=full_media')->assertOk()->assertJsonCount(1, 'models');
    }

    public function test_model_used_by_an_audit_must_be_disabled_instead_of_deleted(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $model = AiModel::query()->firstOrFail();
        AdLibraryAudit::create([
            'company_name' => 'Acme', 'company_type' => 'competitor', 'name' => 'Existing audit',
            'provider' => $model->provider, 'model' => $model->model, 'model_key' => $model->catalogKey(),
        ]);

        $this->actingAs($admin)->deleteJson('/api/settings/ai-models/'.$model->id)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('model');

        $this->putJson('/api/settings/ai-models/'.$model->id, $this->payload([
            'provider' => 'gemini',
            'model' => 'renamed-model',
        ]))->assertUnprocessable()->assertJsonValidationErrors('model');
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'provider' => 'gemini',
            'model' => 'gemini-3.1-flash-lite',
            'label' => 'Gemini 3.1 Flash Lite',
            'capabilities' => ['text', 'vision', 'audio', 'structured_json'],
            'input_price' => 0.1,
            'output_price' => 0.4,
            'enabled' => true,
            'is_default' => false,
        ], $overrides);
    }
}
