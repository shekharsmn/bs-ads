<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('alert_incidents', function (Blueprint $table) {
            $table->decimal('comparison_value', 20, 6)->nullable()->after('threshold_value');
        });
    }

    public function down(): void
    {
        Schema::table('alert_incidents', function (Blueprint $table) {
            $table->dropColumn('comparison_value');
        });
    }
};
