<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ad_library_audits', function (Blueprint $table) {
            $table->unsignedSmallInteger('workflow_version')->default(1)->after('schema_version');
            $table->foreignId('related_client_id')->nullable()->after('created_by')->constrained('clients')->nullOnDelete();
            $table->json('client_context')->nullable()->after('website_context');
        });

        Schema::table('ad_library_audit_creatives', function (Blueprint $table) {
            $table->json('persona_candidates')->nullable()->after('evidence');
            $table->longText('video_script')->nullable()->after('persona_candidates');
            $table->string('video_script_status', 24)->nullable()->after('video_script');
        });

        Schema::table('ad_library_audit_creative_persona', function (Blueprint $table) {
            $table->text('rationale')->nullable()->after('role');
            $table->decimal('confidence', 4, 3)->nullable()->after('rationale');
        });
    }

    public function down(): void
    {
        Schema::table('ad_library_audit_creative_persona', function (Blueprint $table) {
            $table->dropColumn(['rationale', 'confidence']);
        });
        Schema::table('ad_library_audit_creatives', function (Blueprint $table) {
            $table->dropColumn(['persona_candidates', 'video_script', 'video_script_status']);
        });
        Schema::table('ad_library_audits', function (Blueprint $table) {
            $table->dropConstrainedForeignId('related_client_id');
            $table->dropColumn(['workflow_version', 'client_context']);
        });
    }
};
