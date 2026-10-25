<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'disabled_at')) {
            Schema::table('users', function (Blueprint $table) {
                $table->timestamp('disabled_at')->nullable()->after('last_login_at')->index();
            });
        }

        if (! Schema::hasColumn('users', 'disabled_by')) {
            Schema::table('users', function (Blueprint $table) {
                $table->foreignId('disabled_by')->nullable()->after('disabled_at')->constrained('users')->nullOnDelete();
            });
        }

        if (! Schema::hasTable('audit_events')) {
            Schema::create('audit_events', function (Blueprint $table) {
                $table->id();
                $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
                $table->string('action', 100)->index();
                $table->string('entity_type', 120)->nullable();
                $table->string('entity_id', 80)->nullable();
                $table->string('source', 24)->default('http');
                $table->uuid('correlation_id')->index();
                $table->json('before')->nullable();
                $table->json('after')->nullable();
                $table->string('ip_address', 45)->nullable();
                $table->string('user_agent', 500)->nullable();
                $table->timestamp('created_at')->useCurrent();
                $table->index(['client_id', 'created_at']);
                $table->index(['entity_type', 'entity_id']);
            });
        }

        if (! Schema::hasTable('ai_budget_policies')) {
            Schema::create('ai_budget_policies', function (Blueprint $table) {
                $table->id();
                $table->foreignId('client_id')->nullable()->constrained()->cascadeOnDelete();
                $table->decimal('monthly_budget', 14, 4);
                $table->unsignedTinyInteger('warning_percent')->default(70);
                $table->unsignedTinyInteger('critical_percent')->default(90);
                $table->boolean('enabled')->default(true);
                $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->unique('client_id');
            });
        }

        Schema::create('data_quality_issues', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ad_account_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('issue_key')->unique();
            $table->string('type', 50)->index();
            $table->string('severity', 16)->default('warning')->index();
            $table->string('status', 16)->default('open')->index();
            $table->string('title');
            $table->text('summary')->nullable();
            $table->json('evidence')->nullable();
            $table->text('remediation')->nullable();
            $table->dateTime('first_detected_at');
            $table->dateTime('last_detected_at');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
            $table->index(['client_id', 'status', 'severity']);
        });

        Schema::create('notification_destinations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('channel', 24)->index();
            $table->text('configuration');
            $table->boolean('enabled')->default(true);
            $table->timestamp('last_tested_at')->nullable();
            $table->string('last_test_status', 24)->nullable();
            $table->text('last_error')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['client_id', 'enabled']);
        });

        Schema::create('notification_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('notification_destination_id')->constrained()->cascadeOnDelete();
            $table->string('event_type', 50);
            $table->timestamps();
            $table->unique(['notification_destination_id', 'event_type'], 'notification_subscription_unique');
        });

        Schema::create('notification_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('notification_destination_id')->constrained()->cascadeOnDelete();
            $table->string('event_type', 50)->index();
            $table->string('idempotency_key')->unique();
            $table->string('status', 24)->default('pending')->index();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->json('payload');
            $table->text('error_message')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();
        });

        foreach (['reports', 'report_runs', 'ad_library_audits'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->string('methodology_key', 80)->nullable();
                $table->string('methodology_version', 24)->nullable();
                $table->json('methodology_snapshot')->nullable();
            });
        }

        Schema::table('gemini_request_logs', function (Blueprint $table) {
            $table->unsignedSmallInteger('prompt_version')->default(1);
            $table->unsignedSmallInteger('schema_version')->default(1);
        });

        DB::table('reports')->whereNull('methodology_version')->update(['methodology_key' => 'account-audit', 'methodology_version' => 'legacy-1.0']);
        DB::table('report_runs')->whereNull('methodology_version')->update(['methodology_key' => 'performance-report', 'methodology_version' => 'legacy-1.0']);
        DB::table('ad_library_audits')->whereNull('methodology_version')->update(['methodology_key' => 'content-audit', 'methodology_version' => 'legacy-1.0']);
    }

    public function down(): void
    {
        Schema::table('gemini_request_logs', fn (Blueprint $table) => $table->dropColumn(['prompt_version', 'schema_version']));
        foreach (['reports', 'report_runs', 'ad_library_audits'] as $tableName) {
            Schema::table($tableName, fn (Blueprint $table) => $table->dropColumn(['methodology_key', 'methodology_version', 'methodology_snapshot']));
        }
        Schema::dropIfExists('notification_deliveries');
        Schema::dropIfExists('notification_subscriptions');
        Schema::dropIfExists('notification_destinations');
        Schema::dropIfExists('data_quality_issues');
        Schema::dropIfExists('ai_budget_policies');
        Schema::dropIfExists('audit_events');
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('disabled_by');
            $table->dropColumn('disabled_at');
        });
    }
};
