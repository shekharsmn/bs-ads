<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('google_subject')->nullable()->unique();
            $table->string('role', 24)->default('viewer')->index();
            $table->string('avatar_url')->nullable();
            $table->timestamp('last_login_at')->nullable();
        });

        Schema::create('clients', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('timezone')->default('Asia/Kolkata');
            $table->string('currency', 8)->default('INR');
            $table->string('objective', 24)->default('lead_gen');
            $table->json('goals')->nullable();
            $table->string('chat_visibility', 24)->default('internal');
            $table->text('google_chat_webhook')->nullable();
            $table->boolean('active')->default(true)->index();
            $table->timestamps();
        });

        Schema::create('client_user', function (Blueprint $table) {
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->primary(['client_id', 'user_id']);
        });

        Schema::create('platform_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->string('platform', 16)->index();
            $table->string('name');
            $table->string('scope', 24)->default('client');
            $table->string('manager_external_id')->nullable();
            $table->text('credentials');
            $table->string('status', 24)->default('pending')->index();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamp('last_healthy_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
        });

        Schema::create('ad_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('platform_connection_id')->constrained()->cascadeOnDelete();
            $table->string('platform', 16)->index();
            $table->string('external_id');
            $table->string('name');
            $table->string('currency', 8);
            $table->string('timezone', 64);
            $table->string('status', 24)->default('active')->index();
            $table->decimal('monthly_budget', 18, 4)->nullable();
            $table->timestamp('last_collected_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
            $table->unique(['platform_connection_id', 'external_id']);
            $table->index(['client_id', 'platform', 'status']);
        });

        Schema::create('campaigns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ad_account_id')->constrained()->cascadeOnDelete();
            $table->string('external_id');
            $table->string('name');
            $table->string('status', 32)->nullable();
            $table->string('type', 64)->nullable();
            $table->decimal('daily_budget', 18, 4)->nullable();
            $table->decimal('lifetime_budget', 18, 4)->nullable();
            $table->timestamps();
            $table->unique(['ad_account_id', 'external_id']);
            $table->index(['ad_account_id', 'status']);
        });

        Schema::create('conversion_mappings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->string('platform', 16);
            $table->string('external_action');
            $table->string('canonical_metric', 32);
            $table->decimal('weight', 10, 4)->default(1);
            $table->unsignedInteger('version')->default(1);
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->unique(['client_id', 'platform', 'external_action', 'version'], 'conversion_mapping_unique');
        });

        Schema::create('metric_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ad_account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('campaign_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('scope', 16);
            $table->string('scope_key');
            $table->date('metric_date');
            $table->string('currency', 8);
            $table->decimal('spend', 20, 6)->default(0);
            $table->unsignedBigInteger('impressions')->default(0);
            $table->unsignedBigInteger('reach')->default(0);
            $table->unsignedBigInteger('clicks')->default(0);
            $table->decimal('conversions', 20, 6)->default(0);
            $table->decimal('leads', 20, 6)->default(0);
            $table->decimal('purchases', 20, 6)->default(0);
            $table->decimal('conversion_value', 20, 6)->default(0);
            $table->unsignedInteger('mapping_version')->default(1);
            $table->timestamp('collected_at');
            $table->timestamps();
            $table->unique(['ad_account_id', 'scope_key', 'metric_date']);
            $table->index(['metric_date', 'scope']);
        });

        Schema::create('alert_templates', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('name');
            $table->string('description')->nullable();
            $table->string('metric', 40);
            $table->string('operator', 40);
            $table->decimal('threshold', 20, 6)->nullable();
            $table->string('severity', 16)->default('warning');
            $table->json('config')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('alert_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('alert_template_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('client_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('ad_account_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('campaign_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('metric', 40);
            $table->string('operator', 40);
            $table->decimal('threshold', 20, 6)->nullable();
            $table->string('severity', 16)->default('warning');
            $table->unsignedSmallInteger('window_days')->default(1);
            $table->decimal('minimum_spend', 20, 6)->nullable();
            $table->unsignedBigInteger('minimum_impressions')->nullable();
            $table->json('config')->nullable();
            $table->boolean('enabled')->default(true)->index();
            $table->timestamps();
            $table->index(['client_id', 'ad_account_id', 'campaign_id'], 'alert_rule_scope');
        });

        Schema::create('alert_rule_states', function (Blueprint $table) {
            $table->id();
            $table->foreignId('alert_rule_id')->constrained()->cascadeOnDelete();
            $table->string('scope_key');
            $table->unsignedSmallInteger('failure_count')->default(0);
            $table->unsignedSmallInteger('healthy_count')->default(0);
            $table->boolean('currently_breaching')->default(false);
            $table->timestamp('last_evaluated_at')->nullable();
            $table->json('last_result')->nullable();
            $table->timestamps();
            $table->unique(['alert_rule_id', 'scope_key']);
        });

        Schema::create('alert_incidents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('alert_rule_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('ad_account_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('campaign_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->string('incident_key')->unique();
            $table->string('status', 24)->default('open')->index();
            $table->string('severity', 16)->default('warning')->index();
            $table->string('title');
            $table->text('summary')->nullable();
            $table->decimal('observed_value', 20, 6)->nullable();
            $table->decimal('baseline_value', 20, 6)->nullable();
            $table->decimal('threshold_value', 20, 6)->nullable();
            $table->timestamp('triggered_at');
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamp('snoozed_until')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('last_notified_at')->nullable();
            $table->timestamps();
            $table->index(['client_id', 'status', 'severity']);
        });

        Schema::create('alert_events', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('alert_incident_id')->constrained('alert_incidents')->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type', 32);
            $table->text('note')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('report_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->string('frequency', 16);
            $table->unsignedTinyInteger('weekday')->nullable();
            $table->unsignedTinyInteger('day_of_month')->nullable();
            $table->time('local_time')->default('09:00');
            $table->boolean('enabled')->default(true);
            $table->timestamps();
            $table->unique(['client_id', 'frequency']);
        });

        Schema::create('report_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('report_schedule_id')->nullable()->constrained()->nullOnDelete();
            $table->string('frequency', 16);
            $table->date('period_start');
            $table->date('period_end');
            $table->string('status', 24)->default('pending');
            $table->json('snapshot')->nullable();
            $table->string('public_token', 80)->nullable()->unique();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('generated_at')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();
        });

        Schema::table('reports', function (Blueprint $table) {
            $table->foreignId('client_id')->nullable()->after('user_id')->constrained()->nullOnDelete();
            $table->foreignId('ad_account_id')->nullable()->after('client_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('reports', function (Blueprint $table) {
            $table->dropConstrainedForeignId('ad_account_id');
            $table->dropConstrainedForeignId('client_id');
        });
        Schema::dropIfExists('report_runs');
        Schema::dropIfExists('report_schedules');
        Schema::dropIfExists('alert_events');
        Schema::dropIfExists('alert_incidents');
        Schema::dropIfExists('alert_rule_states');
        Schema::dropIfExists('alert_rules');
        Schema::dropIfExists('alert_templates');
        Schema::dropIfExists('metric_snapshots');
        Schema::dropIfExists('conversion_mappings');
        Schema::dropIfExists('campaigns');
        Schema::dropIfExists('ad_accounts');
        Schema::dropIfExists('platform_connections');
        Schema::dropIfExists('client_user');
        Schema::dropIfExists('clients');
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['google_subject', 'role', 'avatar_url', 'last_login_at']);
        });
    }
};
