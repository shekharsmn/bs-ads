<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workflow_activities', function (Blueprint $table) {
            $table->id();
            $table->string('type', 40)->index();
            $table->string('entity_type', 80);
            $table->string('entity_id', 80);
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('company_name', 160)->nullable();
            $table->string('title');
            $table->string('status', 32)->index();
            $table->string('stage', 64)->nullable();
            $table->unsignedInteger('progress_completed')->nullable();
            $table->unsignedInteger('progress_total')->nullable();
            $table->unsignedInteger('progress_failed')->nullable();
            $table->text('summary')->nullable();
            $table->text('error_message')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->unique(['entity_type', 'entity_id']);
            $table->index(['client_id', 'status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_activities');
    }
};
