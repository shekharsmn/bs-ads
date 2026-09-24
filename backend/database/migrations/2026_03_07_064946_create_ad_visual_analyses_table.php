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
        Schema::create('ad_visual_analyses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('report_ad_id')->constrained('report_ads')->cascadeOnDelete();
            $table->enum('type', ['static', 'video'])->default('static');
            $table->longText('analysis_text')->nullable();
            $table->text('transcript')->nullable();
            $table->boolean('has_frames')->default(false);
            $table->text('error')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ad_visual_analyses');
    }
};
