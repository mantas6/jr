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
            $table->string('pr_state')->nullable()->after('original_estimate_seconds');
            $table->unsignedInteger('pr_count')->nullable()->after('pr_state');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('jira_issues', function (Blueprint $table) {
            $table->dropColumn(['pr_state', 'pr_count']);
        });
    }
};
