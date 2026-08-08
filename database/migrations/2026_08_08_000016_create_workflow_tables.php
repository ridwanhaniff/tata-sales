<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Expression;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workflows', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(new Expression('gen_random_uuid()'));
            $table->uuid('tenant_id');
            $table->string('name');
            $table->string('trigger_event', 50);
            $table->string('status', 20)->default('active');
            $table->jsonb('definition');
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
        });

        DB::statement("ALTER TABLE workflows ADD CONSTRAINT workflows_status_check CHECK (status IN ('active','paused','draft'))");

        Schema::create('workflow_nodes', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(new Expression('gen_random_uuid()'));
            $table->uuid('tenant_id');
            $table->uuid('workflow_id');
            $table->string('node_type', 20);
            $table->jsonb('config')->default('{}');
            $table->integer('sort_order')->default(0);
            $table->uuid('next_node_id')->nullable();

            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->foreign('workflow_id')->references('id')->on('workflows')->onDelete('cascade');
        });

        Schema::table('workflow_nodes', function (Blueprint $table) {
            $table->foreign('next_node_id')->references('id')->on('workflow_nodes')->onDelete('set null');
        });

        DB::statement("ALTER TABLE workflow_nodes ADD CONSTRAINT workflow_nodes_type_check CHECK (node_type IN ('trigger','condition','action','delay','ai','human','end'))");

        Schema::create('workflow_runs', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(new Expression('gen_random_uuid()'));
            $table->uuid('tenant_id');
            $table->uuid('workflow_id');
            $table->uuid('lead_id')->nullable();
            $table->uuid('conversation_id')->nullable();
            $table->string('status', 20)->default('running');
            $table->uuid('current_node_id')->nullable();
            $table->timestampTz('started_at')->default(new Expression('now()'));
            $table->timestampTz('finished_at')->nullable();

            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->foreign('workflow_id')->references('id')->on('workflows')->onDelete('cascade');
            $table->foreign('lead_id')->references('id')->on('leads')->onDelete('set null');
            $table->foreign('conversation_id')->references('id')->on('conversations')->onDelete('set null');
            $table->foreign('current_node_id')->references('id')->on('workflow_nodes')->onDelete('set null');
        });

        DB::statement("ALTER TABLE workflow_runs ADD CONSTRAINT workflow_runs_status_check CHECK (status IN ('running','completed','failed','cancelled'))");

        Schema::create('workflow_logs', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(new Expression('gen_random_uuid()'));
            $table->uuid('tenant_id');
            $table->uuid('workflow_run_id');
            $table->uuid('node_id')->nullable();
            $table->string('status', 20);
            $table->jsonb('input')->nullable();
            $table->jsonb('output')->nullable();
            $table->text('error')->nullable();
            $table->timestampTz('created_at')->nullable()->useCurrent();

            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->foreign('workflow_run_id')->references('id')->on('workflow_runs')->onDelete('cascade');
            $table->foreign('node_id')->references('id')->on('workflow_nodes')->onDelete('set null');
        });

        DB::statement("ALTER TABLE workflow_logs ADD CONSTRAINT workflow_logs_status_check CHECK (status IN ('success','failed','skipped'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_logs');
        Schema::dropIfExists('workflow_runs');
        Schema::dropIfExists('workflow_nodes');
        Schema::dropIfExists('workflows');
    }
};
