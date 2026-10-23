<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ad_library_results', function (Blueprint $table) {
            $table->text('title')->nullable()->change();
            $table->text('link_title')->nullable()->change();
            $table->text('link_description')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('ad_library_results', function (Blueprint $table) {
            $table->string('title')->nullable()->change();
            $table->string('link_title')->nullable()->change();
            $table->string('link_description')->nullable()->change();
        });
    }
};
