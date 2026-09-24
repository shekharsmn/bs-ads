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
        Schema::table('reports', function (Blueprint $table) {
            $table->unsignedBigInteger('gemini_input_tokens')->default(0)->after('completed_at');
            $table->unsignedBigInteger('gemini_output_tokens')->default(0)->after('gemini_input_tokens');
        });
    }

    public function down(): void
    {
        Schema::table('reports', function (Blueprint $table) {
            $table->dropColumn(['gemini_input_tokens', 'gemini_output_tokens']);
        });
    }
};
