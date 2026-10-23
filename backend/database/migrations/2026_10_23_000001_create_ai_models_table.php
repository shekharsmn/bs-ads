<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_models', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 32);
            $table->string('model', 120);
            $table->string('label', 160);
            $table->json('capabilities');
            $table->decimal('input_price', 12, 6)->default(0);
            $table->decimal('output_price', 12, 6)->default(0);
            $table->boolean('enabled')->default(true)->index();
            $table->boolean('is_default')->default(false)->index();
            $table->timestamps();
            $table->unique(['provider', 'model']);
        });

        DB::table('ai_models')->insert([
            'provider' => 'gemini',
            'model' => 'gemini-2.5-flash-lite',
            'label' => 'Gemini 2.5 Flash Lite',
            'capabilities' => json_encode(['text', 'vision', 'audio', 'structured_json']),
            'input_price' => 0.075,
            'output_price' => 0.30,
            'enabled' => true,
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_models');
    }
};
