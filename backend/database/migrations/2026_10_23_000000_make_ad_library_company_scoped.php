<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ad_library_searches') && ! Schema::hasColumn('ad_library_searches', 'company_name')) {
            Schema::table('ad_library_searches', function (Blueprint $table) {
                $table->string('company_name', 160)->nullable()->after('created_by');
                $table->string('company_type', 24)->default('other')->after('company_name');
                $table->text('website_url')->nullable()->after('company_type');
                $table->index(['company_type', 'created_at']);
            });

            DB::table('ad_library_searches')->orderBy('id')->eachById(function ($search) {
                DB::table('ad_library_searches')->where('id', $search->id)->update([
                    'company_name' => $search->name ?: 'Imported company',
                    'company_type' => 'other',
                ]);
            });
        }

        if (Schema::hasTable('ad_library_audits') && ! Schema::hasColumn('ad_library_audits', 'company_name')) {
            Schema::table('ad_library_audits', function (Blueprint $table) {
                $table->string('company_name', 160)->nullable()->after('created_by');
                $table->string('company_type', 24)->default('other')->after('company_name');
                $table->index(['company_type', 'created_at']);
            });

            DB::table('ad_library_audits')->orderBy('id')->eachById(function ($audit) {
                $search = $audit->ad_library_run_id
                    ? DB::table('ad_library_runs')
                        ->join('ad_library_searches', 'ad_library_searches.id', '=', 'ad_library_runs.ad_library_search_id')
                        ->where('ad_library_runs.id', $audit->ad_library_run_id)
                        ->first(['ad_library_searches.company_name', 'ad_library_searches.company_type'])
                    : null;

                DB::table('ad_library_audits')->where('id', $audit->id)->update([
                    'company_name' => $search?->company_name ?: $audit->name ?: 'Imported company',
                    'company_type' => $search?->company_type ?: 'other',
                ]);
            });
        }

        // Older local installs already have these foreign keys. Keep the nullable
        // legacy columns so the migration is safe, but new code no longer reads or
        // writes them. Fresh installs do not create the columns at all.
        foreach (['ad_library_searches', 'ad_library_runs', 'ad_library_audits'] as $tableName) {
            if (Schema::hasTable($tableName) && Schema::hasColumn($tableName, 'client_id')) {
                Schema::table($tableName, function (Blueprint $table) {
                    $table->unsignedBigInteger('client_id')->nullable()->change();
                });
            }
        }
    }

    public function down(): void
    {
        // This intentionally does not restore required client ownership: company
        // research created after this migration may have no corresponding client.
    }
};
