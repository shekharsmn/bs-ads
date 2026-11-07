<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // InnoDB may use the original composite unique index to support the
        // client_id foreign key. Give that constraint a replacement before
        // removing the unique index.
        Schema::whenTableDoesntHaveIndex('report_schedules', 'report_schedules_client_id_index', function (Blueprint $table) {
            $table->index('client_id', 'report_schedules_client_id_index');
        });

        Schema::whenTableHasIndex('report_schedules', 'report_schedules_client_id_frequency_unique', function (Blueprint $table) {
            $table->dropUnique('report_schedules_client_id_frequency_unique');
        });

        Schema::whenTableDoesntHaveColumn('report_schedules', 'ad_account_id', function (Blueprint $table) {
            $table->foreignId('ad_account_id')->nullable()->after('client_id')->constrained()->cascadeOnDelete();
        });

        Schema::whenTableDoesntHaveColumn('report_schedules', 'scope_key', function (Blueprint $table) {
            $table->string('scope_key', 80)->default('client')->after('ad_account_id');
        });

        Schema::whenTableDoesntHaveIndex('report_schedules', 'report_schedules_scope_frequency_unique', function (Blueprint $table) {
            $table->unique(['client_id', 'scope_key', 'frequency'], 'report_schedules_scope_frequency_unique');
        });

        Schema::whenTableDoesntHaveColumn('report_runs', 'ad_account_id', function (Blueprint $table) {
            $table->foreignId('ad_account_id')->nullable()->after('client_id')->constrained()->nullOnDelete();
        });

        Schema::whenTableDoesntHaveIndex('report_runs', 'report_runs_scope_created_index', function (Blueprint $table) {
            $table->index(['client_id', 'ad_account_id', 'created_at'], 'report_runs_scope_created_index');
        });
    }

    public function down(): void
    {
        Schema::whenTableHasIndex('report_runs', 'report_runs_scope_created_index', function (Blueprint $table) {
            $table->dropIndex('report_runs_scope_created_index');
        });

        Schema::whenTableHasColumn('report_runs', 'ad_account_id', function (Blueprint $table) {
            $table->dropConstrainedForeignId('ad_account_id');
        });

        // Restore the original unique index first. If account-scoped rows now
        // conflict at client scope, rollback fails before removing new schema.
        Schema::whenTableDoesntHaveIndex('report_schedules', 'report_schedules_client_id_frequency_unique', function (Blueprint $table) {
            $table->unique(['client_id', 'frequency'], 'report_schedules_client_id_frequency_unique');
        });

        Schema::whenTableHasIndex('report_schedules', 'report_schedules_scope_frequency_unique', function (Blueprint $table) {
            $table->dropUnique('report_schedules_scope_frequency_unique');
        });

        Schema::whenTableHasIndex('report_schedules', 'report_schedules_client_id_index', function (Blueprint $table) {
            $table->dropIndex('report_schedules_client_id_index');
        });

        Schema::whenTableHasColumn('report_schedules', 'ad_account_id', function (Blueprint $table) {
            $table->dropConstrainedForeignId('ad_account_id');
        });

        Schema::whenTableHasColumn('report_schedules', 'scope_key', function (Blueprint $table) {
            $table->dropColumn('scope_key');
        });
    }
};
