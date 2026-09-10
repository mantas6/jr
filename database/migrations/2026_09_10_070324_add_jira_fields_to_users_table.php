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
        Schema::table('users', function (Blueprint $table) {
            $table->string('jira_site_url')->nullable();
            $table->string('jira_email')->nullable();
            $table->text('jira_api_token')->nullable();
            $table->string('jira_account_id')->nullable();
            $table->string('jira_project_key')->nullable();
            $table->timestamp('jira_connected_at')->nullable();
            $table->timestamp('jira_last_synced_at')->nullable();
            $table->text('jira_last_sync_error')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'jira_site_url',
                'jira_email',
                'jira_api_token',
                'jira_account_id',
                'jira_project_key',
                'jira_connected_at',
                'jira_last_synced_at',
                'jira_last_sync_error',
            ]);
        });
    }
};
