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
        Schema::create('jira_issues', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('jira_id');
            $table->string('jira_key');
            $table->string('summary');
            $table->string('status');
            $table->string('status_id');
            $table->string('status_category');
            $table->string('issue_type');
            $table->string('priority')->nullable();
            $table->string('assignee_account_id')->nullable();
            $table->string('assignee_name')->nullable();
            $table->string('reporter_name')->nullable();
            $table->string('jira_url');
            $table->timestamp('jira_created_at');
            $table->timestamp('jira_updated_at');
            $table->json('raw');
            $table->timestamp('last_synced_at');
            $table->timestamps();

            $table->unique(['user_id', 'jira_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('jira_issues');
    }
};
