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
            $table->timestamp('assigned_to_me_at')->nullable()->after('last_viewed_at');
            $table->timestamp('last_mentioned_at')->nullable()->after('mentions_scanned_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('jira_issues', function (Blueprint $table) {
            $table->dropColumn(['assigned_to_me_at', 'last_mentioned_at']);
        });
    }
};
