<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('ad_video_metrics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('report_ad_id')->constrained('report_ads')->cascadeOnDelete();
            $table->decimal('hook_rate', 6, 2)->default(0);
            $table->decimal('thruplay_rate', 6, 2)->default(0);
            $table->decimal('avg_watch_time', 8, 2)->default(0);
            $table->decimal('duration', 8, 2)->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ad_video_metrics');
    }
};
