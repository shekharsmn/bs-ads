<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workflow_activities', function (Blueprint $table) {
            $table->unsignedInteger('attempt_count')->default(0)->after('stage');
            $table->timestamp('last_heartbeat_at')->nullable()->after('finished_at');
        });

        Schema::create('workflow_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workflow_activity_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('attempt_number');
            $table->string('idempotency_key', 191)->unique();
            $table->string('job_uuid', 64)->nullable()->index();
            $table->string('status', 32)->index();
            $table->string('stage', 64)->nullable();
            $table->text('error_class')->nullable();
            $table->text('error_message')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('heartbeat_at')->nullable()->index();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->unique(['workflow_activity_id', 'attempt_number'], 'workflow_attempt_activity_number_unique');
        });

        Schema::table('metric_snapshots', function (Blueprint $table) {
            $table->index(['ad_account_id', 'scope', 'metric_date', 'currency'], 'metric_account_scope_date_currency');
            $table->index(['campaign_id', 'metric_date', 'currency'], 'metric_campaign_date_currency');
        });
    }

    public function down(): void
    {
        Schema::table('metric_snapshots', function (Blueprint $table) {
            $table->dropIndex('metric_account_scope_date_currency');
            $table->dropIndex('metric_campaign_date_currency');
        });
        Schema::dropIfExists('workflow_attempts');
        Schema::table('workflow_activities', function (Blueprint $table) {
            $table->dropColumn(['attempt_count', 'last_heartbeat_at']);
        });
    }
};
