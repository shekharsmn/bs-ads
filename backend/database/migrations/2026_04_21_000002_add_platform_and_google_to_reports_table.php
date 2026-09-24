<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reports', function (Blueprint $table) {
            $table->string('platform', 16)->default('meta')->after('user_id');
            $table->foreignId('google_account_id')->nullable()->after('meta_account_id')->constrained('google_accounts')->nullOnDelete();
        });

        Schema::table('reports', function (Blueprint $table) {
            $table->unsignedBigInteger('meta_account_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('reports', function (Blueprint $table) {
            $table->dropForeign(['google_account_id']);
            $table->dropColumn(['platform', 'google_account_id']);
        });
    }
};
