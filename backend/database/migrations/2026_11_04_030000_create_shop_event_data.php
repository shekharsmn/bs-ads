<?php

use App\Support\SchemaRepair;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('shop_hooks')) {
            Schema::create('shop_hooks', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('app_id');
                $t->unsignedBigInteger('install_id')->nullable();
                $t->unsignedInteger('generation')->nullable();
                SchemaRepair::identifier($t->string('domain', 191));
                $t->string('topic', 80);
                SchemaRepair::identifier($t->string('delivery_id', 191));
                $t->mediumText('payload')->nullable();
                $t->string('status', 24)->default('pending');
                $t->string('error_code', 80)->nullable();
                $t->timestamps();
                $t->unique(['app_id', 'delivery_id'], 'shop_hook_delivery_uq');
                $t->index(['status', 'created_at'], 'shop_hook_status_ix');
            });
        }
        if (! Schema::hasTable('shop_privacy')) {
            Schema::create('shop_privacy', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('app_id');
                SchemaRepair::identifier($t->string('domain', 191));
                SchemaRepair::identifier($t->string('request_key', 191));
                $t->string('topic', 80);
                $t->json('order_gids')->nullable();
                $t->string('status', 24)->default('pending');
                $t->timestamp('completed_at')->nullable();
                $t->timestamps();
                $t->unique(['app_id', 'request_key'], 'shop_privacy_req_uq');
            });
        }
        SchemaRepair::columns('shop_hooks', ['id', 'app_id', 'install_id', 'generation', 'domain', 'topic', 'delivery_id', 'payload', 'status', 'error_code', 'created_at', 'updated_at']);
        SchemaRepair::columns('shop_privacy', ['id', 'app_id', 'domain', 'request_key', 'topic', 'order_gids', 'status', 'completed_at', 'created_at', 'updated_at']);
        SchemaRepair::index('shop_hooks', ['app_id', 'delivery_id'], 'shop_hook_delivery_uq', true);
        SchemaRepair::index('shop_hooks', ['status', 'created_at'], 'shop_hook_status_ix', false);
        SchemaRepair::index('shop_privacy', ['app_id', 'request_key'], 'shop_privacy_req_uq', true);
        foreach ([['shop_hooks', 'app_id', 'shop_apps', 'shop_hook_app_fk'], ['shop_hooks', 'install_id', 'shop_installs', 'shop_hook_inst_fk', 'set null'],
            ['shop_privacy', 'app_id', 'shop_apps', 'shop_privacy_app_fk']] as $key) {
            SchemaRepair::foreign(...$key);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('shop_privacy');
        Schema::dropIfExists('shop_hooks');
    }
};
