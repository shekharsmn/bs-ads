<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gemini_request_logs', function (Blueprint $table) {
            $table->string('provider', 32)->nullable()->after('call_type');
            $table->string('model', 160)->nullable()->after('provider');
            $table->decimal('input_price', 12, 6)->nullable()->after('output_tokens');
            $table->decimal('output_price', 12, 6)->nullable()->after('input_price');
            $table->decimal('estimated_cost', 14, 6)->nullable()->after('output_price');
            $table->string('status', 24)->nullable()->after('estimated_cost');
            $table->unsignedInteger('latency_ms')->nullable()->after('status');
            $table->text('error_message')->nullable()->after('latency_ms');
        });
    }

    public function down(): void
    {
        Schema::table('gemini_request_logs', function (Blueprint $table) {
            $table->dropColumn([
                'provider', 'model', 'input_price', 'output_price', 'estimated_cost',
                'status', 'latency_ms', 'error_message',
            ]);
        });
    }
};
