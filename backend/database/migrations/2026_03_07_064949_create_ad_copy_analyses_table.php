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
        Schema::create('ad_copy_analyses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('report_ad_id')->constrained('report_ads')->cascadeOnDelete();
            $table->unsignedTinyInteger('score')->default(0);
            $table->json('issues')->nullable();
            $table->json('suggestions')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ad_copy_analyses');
    }
};
