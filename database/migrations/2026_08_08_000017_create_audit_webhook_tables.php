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
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(new Expression('gen_random_uuid()'));
            $table->uuid('tenant_id');
            $table->uuid('actor_id')->nullable();
            $table->string('actor_type', 20)->default('user');
            $table->string('action', 100);
            $table->string('entity_type', 50);
            $table->uuid('entity_id')->nullable();
            $table->jsonb('before_data')->nullable();
            $table->jsonb('after_data')->nullable();
            $table->ipAddress('ip_address')->nullable();
            $table->timestampTz('created_at')->nullable()->useCurrent();

            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->foreign('actor_id')->references('id')->on('users')->onDelete('set null');
        });

        DB::statement("ALTER TABLE audit_logs ADD CONSTRAINT audit_logs_actor_type_check CHECK (actor_type IN ('user','system','ai'))");
        DB::statement('CREATE INDEX ix_audit_logs_entity ON audit_logs(tenant_id, entity_type, entity_id)');

        Schema::create('webhook_events', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(new Expression('gen_random_uuid()'));
            $table->uuid('tenant_id')->nullable();
            $table->string('provider', 50);
            $table->string('provider_event_id');
            $table->jsonb('payload');
            $table->string('status', 20)->default('received');
            $table->timestampTz('processed_at')->nullable();
            $table->timestampTz('created_at')->nullable()->useCurrent();

            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->unique(['provider', 'provider_event_id']);
        });

        DB::statement("ALTER TABLE webhook_events ADD CONSTRAINT webhook_events_status_check CHECK (status IN ('received','processed','failed','duplicate'))");
        DB::statement('CREATE INDEX ix_webhook_events_tenant ON webhook_events(tenant_id)');
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_events');
        Schema::dropIfExists('audit_logs');
    }
};
