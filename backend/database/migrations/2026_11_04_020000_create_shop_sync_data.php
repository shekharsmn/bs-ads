<?php

use App\Support\SchemaRepair;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('shop_sync_runs')) {
            Schema::create('shop_sync_runs', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('install_id');
                $t->unsignedInteger('generation');
                $t->string('kind', 24);
                $t->string('status', 24)->default('pending');
                $t->string('stage', 24)->default('start');
                $t->timestamp('from_at')->nullable();
                $t->dateTime('until_at');
                $t->string('operation_gid', 191)->nullable();
                $t->mediumText('checkpoint')->nullable();
                $t->unsignedBigInteger('records')->default(0);
                $t->string('error_code', 100)->nullable();
                $t->timestamp('completed_at')->nullable();
                $t->timestamps();
                $t->index(['install_id', 'status'], 'shop_run_inst_status_ix');
            });
        }
        if (! Schema::hasTable('shop_orders')) {
            Schema::create('shop_orders', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('store_id');
                $t->unsignedBigInteger('install_id');
                $t->unsignedBigInteger('run_id')->nullable();
                SchemaRepair::identifier($t->string('gid', 191));
                $t->dateTime('placed_at');
                $t->dateTime('source_updated_at');
                $t->boolean('test')->default(false);
                $t->dateTime('cancelled_at')->nullable();
                $t->boolean('deleted')->default(false);
                $t->string('financial_status', 40)->nullable();
                $t->string('sync_state', 24)->default('pending');
                SchemaRepair::identifier($t->string('sales_key', 36))->nullable();
                $t->timestamps();
                $t->unique(['store_id', 'gid'], 'shop_ord_store_gid_uq');
                $t->index(['store_id', 'placed_at'], 'shop_ord_date_ix');
                $t->index(['run_id', 'sync_state'], 'shop_ord_run_state_ix');
            });
        }
        if (! Schema::hasTable('shop_sales')) {
            Schema::create('shop_sales', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('store_id');
                $t->unsignedBigInteger('order_id');
                $t->unsignedBigInteger('install_id');
                SchemaRepair::identifier($t->string('gid', 191));
                SchemaRepair::identifier($t->string('sync_key', 36));
                SchemaRepair::identifier($t->string('agreement_gid', 191));
                $t->dateTime('happened_at');
                $t->string('line_type', 40);
                $t->string('action_type', 40);
                $t->string('currency', 8);
                $t->decimal('amount', 24, 6);
                $t->decimal('tax', 24, 6);
                $t->decimal('discount', 24, 6);
                $t->decimal('net', 24, 6);
                $t->timestamps();
                $t->unique(['order_id', 'sync_key', 'gid'], 'shop_sale_snapshot_uq');
                $t->index(['store_id', 'happened_at', 'currency'], 'shop_sale_date_ix');
            });
        }
        SchemaRepair::columns('shop_sync_runs', ['id', 'install_id', 'generation', 'kind', 'status', 'stage', 'from_at', 'until_at', 'operation_gid', 'checkpoint', 'records', 'error_code', 'completed_at', 'created_at', 'updated_at']);
        SchemaRepair::columns('shop_orders', ['id', 'store_id', 'install_id', 'run_id', 'gid', 'placed_at', 'source_updated_at', 'test', 'cancelled_at', 'deleted', 'financial_status', 'sync_state', 'sales_key', 'created_at', 'updated_at']);
        SchemaRepair::columns('shop_sales', ['id', 'store_id', 'order_id', 'install_id', 'gid', 'sync_key', 'agreement_gid', 'happened_at', 'line_type', 'action_type', 'currency', 'amount', 'tax', 'discount', 'net', 'created_at', 'updated_at']);
        SchemaRepair::index('shop_sync_runs', ['install_id', 'status'], 'shop_run_inst_status_ix', false);
        SchemaRepair::index('shop_orders', ['store_id', 'gid'], 'shop_ord_store_gid_uq', true);
        SchemaRepair::index('shop_orders', ['store_id', 'placed_at'], 'shop_ord_date_ix', false);
        SchemaRepair::index('shop_orders', ['run_id', 'sync_state'], 'shop_ord_run_state_ix', false);
        SchemaRepair::index('shop_sales', ['order_id', 'sync_key', 'gid'], 'shop_sale_snapshot_uq', true);
        SchemaRepair::index('shop_sales', ['store_id', 'happened_at', 'currency'], 'shop_sale_date_ix', false);
        foreach ([['shop_sync_runs', 'install_id', 'shop_installs', 'shop_run_inst_fk'], ['shop_orders', 'store_id', 'shop_stores', 'shop_ord_store_fk'],
            ['shop_orders', 'install_id', 'shop_installs', 'shop_ord_inst_fk'], ['shop_orders', 'run_id', 'shop_sync_runs', 'shop_ord_run_fk', 'set null'],
            ['shop_sales', 'store_id', 'shop_stores', 'shop_sale_store_fk'], ['shop_sales', 'order_id', 'shop_orders', 'shop_sale_order_fk'],
            ['shop_sales', 'install_id', 'shop_installs', 'shop_sale_inst_fk']] as $key) {
            SchemaRepair::foreign(...$key);
        }
    }

    public function down(): void
    {
        foreach (['shop_sales', 'shop_orders', 'shop_sync_runs'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
