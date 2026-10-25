<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name', 120);
            $table->json('sections');
            $table->string('brand_name')->nullable();
            $table->string('brand_color', 7)->nullable();
            $table->string('logo_url', 2000)->nullable();
            $table->boolean('is_default')->default(false);
            $table->timestamps();
        });
        Schema::table('report_schedules', function (Blueprint $table) {
            $table->foreignId('report_template_id')->nullable()->after('client_id')->constrained()->nullOnDelete();
        });
        Schema::table('report_runs', function (Blueprint $table) {
            $table->foreignId('report_template_id')->nullable()->after('client_id')->constrained()->nullOnDelete();
            $table->json('template_snapshot')->nullable()->after('snapshot');
            $table->string('delivery_status', 24)->default('pending')->after('status');
            $table->text('delivery_error')->nullable()->after('error_message');
            $table->timestamp('delivered_at')->nullable()->after('generated_at');
        });
    }

    public function down(): void
    {
        Schema::table('report_runs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('report_template_id');
            $table->dropColumn(['template_snapshot', 'delivery_status', 'delivery_error', 'delivered_at']);
        });
        Schema::table('report_schedules', fn (Blueprint $table) => $table->dropConstrainedForeignId('report_template_id'));
        Schema::dropIfExists('report_templates');
    }
};
