<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reports', function (Blueprint $table) {
            $table->timestamp('cancel_requested_at')->nullable()->after('completed_at');
            $table->timestamp('cancelled_at')->nullable()->after('cancel_requested_at');
        });

        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE reports MODIFY COLUMN status ENUM('pending','running','complete','failed','cancelled') NOT NULL DEFAULT 'pending'");
        }
    }

    public function down(): void
    {
        Schema::table('reports', function (Blueprint $table) {
            $table->dropColumn(['cancel_requested_at', 'cancelled_at']);
        });

        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE reports MODIFY COLUMN status ENUM('pending','running','complete','failed') NOT NULL DEFAULT 'pending'");
        }
    }
};
