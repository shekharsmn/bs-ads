<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_integrations', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 40)->unique();
            $table->boolean('enabled')->default(false);
            $table->string('region', 64)->default('us-east-1');
            $table->unsignedSmallInteger('port')->default(587);
            $table->text('smtp_username')->nullable();
            $table->text('smtp_password')->nullable();
            $table->string('from_address');
            $table->string('from_name');
            $table->timestamp('last_tested_at')->nullable();
            $table->string('last_test_status', 20)->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_integrations');
    }
};
