<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('run_performance_audits')->default(false)->after('role');
            $table->boolean('run_content_audits')->default(false)->after('run_performance_audits');
            $table->boolean('use_data_inspector')->default(false)->after('run_content_audits');
        });

        DB::table('users')->whereIn('role', ['admin', 'marketer'])->update([
            'run_performance_audits' => true,
            'run_content_audits' => true,
        ]);
        DB::table('users')->update(['use_data_inspector' => true]);

        Schema::create('user_invitations', function (Blueprint $table) {
            $table->id();
            $table->string('email')->unique();
            $table->string('name')->nullable();
            $table->string('role', 24)->default('viewer');
            $table->boolean('run_performance_audits')->default(false);
            $table->boolean('run_content_audits')->default(false);
            $table->boolean('use_data_inspector')->default(false);
            $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('invited_at');
            $table->timestamp('last_sent_at')->nullable();
            $table->timestamp('accepted_at')->nullable()->index();
            $table->timestamp('revoked_at')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('client_user_invitation', function (Blueprint $table) {
            $table->foreignId('user_invitation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->primary(['user_invitation_id', 'client_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_user_invitation');
        Schema::dropIfExists('user_invitations');
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['run_performance_audits', 'run_content_audits', 'use_data_inspector']);
        });
    }
};
