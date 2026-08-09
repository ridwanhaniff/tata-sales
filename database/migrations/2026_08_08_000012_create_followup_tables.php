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
        Schema::create('followups', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(new Expression('gen_random_uuid()'));
            $table->uuid('tenant_id');
            $table->uuid('lead_id');
            $table->uuid('assigned_to')->nullable();
            $table->string('status', 20)->default('pending');
            $table->string('channel', 20)->default('whatsapp');
            $table->timestamp('scheduled_at');
            $table->timestamp('sent_at')->nullable();
            $table->text('message')->nullable();
            $table->timestamp('created_at')->nullable()->useCurrent();

            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->foreign('lead_id')->references('id')->on('leads')->onDelete('cascade');
            $table->foreign('assigned_to')->references('id')->on('users')->onDelete('set null');
        });

        DB::statement("ALTER TABLE followups ADD CONSTRAINT followups_status_check CHECK (status IN ('pending','sent','skipped','failed'))");
        DB::statement('CREATE INDEX idx_followups_due ON followups(tenant_id, status, scheduled_at)');

        Schema::create('followup_steps', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(new Expression('gen_random_uuid()'));
            $table->uuid('tenant_id');
            $table->string('name');
            $table->string('trigger_event', 50);
            $table->integer('delay_minutes');
            $table->jsonb('condition')->nullable();
            $table->string('action', 30)->default('create_followup');
            $table->integer('sort_order')->default(0);
            $table->string('status', 20)->default('active');

            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('followup_steps');
        Schema::dropIfExists('followups');
    }
};
