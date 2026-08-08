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
        Schema::create('activities', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(new Expression('gen_random_uuid()'));
            $table->uuid('tenant_id');
            $table->uuid('lead_id')->nullable();
            $table->uuid('customer_id')->nullable();
            $table->uuid('user_id')->nullable();
            $table->string('activity_type', 50);
            $table->text('description')->nullable();
            $table->jsonb('metadata')->default('{}');
            $table->timestampTz('created_at')->nullable()->useCurrent();

            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->foreign('lead_id')->references('id')->on('leads')->onDelete('cascade');
            $table->foreign('customer_id')->references('id')->on('customers')->onDelete('set null');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('set null');
        });

        Schema::create('tasks', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(new Expression('gen_random_uuid()'));
            $table->uuid('tenant_id');
            $table->uuid('lead_id')->nullable();
            $table->uuid('assigned_to')->nullable();
            $table->string('title');
            $table->text('description')->nullable();
            $table->timestampTz('due_at')->nullable();
            $table->string('status', 20)->default('open');
            $table->uuid('created_by')->nullable();
            $table->timestampTz('created_at')->nullable()->useCurrent();
            $table->timestampTz('completed_at')->nullable();

            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->foreign('lead_id')->references('id')->on('leads')->onDelete('cascade');
            $table->foreign('assigned_to')->references('id')->on('users')->onDelete('set null');
            $table->foreign('created_by')->references('id')->on('users')->onDelete('set null');
        });

        DB::statement("ALTER TABLE tasks ADD CONSTRAINT tasks_status_check CHECK (status IN ('open','done','cancelled'))");

        Schema::create('notes', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(new Expression('gen_random_uuid()'));
            $table->uuid('tenant_id');
            $table->uuid('lead_id')->nullable();
            $table->uuid('customer_id')->nullable();
            $table->uuid('user_id')->nullable();
            $table->text('content');
            $table->timestampTz('created_at')->nullable()->useCurrent();

            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->foreign('lead_id')->references('id')->on('leads')->onDelete('cascade');
            $table->foreign('customer_id')->references('id')->on('customers')->onDelete('set null');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notes');
        Schema::dropIfExists('tasks');
        Schema::dropIfExists('activities');
    }
};
