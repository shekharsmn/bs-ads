<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('google_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('label')->default('My Google Ads Account');
            $table->string('customer_id', 32);
            $table->string('login_customer_id', 32)->nullable();
            $table->text('refresh_token');
            $table->string('account_name')->nullable();
            $table->string('currency', 8)->nullable();
            $table->string('time_zone', 64)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('google_accounts');
    }
};
