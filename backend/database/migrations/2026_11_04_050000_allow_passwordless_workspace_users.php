<?php

use App\Support\SchemaRepair;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        SchemaRepair::columns('users', ['password']);
        $column = collect(Schema::getColumns('users'))->firstWhere('name', 'password');
        if (! $column['nullable']) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('password')->nullable()->change();
            });
        }
    }

    public function down(): void
    {
        // Existing Google-only accounts must remain valid; never invent passwords or delete users.
    }
};
