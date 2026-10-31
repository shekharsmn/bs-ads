<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('performance_pulses')) {
            Schema::create('performance_pulses', function (Blueprint $table) {
                $table->id();
                $table->foreignId('client_id')->constrained()->cascadeOnDelete();
                $table->foreignId('ad_account_id')->constrained()->cascadeOnDelete();
                $table->foreignId('campaign_id')->nullable()->constrained()->cascadeOnDelete();
                $table->string('scope_key', 80);
                $table->json('periods');
                $table->time('local_time')->default('09:00');
                $table->boolean('include_campaign_breakdown')->default(false);
                $table->boolean('enabled')->default(false);
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->unique(['client_id', 'scope_key']);
                $table->index(['enabled', 'local_time']);
            });
        }

        if (! Schema::hasTable('performance_pulse_destination')) {
            Schema::create('performance_pulse_destination', function (Blueprint $table) {
                $table->foreignId('performance_pulse_id')->constrained()->cascadeOnDelete();
                $table->foreignId('notification_destination_id')->constrained()->cascadeOnDelete();
                $table->primary(['performance_pulse_id', 'notification_destination_id'], 'pulse_destination_primary');
            });
        }

        if (! Schema::hasTable('performance_pulse_runs')) {
            Schema::create('performance_pulse_runs', function (Blueprint $table) {
                $table->id();
                $table->foreignId('performance_pulse_id')->constrained()->cascadeOnDelete();
                $table->foreignId('triggered_by')->nullable()->constrained('users')->nullOnDelete();
                $table->string('trigger', 16);
                $table->date('reporting_date');
                $table->string('schedule_key')->nullable()->unique();
                $table->uuid('idempotency_key')->unique();
                $table->string('status', 24)->default('pending')->index();
                $table->json('rendered_payload')->nullable();
                $table->timestamp('freshness_at')->nullable();
                $table->text('warning')->nullable();
                $table->text('error_message')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->timestamps();
                $table->index(['performance_pulse_id', 'created_at']);
            });
        }

        if (! Schema::hasColumn('notification_deliveries', 'performance_pulse_run_id')) {
            Schema::table('notification_deliveries', function (Blueprint $table) {
                $table->foreignId('performance_pulse_run_id')->nullable()->after('notification_destination_id')->constrained()->nullOnDelete();
            });
        }

        if (! Schema::hasColumn('notification_deliveries', 'part_number')) {
            Schema::table('notification_deliveries', function (Blueprint $table) {
                $table->unsignedSmallInteger('part_number')->nullable()->after('event_type');
            });
        }

        if (! Schema::hasColumn('notification_deliveries', 'part_count')) {
            Schema::table('notification_deliveries', function (Blueprint $table) {
                $table->unsignedSmallInteger('part_count')->nullable()->after('part_number');
            });
        }
    }

    public function down(): void
    {
        Schema::table('notification_deliveries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('performance_pulse_run_id');
            $table->dropColumn(['part_number', 'part_count']);
        });
        Schema::dropIfExists('performance_pulse_runs');
        Schema::dropIfExists('performance_pulse_destination');
        Schema::dropIfExists('performance_pulses');
    }
};
