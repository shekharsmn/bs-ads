<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ad_library_tags', function (Blueprint $table) {
            $table->id();
            $table->string('name', 60);
            $table->string('slug', 80)->unique();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
        Schema::create('ad_library_search_tag', function (Blueprint $table) {
            $table->foreignId('ad_library_search_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ad_library_tag_id')->constrained()->cascadeOnDelete();
            $table->primary(['ad_library_search_id', 'ad_library_tag_id']);
        });
        Schema::create('ad_library_favorites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ad_library_result_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['user_id', 'ad_library_result_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ad_library_favorites');
        Schema::dropIfExists('ad_library_search_tag');
        Schema::dropIfExists('ad_library_tags');
    }
};
