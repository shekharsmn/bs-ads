<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('report_checks', function (Blueprint $table) {
            $table->string('status', 12)->default('PASS')->change();
        });
    }

    public function down(): void
    {
        Schema::table('report_checks', function (Blueprint $table) {
            $table->enum('status', ['PASS', 'WARN', 'FAIL'])->default('PASS')->change();
        });
    }
};
