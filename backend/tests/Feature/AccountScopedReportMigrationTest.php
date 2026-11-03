<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AccountScopedReportMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_migration_can_roll_back_and_resume_after_committed_ddl(): void
    {
        $migration = require database_path('migrations/2026_10_29_000000_add_account_scope_to_performance_reports.php');

        $migration->down();

        $this->assertFalse(Schema::hasColumn('report_schedules', 'ad_account_id'));
        $this->assertFalse(Schema::hasColumn('report_schedules', 'scope_key'));
        $this->assertTrue(Schema::hasIndex('report_schedules', 'report_schedules_client_id_frequency_unique'));

        $migration->up();
        $migration->up();

        $this->assertTrue(Schema::hasColumns('report_schedules', ['ad_account_id', 'scope_key']));
        $this->assertTrue(Schema::hasIndex('report_schedules', 'report_schedules_client_id_index'));
        $this->assertTrue(Schema::hasIndex('report_schedules', 'report_schedules_scope_frequency_unique'));
        $this->assertTrue(Schema::hasColumn('report_runs', 'ad_account_id'));
        $this->assertTrue(Schema::hasIndex('report_runs', 'report_runs_scope_created_index'));
    }
}
