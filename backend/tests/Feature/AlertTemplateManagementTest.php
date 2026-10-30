<?php

namespace Tests\Feature;

use App\Models\AlertRule;
use App\Models\AlertTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AlertTemplateManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_update_a_template(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $template = AlertTemplate::create($this->templateData());

        $this->actingAs($admin)->putJson('/api/alert-templates/'.$template->id, [
            ...$this->templateData(),
            'name' => 'CPL increased materially',
            'threshold' => 30,
        ])->assertOk()
            ->assertJsonPath('name', 'CPL increased materially')
            ->assertJsonPath('threshold', '30.000000');

        $this->assertDatabaseHas('alert_templates', ['id' => $template->id, 'name' => 'CPL increased materially', 'threshold' => 30]);
    }

    public function test_deleting_a_template_preserves_existing_policies(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $template = AlertTemplate::create($this->templateData());
        $policy = AlertRule::create([
            'alert_template_id' => $template->id,
            'name' => $template->name,
            'metric' => $template->metric,
            'operator' => $template->operator,
            'threshold' => $template->threshold,
            'severity' => $template->severity,
            'window_days' => 7,
        ]);

        $this->actingAs($admin)->deleteJson('/api/alert-templates/'.$template->id)->assertNoContent();

        $this->assertDatabaseMissing('alert_templates', ['id' => $template->id]);
        $this->assertDatabaseHas('alert_rules', ['id' => $policy->id, 'alert_template_id' => null, 'threshold' => 25]);
    }

    public function test_non_admin_cannot_update_or_delete_templates(): void
    {
        $marketer = User::factory()->create(['role' => 'marketer']);
        $template = AlertTemplate::create($this->templateData());

        $this->actingAs($marketer)->putJson('/api/alert-templates/'.$template->id, $this->templateData())->assertForbidden();
        $this->actingAs($marketer)->deleteJson('/api/alert-templates/'.$template->id)->assertForbidden();
    }

    private function templateData(): array
    {
        return [
            'key' => 'cpl-increase',
            'name' => 'Cost per lead increased',
            'description' => 'CPL is higher than the preceding period.',
            'metric' => 'cpl',
            'operator' => 'percentage_increase',
            'threshold' => 25,
            'severity' => 'warning',
            'active' => true,
        ];
    }
}
