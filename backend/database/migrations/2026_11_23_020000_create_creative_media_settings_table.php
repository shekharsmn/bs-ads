<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('creative_media_settings', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 40)->unique();
            $table->boolean('enabled')->default(false);
            $table->text('api_key')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('creative_media_settings');
    }
};
