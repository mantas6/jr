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
        Schema::create('jira_issue_jira_release', function (Blueprint $table) {
            $table->foreignId('jira_issue_id')->constrained()->cascadeOnDelete();
            $table->foreignId('jira_release_id')->constrained()->cascadeOnDelete();

            $table->primary(['jira_issue_id', 'jira_release_id']);
            $table->index('jira_release_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('jira_issue_jira_release');
    }
};
