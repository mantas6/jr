<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('jira_issues', function (Blueprint $table) {
            $table->boolean('is_important')->default(false)->after('sprints');
            $table->timestamp('snoozed_until')->nullable()->after('is_important');
            $table->timestamp('dismissed_at')->nullable()->after('snoozed_until');
            $table->boolean('mentions_me')->default(false)->after('dismissed_at');
            $table->timestamp('mentions_scanned_at')->nullable()->after('mentions_me');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('jira_issues', function (Blueprint $table) {
            $table->dropColumn([
                'is_important',
                'snoozed_until',
                'dismissed_at',
                'mentions_me',
                'mentions_scanned_at',
            ]);
        });
    }
};
