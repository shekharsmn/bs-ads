<?php

use App\Support\SchemaRepair;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Legacy MariaDB TIMESTAMP defaults can reject or automatically alter deadlines.
        // Repair databases where the original connection/sync migrations already ran.
        foreach (['shop_links' => 'expires_at', 'shop_sync_runs' => 'until_at'] as $table => $column) {
            SchemaRepair::columns($table, [$column]);
            if (Schema::getColumnType($table, $column) === 'timestamp') {
                Schema::table($table, function (Blueprint $blueprint) use ($column) {
                    $blueprint->dateTime($column)->change();
                });
            }
        }
    }

    public function down(): void
    {
        // Keep the compatible type; reverting it reintroduces implicit timestamp behavior.
    }
};
