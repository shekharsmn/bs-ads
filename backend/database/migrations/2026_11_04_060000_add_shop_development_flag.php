<?php

use App\Support\SchemaRepair;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        SchemaRepair::columns('shop_stores', ['id']);
        if (! Schema::hasColumn('shop_stores', 'is_development')) {
            Schema::table('shop_stores', fn (Blueprint $table) => $table->boolean('is_development')->default(false));
        }
    }

    public function down(): void
    {
        Schema::table('shop_stores', fn (Blueprint $table) => $table->dropColumn('is_development'));
    }
};
