<?php

namespace Tests\MySql;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ShopifyMigrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (! app()->environment('testing') || config('database.default') !== 'mysql' || config('database.connections.mysql.database') !== 'bs_ads_shopify_test' || config('database.connections.mysql.username') !== 'bs_ads_test' || config('database.connections.mysql.host') !== '127.0.0.1' || (int) config('database.connections.mysql.port') !== 43316) {
            throw new \RuntimeException('Refusing migration test on a non-disposable database.');
        }
        Artisan::call('migrate:fresh', ['--force' => true]);
    }

    public function test_full_chain_constraints_and_repeated_up_are_valid_on_mysql(): void
    {
        foreach (glob(database_path('migrations/2026_11_04_*.php')) as $file) {
            (require $file)->up();
        }
        foreach (['shop_apps', 'shop_stores', 'shop_installs', 'shop_links', 'shop_sync_runs', 'shop_orders', 'shop_sales', 'shop_hooks', 'shop_privacy'] as $table) {
            $this->assertTrue(Schema::hasTable($table));
            foreach (Schema::getIndexes($table) as $index) {
                $this->assertLessThanOrEqual(48, strlen($index['name']));
            }
            foreach (Schema::getForeignKeys($table) as $key) {
                $this->assertLessThanOrEqual(48, strlen($key['name']));
            }
        }
        $this->assertCount(2, Schema::getForeignKeys('performance_pulse_destination'));
    }

    public function test_partial_constraint_failure_is_repaired_without_losing_rows(): void
    {
        Schema::table('shop_installs', fn ($t) => $t->dropForeign('shop_inst_app_fk'));
        (require database_path('migrations/2026_11_04_010000_create_shop_connections.php'))->up();
        $this->assertCount(2, Schema::getForeignKeys('shop_installs'));
        Schema::table('performance_pulse_destination', fn ($t) => $t->dropForeign('pulse_dest_notif_fk'));
        (require database_path('migrations/2026_11_04_000000_repair_pulse_destination_key.php'))->up();
        $this->assertCount(2, Schema::getForeignKeys('performance_pulse_destination'));
    }

    public function test_deadlines_work_with_legacy_timestamp_defaults_and_forward_repair(): void
    {
        $original = (int) DB::selectOne('SELECT @@SESSION.explicit_defaults_for_timestamp AS enabled')->enabled;
        try {
            DB::statement('SET SESSION explicit_defaults_for_timestamp = OFF');
            $migration = require database_path('migrations/2026_11_04_020000_create_shop_sync_data.php');
            $migration->down();
            $migration->up();
            $this->assertSame('datetime', Schema::getColumnType('shop_sync_runs', 'until_at'));

            Schema::table('shop_links', fn ($t) => $t->timestamp('expires_at')->useCurrent()->change());
            (require database_path('migrations/2026_11_04_040000_normalize_shop_deadlines.php'))->up();
            $this->assertSame('datetime', Schema::getColumnType('shop_links', 'expires_at'));
            $column = collect(Schema::getColumns('shop_links'))->firstWhere('name', 'expires_at');
            $this->assertNull($column['default']);
        } finally {
            DB::statement('SET SESSION explicit_defaults_for_timestamp = '.$original);
        }
    }

    public function test_shopify_migrations_can_roll_back_and_reapply_on_disposable_mysql(): void
    {
        $files = glob(database_path('migrations/2026_11_04_0[123]0000_*.php'));
        foreach (array_reverse($files) as $file) {
            (require $file)->down();
        }
        foreach ($files as $file) {
            (require $file)->up();
        }
        $this->assertTrue(Schema::hasTable('shop_sales'));
    }

    public function test_each_schema_phase_resumes_after_a_committed_create_is_interrupted(): void
    {
        $files = glob(database_path('migrations/2026_11_04_0[123]0000_*.php'));
        foreach ($files as $phase => $target) {
            foreach (array_reverse($files) as $file) {
                (require $file)->down();
            }
            foreach (array_slice($files, 0, $phase) as $file) {
                (require $file)->up();
            }
            $dispatcher = DB::getEventDispatcher();
            DB::setEventDispatcher(clone $dispatcher);
            DB::listen(function ($event) {
                if (str_starts_with(strtolower($event->sql), 'create table `shop_')) {
                    throw new \RuntimeException('injected_after_committed_create');
                }
            });
            try {
                (require $target)->up();
                $this->fail('The DDL fault was not injected.');
            } catch (\RuntimeException $e) {
                $this->assertSame('injected_after_committed_create', $e->getMessage());
            } finally {
                DB::setEventDispatcher($dispatcher);
            }
            foreach ($files as $file) {
                (require $file)->up();
            }
            $this->assertTrue(Schema::hasTable('shop_privacy'));
            $this->assertCount(2, Schema::getForeignKeys('shop_installs'));
        }
    }
}
