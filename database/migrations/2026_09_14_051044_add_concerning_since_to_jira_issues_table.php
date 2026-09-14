<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('jira_issues', function (Blueprint $table) {
            $table->timestamp('concerning_since')->nullable()->after('is_important');
        });

        // Backfill: any currently starred task is pinned to the concerning list
        // so unstarring it later keeps it there until it is dismissed.
        DB::table('jira_issues')
            ->where('is_important', true)
            ->update(['concerning_since' => now()]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('jira_issues', function (Blueprint $table) {
            $table->dropColumn('concerning_since');
        });
    }
};
