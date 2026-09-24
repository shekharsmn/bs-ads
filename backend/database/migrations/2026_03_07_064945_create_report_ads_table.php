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
        Schema::create('report_ads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('report_id')->constrained()->cascadeOnDelete();
            $table->string('meta_ad_id');
            $table->string('ad_name');
            $table->string('format');
            $table->decimal('spend', 10, 2)->default(0);
            $table->unsignedBigInteger('impressions')->default(0);
            $table->decimal('ctr', 8, 4)->default(0);
            $table->decimal('cpc', 8, 2)->default(0);
            $table->decimal('frequency', 8, 2)->default(0);
            $table->decimal('leads', 8, 2)->default(0);
            $table->json('perf_flags')->nullable();
            $table->json('copy')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('report_ads');
    }
};
