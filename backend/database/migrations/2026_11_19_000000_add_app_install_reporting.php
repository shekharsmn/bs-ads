<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ad_accounts', function (Blueprint $table) {
            $table->string('reporting_objective', 24)->nullable()->after('status')->index();
        });

        Schema::table('metric_snapshots', function (Blueprint $table) {
            $table->decimal('installs', 20, 6)->default(0)->after('purchases');
        });
    }

    public function down(): void
    {
        Schema::table('metric_snapshots', function (Blueprint $table) {
            $table->dropColumn('installs');
        });

        Schema::table('ad_accounts', function (Blueprint $table) {
            $table->dropIndex(['reporting_objective']);
            $table->dropColumn('reporting_objective');
        });
    }
};
