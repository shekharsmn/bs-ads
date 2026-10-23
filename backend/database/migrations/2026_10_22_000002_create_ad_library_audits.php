<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ad_library_audits')) {
            Schema::create('ad_library_audits', function (Blueprint $table) {
                $table->id();
                $table->foreignId('ad_library_run_id')->nullable()->constrained('ad_library_runs')->nullOnDelete();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->string('company_name', 160);
                $table->string('company_type', 24)->default('competitor');
                $table->string('name', 160);
                $table->text('website_url')->nullable();
                $table->string('provider', 32);
                $table->string('model', 120);
                $table->string('model_key', 180);
                $table->string('media_depth', 24)->default('visual');
                $table->string('status', 40)->default('draft')->index();
                $table->string('failed_stage', 40)->nullable();
                $table->text('error_message')->nullable();
                $table->json('context_sources')->nullable();
                $table->json('website_context')->nullable();
                $table->json('insights')->nullable();
                $table->boolean('insights_stale')->default(false);
                $table->unsignedSmallInteger('prompt_version')->default(1);
                $table->unsignedSmallInteger('schema_version')->default(1);
                $table->unsignedSmallInteger('progress_total')->default(0);
                $table->unsignedSmallInteger('progress_completed')->default(0);
                $table->unsignedSmallInteger('progress_failed')->default(0);
                $table->unsignedBigInteger('input_tokens')->default(0);
                $table->unsignedBigInteger('output_tokens')->default(0);
                $table->decimal('estimated_cost', 14, 6)->default(0);
                $table->timestamp('personas_confirmed_at')->nullable();
                $table->timestamp('grid_confirmed_at')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->timestamps();
                $table->index(['company_type', 'created_at']);
            });
        }

        if (! Schema::hasTable('ad_library_audit_personas')) {
            Schema::create('ad_library_audit_personas', function (Blueprint $table) {
                $table->id();
                $table->foreignId('ad_library_audit_id')->constrained('ad_library_audits')->cascadeOnDelete();
                $table->string('name', 160);
                $table->text('description');
                $table->string('demographics')->nullable();
                $table->json('problems')->nullable();
                $table->json('desired_outcomes')->nullable();
                $table->json('motivations')->nullable();
                $table->json('purchase_triggers')->nullable();
                $table->json('objections')->nullable();
                $table->json('products')->nullable();
                $table->json('evidence')->nullable();
                $table->decimal('confidence', 4, 3)->nullable();
                $table->unsignedSmallInteger('position')->default(0);
                $table->boolean('confirmed')->default(false);
                $table->boolean('manually_edited')->default(false);
                $table->foreignId('edited_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('edited_at')->nullable();
                $table->timestamps();
                $table->index(['ad_library_audit_id', 'position']);
            });
        }

        if (! Schema::hasTable('ad_library_audit_creatives')) {
            Schema::create('ad_library_audit_creatives', function (Blueprint $table) {
                $table->id();
                $table->foreignId('ad_library_audit_id')->constrained('ad_library_audits')->cascadeOnDelete();
                $table->foreignId('ad_library_result_id')->nullable()->constrained('ad_library_results')->nullOnDelete();
                $table->string('creative_key');
                $table->json('library_ad_ids');
                $table->json('source_snapshot');
                $table->unsignedSmallInteger('position')->default(0);
                $table->string('processing_status', 24)->default('pending')->index();
                $table->string('review_status', 24)->default('pending')->index();
                $table->text('angle')->nullable();
                $table->string('product')->nullable();
                $table->text('what_it_solves')->nullable();
                $table->string('format')->nullable();
                $table->string('type')->nullable();
                $table->text('remarks')->nullable();
                $table->json('evidence')->nullable();
                $table->decimal('confidence', 4, 3)->nullable();
                $table->json('raw_ai_response')->nullable();
                $table->text('error_message')->nullable();
                $table->boolean('manually_edited')->default(false);
                $table->foreignId('edited_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('edited_at')->nullable();
                $table->timestamp('reviewed_at')->nullable();
                $table->timestamps();
                $table->unique(['ad_library_audit_id', 'creative_key'], 'audit_creative_unique');
            });
        }

        // MySQL does not roll back DDL. A failed first run can leave the
        // parent tables behind before Laravel records this migration.
        Schema::dropIfExists('ad_library_audit_ai_requests');
        Schema::dropIfExists('ad_library_audit_creative_persona');

        Schema::create('ad_library_audit_creative_persona', function (Blueprint $table) {
            $table->foreignId('ad_library_audit_creative_id')->constrained(
                'ad_library_audit_creatives',
                indexName: 'audit_creative_persona_creative_fk',
            )->cascadeOnDelete();
            $table->foreignId('ad_library_audit_persona_id')->constrained(
                'ad_library_audit_personas',
                indexName: 'audit_creative_persona_persona_fk',
            )->cascadeOnDelete();
            $table->string('role', 16)->default('secondary');
            $table->timestamps();
            $table->primary(['ad_library_audit_creative_id', 'ad_library_audit_persona_id'], 'audit_creative_persona_primary');
        });

        Schema::create('ad_library_audit_ai_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ad_library_audit_id')->constrained('ad_library_audits')->cascadeOnDelete();
            $table->foreignId('ad_library_audit_creative_id')->nullable()->constrained(
                'ad_library_audit_creatives',
                indexName: 'audit_ai_request_creative_fk',
            )->cascadeOnDelete();
            $table->string('stage', 40);
            $table->string('provider', 32);
            $table->string('model', 120);
            $table->unsignedSmallInteger('prompt_version')->default(1);
            $table->unsignedSmallInteger('schema_version')->default(1);
            $table->string('status', 24);
            $table->unsignedBigInteger('input_tokens')->default(0);
            $table->unsignedBigInteger('output_tokens')->default(0);
            $table->decimal('estimated_cost', 14, 6)->default(0);
            $table->decimal('input_price', 12, 6)->default(0);
            $table->decimal('output_price', 12, 6)->default(0);
            $table->unsignedInteger('latency_ms')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();
            $table->index(['ad_library_audit_id', 'stage']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ad_library_audit_ai_requests');
        Schema::dropIfExists('ad_library_audit_creative_persona');
        Schema::dropIfExists('ad_library_audit_creatives');
        Schema::dropIfExists('ad_library_audit_personas');
        Schema::dropIfExists('ad_library_audits');
    }
};
