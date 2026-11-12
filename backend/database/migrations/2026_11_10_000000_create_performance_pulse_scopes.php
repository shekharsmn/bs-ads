<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('performance_pulse_scopes')) {
            Schema::create('performance_pulse_scopes', function (Blueprint $table) {
                $table->id();
                $table->foreignId('performance_pulse_id');
                $table->foreignId('campaign_id')->nullable();
                $table->string('scope_key', 80)->unique();
                $table->timestamps();
                $table->index(['performance_pulse_id', 'campaign_id'], 'pulse_scope_pulse_campaign_idx');
                $table->foreign('performance_pulse_id', 'pulse_scope_pulse_fk')->references('id')->on('performance_pulses')->cascadeOnDelete();
                $table->foreign('campaign_id', 'pulse_scope_campaign_fk')->references('id')->on('campaigns')->cascadeOnDelete();
            });
        }

        DB::table('performance_pulses')->orderBy('id')->each(function ($pulse) {
            DB::table('performance_pulse_scopes')->insertOrIgnore([
                'performance_pulse_id' => $pulse->id,
                'campaign_id' => $pulse->campaign_id,
                'scope_key' => $pulse->campaign_id ? 'campaign:'.$pulse->campaign_id : 'account:'.$pulse->ad_account_id,
                'created_at' => $pulse->created_at ?? now(),
                'updated_at' => $pulse->updated_at ?? now(),
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('performance_pulse_scopes');
    }
};
