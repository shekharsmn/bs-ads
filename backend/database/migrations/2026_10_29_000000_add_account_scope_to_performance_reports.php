<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('report_schedules', function (Blueprint $table) {
            $table->dropUnique('report_schedules_client_id_frequency_unique');
            $table->foreignId('ad_account_id')->nullable()->after('client_id')->constrained()->cascadeOnDelete();
            $table->string('scope_key', 80)->default('client')->after('ad_account_id');
            $table->unique(['client_id', 'scope_key', 'frequency'], 'report_schedules_scope_frequency_unique');
        });

        Schema::table('report_runs', function (Blueprint $table) {
            $table->foreignId('ad_account_id')->nullable()->after('client_id')->constrained()->nullOnDelete();
            $table->index(['client_id', 'ad_account_id', 'created_at'], 'report_runs_scope_created_index');
        });
    }

    public function down(): void
    {
        Schema::table('report_runs', function (Blueprint $table) {
            $table->dropIndex('report_runs_scope_created_index');
            $table->dropConstrainedForeignId('ad_account_id');
        });

        Schema::table('report_schedules', function (Blueprint $table) {
            $table->dropUnique('report_schedules_scope_frequency_unique');
            $table->dropConstrainedForeignId('ad_account_id');
            $table->dropColumn('scope_key');
            $table->unique(['client_id', 'frequency']);
        });
    }
};
