<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Clear every user's last full sync so the next scheduled sync pulls the
     * whole project again, backfilling release links (`fixVersions`) for
     * issues an incremental sync would not touch.
     */
    public function up(): void
    {
        DB::table('users')->update(['jira_last_full_synced_at' => null]);
    }

    /**
     * The cleared timestamps cannot be restored; the next full sync sets them.
     */
    public function down(): void
    {
        //
    }
};
