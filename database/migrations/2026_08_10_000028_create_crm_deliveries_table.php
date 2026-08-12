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
        Schema::create('crm_deliveries', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(new Expression('gen_random_uuid()'));
            $table->uuid('tenant_id');
            $table->string('event', 50);
            $table->string('provider', 30);
            $table->string('endpoint', 500)->nullable();
            $table->string('status', 20)->default('pending');
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->unsignedTinyInteger('attempt')->default(0);
            $table->text('error')->nullable();
            $table->jsonb('payload');
            $table->timestampTz('sent_at')->nullable();
            $table->timestampTz('created_at')->nullable()->useCurrent();
            $table->timestampTz('updated_at')->nullable()->useCurrent();

            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
        });

        DB::statement("ALTER TABLE crm_deliveries ADD CONSTRAINT crm_deliveries_status_check CHECK (status IN ('pending','sent','failed'))");
        DB::statement('CREATE INDEX ix_crm_deliveries_tenant_status ON crm_deliveries(tenant_id, status)');
        DB::statement('CREATE INDEX ix_crm_deliveries_event ON crm_deliveries(event)');

        // Ikut gaya tabel tenant lain: RLS dimatikan/aktif via policy per tenant.
        DB::statement('CREATE INDEX idx_crm_deliveries_tenant ON crm_deliveries(tenant_id)');
        DB::statement('ALTER TABLE crm_deliveries ENABLE ROW LEVEL SECURITY');
        DB::statement(
            "CREATE POLICY tenant_isolation ON crm_deliveries USING (tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::uuid)"
        );
    }

    public function down(): void
    {
        DB::statement('DROP POLICY IF EXISTS tenant_isolation ON crm_deliveries');
        DB::statement('ALTER TABLE crm_deliveries DISABLE ROW LEVEL SECURITY');
        Schema::dropIfExists('crm_deliveries');
    }
};
