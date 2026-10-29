<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ad_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $table->string('external_id');
            $table->string('name');
            $table->string('status', 32)->nullable();
            $table->string('type', 64)->nullable();
            $table->decimal('daily_budget', 18, 4)->nullable();
            $table->timestamps();
            $table->unique(['campaign_id', 'external_id']);
            $table->index(['campaign_id', 'status']);
        });

        Schema::create('ads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ad_group_id')->constrained()->cascadeOnDelete();
            $table->string('external_id');
            $table->string('name');
            $table->string('status', 32)->nullable();
            $table->string('type', 64)->nullable();
            $table->timestamps();
            $table->unique(['ad_group_id', 'external_id']);
            $table->index(['ad_group_id', 'status']);
        });

        Schema::table('metric_snapshots', function (Blueprint $table) {
            $table->foreignId('ad_group_id')->nullable()->after('campaign_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ad_id')->nullable()->after('ad_group_id')->constrained()->cascadeOnDelete();
            $table->index(['ad_group_id', 'metric_date', 'currency'], 'metric_ad_group_date_currency');
            $table->index(['ad_id', 'metric_date', 'currency'], 'metric_ad_date_currency');
        });
    }

    public function down(): void
    {
        Schema::table('metric_snapshots', function (Blueprint $table) {
            $table->dropIndex('metric_ad_date_currency');
            $table->dropIndex('metric_ad_group_date_currency');
            $table->dropConstrainedForeignId('ad_id');
            $table->dropConstrainedForeignId('ad_group_id');
        });
        Schema::dropIfExists('ads');
        Schema::dropIfExists('ad_groups');
    }
};
