<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('clients', 'portal_enabled')) {
            Schema::table('clients', function (Blueprint $table) {
                $table->boolean('portal_enabled')->default(false)->after('active');
            });
        }

        if (! Schema::hasColumn('user_invitations', 'delivery_error')) {
            Schema::table('user_invitations', function (Blueprint $table) {
                $table->text('delivery_error')->nullable()->after('last_sent_at');
            });
        }

        if (! Schema::hasTable('client_login_tokens')) {
            Schema::create('client_login_tokens', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
                $table->foreignId('user_invitation_id')->nullable()->constrained()->cascadeOnDelete();
                $table->string('email')->index();
                $table->string('token_hash', 64)->unique();
                $table->timestamp('expires_at')->index();
                $table->timestamp('used_at')->nullable()->index();
                $table->string('requested_ip', 45)->nullable();
                $table->text('requested_user_agent')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('client_portal_account_scopes')) {
            Schema::create('client_portal_account_scopes', function (Blueprint $table) {
                $table->id();
                $table->foreignId('client_id')->constrained()->cascadeOnDelete();
                $table->foreignId('ad_account_id')->constrained()->cascadeOnDelete();
                $table->string('visibility_mode', 32)->default('hidden');
                $table->timestamps();
                $table->unique(['client_id', 'ad_account_id'], 'client_portal_account_scope_unique');
            });
        }

        if (! Schema::hasTable('client_portal_scope_campaign')) {
            Schema::create('client_portal_scope_campaign', function (Blueprint $table) {
                $table->foreignId('client_portal_account_scope_id')
                    ->constrained('client_portal_account_scopes', 'id', 'cp_scope_campaign_account_scope_fk')
                    ->cascadeOnDelete();
                $table->foreignId('campaign_id')
                    ->constrained('campaigns', 'id', 'cp_scope_campaign_campaign_fk')
                    ->cascadeOnDelete();
                $table->timestamps();
                $table->primary(['client_portal_account_scope_id', 'campaign_id'], 'client_portal_scope_campaign_primary');
            });

            return;
        }

        $foreignColumns = collect(Schema::getForeignKeys('client_portal_scope_campaign'))
            ->flatMap(fn (array $foreignKey) => $foreignKey['columns'])
            ->all();

        Schema::table('client_portal_scope_campaign', function (Blueprint $table) use ($foreignColumns) {
            if (! in_array('client_portal_account_scope_id', $foreignColumns, true)) {
                $table->foreign('client_portal_account_scope_id', 'cp_scope_campaign_account_scope_fk')
                    ->references('id')
                    ->on('client_portal_account_scopes')
                    ->cascadeOnDelete();
            }

            if (! in_array('campaign_id', $foreignColumns, true)) {
                $table->foreign('campaign_id', 'cp_scope_campaign_campaign_fk')
                    ->references('id')
                    ->on('campaigns')
                    ->cascadeOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_portal_scope_campaign');
        Schema::dropIfExists('client_portal_account_scopes');
        Schema::dropIfExists('client_login_tokens');

        if (Schema::hasColumn('user_invitations', 'delivery_error')) {
            Schema::table('user_invitations', fn (Blueprint $table) => $table->dropColumn('delivery_error'));
        }

        if (Schema::hasColumn('clients', 'portal_enabled')) {
            Schema::table('clients', fn (Blueprint $table) => $table->dropColumn('portal_enabled'));
        }
    }
};
