<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class WorkspacePasswordMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_legacy_required_password_is_repaired_without_changing_existing_hashes(): void
    {
        $existing = User::factory()->create();
        $hash = DB::table('users')->where('id', $existing->id)->value('password');
        Schema::table('users', fn (Blueprint $table) => $table->string('password')->nullable(false)->change());

        $migration = require database_path('migrations/2026_11_04_050000_allow_passwordless_workspace_users.php');
        $migration->up();
        $migration->up();

        $google = User::create(['name' => 'Workspace user', 'email' => 'workspace@example.test', 'google_subject' => 'synthetic-subject', 'role' => 'viewer']);
        $this->assertNull($google->fresh()->password);
        $this->assertSame($hash, DB::table('users')->where('id', $existing->id)->value('password'));
    }
}
