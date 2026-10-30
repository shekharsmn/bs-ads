<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('alert_incidents', function (Blueprint $table) {
            $table->foreignId('ad_group_id')->nullable()->after('campaign_id')->constrained()->nullOnDelete();
            $table->foreignId('ad_id')->nullable()->after('ad_group_id')->constrained()->nullOnDelete();
            $table->string('currency', 8)->nullable()->after('summary');
            $table->decimal('observed_spend', 20, 6)->nullable()->after('currency');
            $table->decimal('baseline_spend', 20, 6)->nullable()->after('observed_spend');
            $table->timestamp('observed_period_start')->nullable()->after('threshold_value');
            $table->timestamp('observed_period_end')->nullable()->after('observed_period_start');
            $table->timestamp('baseline_period_start')->nullable()->after('observed_period_end');
            $table->timestamp('baseline_period_end')->nullable()->after('baseline_period_start');
        });
    }

    public function down(): void
    {
        Schema::table('alert_incidents', function (Blueprint $table) {
            $table->dropConstrainedForeignId('ad_id');
            $table->dropConstrainedForeignId('ad_group_id');
            $table->dropColumn([
                'currency',
                'observed_spend',
                'baseline_spend',
                'observed_period_start',
                'observed_period_end',
                'baseline_period_start',
                'baseline_period_end',
            ]);
        });
    }
};
