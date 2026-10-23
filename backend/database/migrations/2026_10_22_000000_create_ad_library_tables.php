<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ad_library_searches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('company_name', 160);
            $table->string('company_type', 24)->default('competitor');
            $table->text('website_url')->nullable();
            $table->string('name');
            $table->string('search_mode', 16);
            $table->string('query')->nullable();
            $table->string('advertiser_page_id', 64)->nullable();
            $table->string('region', 3)->default('ALL');
            $table->string('active_status', 16)->default('active');
            $table->string('ad_type', 40)->default('all');
            $table->boolean('exact_phrase')->default(false);
            $table->boolean('details')->default(false);
            $table->unsignedSmallInteger('max_results')->default(100);
            $table->timestamps();
            $table->softDeletes();
            $table->index(['company_type', 'created_at']);
        });

        Schema::create('ad_library_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ad_library_search_id')->constrained('ad_library_searches')->cascadeOnDelete();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('provider', 32)->default('scraper');
            $table->string('status', 24)->default('pending');
            $table->unsignedInteger('result_count')->default(0);
            $table->unsignedBigInteger('reported_total')->nullable();
            $table->string('stop_reason', 80)->nullable();
            $table->boolean('capped')->default(false);
            $table->text('error_message')->nullable();
            $table->json('provider_metadata')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'created_at']);
        });

        Schema::create('ad_library_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ad_library_run_id')->constrained('ad_library_runs')->cascadeOnDelete();
            $table->string('library_ad_id', 80);
            $table->unsignedInteger('rank')->nullable();
            $table->string('advertiser_name')->nullable();
            $table->string('advertiser_page_id', 80)->nullable();
            $table->text('advertiser_url')->nullable();
            $table->unsignedBigInteger('advertiser_likes')->nullable();
            $table->text('title')->nullable();
            $table->text('body')->nullable();
            $table->text('product_url')->nullable();
            $table->text('link_url')->nullable();
            $table->text('link_title')->nullable();
            $table->text('link_description')->nullable();
            $table->string('cta_text')->nullable();
            $table->string('cta_type', 64)->nullable();
            $table->boolean('is_active')->nullable();
            $table->date('first_shown_at')->nullable();
            $table->date('last_shown_at')->nullable();
            $table->string('display_format', 32)->nullable();
            $table->json('platforms')->nullable();
            $table->json('image_urls')->nullable();
            $table->json('video_urls')->nullable();
            $table->string('paid_for_by')->nullable();
            $table->string('spend')->nullable();
            $table->string('spend_currency', 16)->nullable();
            $table->string('impressions')->nullable();
            $table->string('reach')->nullable();
            $table->unsignedBigInteger('eu_reach')->nullable();
            $table->unsignedBigInteger('uk_reach')->nullable();
            $table->json('reach_breakdown')->nullable();
            $table->string('target_ages', 64)->nullable();
            $table->string('target_gender', 32)->nullable();
            $table->json('target_locations')->nullable();
            $table->string('payer')->nullable();
            $table->string('beneficiary')->nullable();
            $table->string('advertiser_ig_username')->nullable();
            $table->unsignedBigInteger('advertiser_ig_followers')->nullable();
            $table->string('advertiser_verification', 64)->nullable();
            $table->json('raw_payload');
            $table->timestamps();
            $table->unique(['ad_library_run_id', 'library_ad_id'], 'ad_library_run_ad_unique');
            $table->index(['advertiser_page_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ad_library_results');
        Schema::dropIfExists('ad_library_runs');
        Schema::dropIfExists('ad_library_searches');
    }
};
