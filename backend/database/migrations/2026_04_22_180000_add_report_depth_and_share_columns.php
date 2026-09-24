<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('report_checks', function (Blueprint $table) {
            $table->json('evidence')->nullable()->after('recommendation');
            $table->json('metrics')->nullable()->after('evidence');
            $table->unsignedTinyInteger('impact_score')->nullable()->after('metrics');
        });

        Schema::table('reports', function (Blueprint $table) {
            $table->string('public_token', 64)->nullable()->unique()->after('google_snapshot');
            $table->json('meta_snapshot')->nullable()->after('public_token');
        });
    }

    public function down(): void
    {
        Schema::table('report_checks', function (Blueprint $table) {
            $table->dropColumn(['evidence', 'metrics', 'impact_score']);
        });

        Schema::table('reports', function (Blueprint $table) {
            $table->dropColumn(['public_token', 'meta_snapshot']);
        });
    }
};
