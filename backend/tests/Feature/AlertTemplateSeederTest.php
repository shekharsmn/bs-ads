<?php

namespace Tests\Feature;

use App\Models\AlertRule;
use App\Models\AlertTemplate;
use Database\Seeders\AlertTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AlertTemplateSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_idempotently_seeds_templates_without_enabling_global_policies(): void
    {
        $this->seed(AlertTemplateSeeder::class);
        $this->seed(AlertTemplateSeeder::class);

        $this->assertSame(6, AlertTemplate::count());
        $this->assertSame(0, AlertRule::count());
        $this->assertDatabaseHas('alert_templates', [
            'key' => 'connection-error',
            'operator' => 'connection_error',
            'severity' => 'critical',
            'active' => true,
        ]);
    }
}
