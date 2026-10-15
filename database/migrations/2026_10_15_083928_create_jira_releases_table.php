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
        Schema::create('jira_releases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('jira_id');
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('released')->default(false);
            $table->boolean('archived')->default(false);
            $table->boolean('overdue')->default(false);
            $table->date('start_date')->nullable();
            $table->date('release_date')->nullable();
            $table->string('jira_url');
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
        Schema::dropIfExists('jira_releases');
    }
};
