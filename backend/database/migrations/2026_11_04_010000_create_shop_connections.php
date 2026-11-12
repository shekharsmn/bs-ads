<?php

use App\Support\SchemaRepair;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('shop_apps')) {
            Schema::create('shop_apps', function (Blueprint $t) {
                $t->id();
                SchemaRepair::identifier($t->string('slug', 80));
                $t->string('name');
                SchemaRepair::identifier($t->string('client_key', 191));
                $t->text('secret');
                $t->text('previous_secret')->nullable();
                $t->string('distribution', 16);
                $t->unsignedBigInteger('client_id')->nullable();
                $t->text('install_url')->nullable();
                $t->boolean('data_approved')->default(false);
                $t->boolean('active')->default(true);
                $t->timestamps();
                $t->unique('slug', 'shop_app_slug_uq');
                $t->unique('client_key', 'shop_app_key_uq');
            });
        }
        if (! Schema::hasTable('shop_stores')) {
            Schema::create('shop_stores', function (Blueprint $t) {
                $t->id();
                SchemaRepair::identifier($t->string('gid', 191));
                SchemaRepair::identifier($t->string('domain', 191));
                $t->string('name');
                $t->string('timezone', 80);
                $t->string('currency', 8);
                $t->unsignedBigInteger('client_id')->nullable();
                $t->unsignedBigInteger('active_install_id')->nullable();
                $t->timestamp('history_from')->nullable();
                $t->timestamp('synced_until')->nullable();
                $t->timestamp('last_synced_at')->nullable();
                $t->boolean('history_complete')->default(false);
                $t->boolean('limited_history')->default(true);
                $t->timestamps();
                $t->unique('gid', 'shop_store_gid_uq');
                $t->unique('domain', 'shop_store_domain_uq');
                $t->unique('client_id', 'shop_store_client_uq');
            });
        }
        if (! Schema::hasTable('shop_installs')) {
            Schema::create('shop_installs', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('app_id');
                $t->unsignedBigInteger('store_id');
                $t->text('tokens')->nullable();
                $t->timestamp('expires_at')->nullable();
                $t->timestamp('refresh_expires_at')->nullable();
                $t->json('scopes')->nullable();
                $t->unsignedInteger('generation')->default(1);
                $t->string('status', 24)->default('active');
                $t->string('error_code', 80)->nullable();
                $t->timestamp('installed_at')->nullable();
                $t->timestamp('revoked_at')->nullable();
                $t->timestamps();
                $t->unique(['app_id', 'store_id'], 'shop_inst_app_store_uq');
            });
        }
        if (! Schema::hasTable('shop_links')) {
            Schema::create('shop_links', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('client_id');
                $t->unsignedBigInteger('user_id');
                SchemaRepair::identifier($t->string('token_hash', 64));
                $t->dateTime('expires_at');
                $t->timestamp('used_at')->nullable();
                $t->boolean('replace')->default(false);
                $t->timestamps();
                $t->unique('token_hash', 'shop_link_token_uq');
            });
        }
        SchemaRepair::columns('shop_apps', ['id', 'slug', 'name', 'client_key', 'secret', 'previous_secret', 'distribution', 'client_id', 'install_url', 'data_approved', 'active', 'created_at', 'updated_at']);
        SchemaRepair::columns('shop_stores', ['id', 'gid', 'domain', 'name', 'timezone', 'currency', 'client_id', 'active_install_id', 'history_from', 'synced_until', 'last_synced_at', 'history_complete', 'limited_history', 'created_at', 'updated_at']);
        SchemaRepair::columns('shop_installs', ['id', 'app_id', 'store_id', 'tokens', 'expires_at', 'refresh_expires_at', 'scopes', 'generation', 'status', 'error_code', 'installed_at', 'revoked_at', 'created_at', 'updated_at']);
        SchemaRepair::columns('shop_links', ['id', 'client_id', 'user_id', 'token_hash', 'expires_at', 'used_at', 'replace', 'created_at', 'updated_at']);
        SchemaRepair::index('shop_apps', ['slug'], 'shop_app_slug_uq', true);
        SchemaRepair::index('shop_apps', ['client_key'], 'shop_app_key_uq', true);
        SchemaRepair::index('shop_stores', ['gid'], 'shop_store_gid_uq', true);
        SchemaRepair::index('shop_stores', ['domain'], 'shop_store_domain_uq', true);
        SchemaRepair::index('shop_stores', ['client_id'], 'shop_store_client_uq', true);
        SchemaRepair::index('shop_installs', ['app_id', 'store_id'], 'shop_inst_app_store_uq', true);
        SchemaRepair::index('shop_links', ['token_hash'], 'shop_link_token_uq', true);
        foreach ([['shop_apps', 'client_id', 'clients', 'shop_app_client_fk', 'set null'], ['shop_stores', 'client_id', 'clients', 'shop_store_client_fk', 'set null'],
            ['shop_installs', 'app_id', 'shop_apps', 'shop_inst_app_fk', 'cascade'], ['shop_installs', 'store_id', 'shop_stores', 'shop_inst_store_fk', 'cascade'],
            ['shop_links', 'client_id', 'clients', 'shop_link_client_fk', 'cascade'], ['shop_links', 'user_id', 'users', 'shop_link_user_fk', 'cascade']] as $key) {
            SchemaRepair::foreign(...$key);
        }
    }

    public function down(): void
    {
        foreach (['shop_links', 'shop_installs', 'shop_stores', 'shop_apps'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
