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
        Schema::create('conversations', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(new Expression('gen_random_uuid()'));
            $table->uuid('tenant_id');
            $table->uuid('lead_id')->nullable();
            $table->uuid('customer_id')->nullable();
            $table->string('channel', 20)->default('whatsapp');
            $table->string('status', 20)->default('AI_ACTIVE');
            $table->uuid('assigned_to')->nullable();
            $table->jsonb('context')->default('{}');
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->foreign('lead_id')->references('id')->on('leads')->onDelete('cascade');
            $table->foreign('customer_id')->references('id')->on('customers')->onDelete('set null');
            $table->foreign('assigned_to')->references('id')->on('users')->onDelete('set null');
        });

        DB::statement("ALTER TABLE conversations ADD CONSTRAINT conversations_channel_check CHECK (channel IN ('whatsapp','webchat','email'))");
        DB::statement("ALTER TABLE conversations ADD CONSTRAINT conversations_status_check CHECK (status IN ('AI_ACTIVE','WAITING_HUMAN','HUMAN_ACTIVE','AI_RESUMED','CLOSED'))");

        Schema::create('conversation_messages', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(new Expression('gen_random_uuid()'));
            $table->uuid('tenant_id');
            $table->uuid('conversation_id');
            $table->string('sender_type', 20);
            $table->uuid('sender_id')->nullable();
            $table->text('content');
            $table->string('intent', 30)->nullable();
            $table->jsonb('metadata')->default('{}');
            $table->timestampTz('created_at')->nullable()->useCurrent();

            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->foreign('conversation_id')->references('id')->on('conversations')->onDelete('cascade');
        });

        DB::statement("ALTER TABLE conversation_messages ADD CONSTRAINT conversation_messages_sender_check CHECK (sender_type IN ('customer','ai','sales','system'))");
        DB::statement('CREATE INDEX idx_conv_messages_conv ON conversation_messages(conversation_id, created_at)');

        Schema::create('ai_agent_logs', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(new Expression('gen_random_uuid()'));
            $table->uuid('tenant_id');
            $table->uuid('conversation_id')->nullable();
            $table->uuid('lead_id')->nullable();
            $table->string('agent', 50);
            $table->string('tool_called', 100)->nullable();
            $table->jsonb('input')->nullable();
            $table->jsonb('output')->nullable();
            $table->decimal('confidence', 4, 3)->nullable();
            $table->string('status', 20);
            $table->integer('latency_ms')->nullable();
            $table->timestampTz('created_at')->nullable()->useCurrent();

            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->foreign('conversation_id')->references('id')->on('conversations')->onDelete('set null');
            $table->foreign('lead_id')->references('id')->on('leads')->onDelete('set null');
        });

        DB::statement("ALTER TABLE ai_agent_logs ADD CONSTRAINT ai_agent_logs_status_check CHECK (status IN ('success','failed','denied','handoff'))");
        DB::statement('CREATE INDEX idx_ai_agent_logs_conv ON ai_agent_logs(conversation_id, created_at)');
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_agent_logs');
        Schema::dropIfExists('conversation_messages');
        Schema::dropIfExists('conversations');
    }
};
