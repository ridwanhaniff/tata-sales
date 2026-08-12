<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Node `human` workflow (§34) = hard-stop menunggu aksi sales: status
     * run baru `waiting_human` (lanjut via WorkflowEngine::resume()).
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE workflow_runs DROP CONSTRAINT workflow_runs_status_check');

        DB::statement("ALTER TABLE workflow_runs ADD CONSTRAINT workflow_runs_status_check CHECK (status IN ('running','completed','failed','cancelled','waiting_human'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE workflow_runs DROP CONSTRAINT workflow_runs_status_check');

        DB::statement("ALTER TABLE workflow_runs ADD CONSTRAINT workflow_runs_status_check CHECK (status IN ('running','completed','failed','cancelled'))");
    }
};
