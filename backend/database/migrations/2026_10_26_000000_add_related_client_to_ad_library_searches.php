<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ad_library_searches', function (Blueprint $table) {
            $table->foreignId('related_client_id')->nullable()->after('created_by')->constrained('clients')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('ad_library_searches', function (Blueprint $table) {
            $table->dropConstrainedForeignId('related_client_id');
        });
    }
};
