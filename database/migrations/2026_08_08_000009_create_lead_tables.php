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
        Schema::create('pipeline_stages', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(new Expression('gen_random_uuid()'));
            $table->uuid('tenant_id');
            $table->string('key', 50);
            $table->string('label');
            $table->integer('sort_order')->default(0);
            $table->boolean('is_won')->default(false);
            $table->boolean('is_lost')->default(false);
            $table->timestampTz('created_at')->nullable()->useCurrent();

            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->unique(['tenant_id', 'key']);
        });

        Schema::create('leads', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(new Expression('gen_random_uuid()'));
            $table->uuid('tenant_id');
            $table->uuid('customer_id');
            $table->uuid('product_id')->nullable();
            $table->uuid('variant_id')->nullable();
            $table->string('source', 50)->nullable();
            $table->uuid('campaign_id')->nullable();
            $table->string('status', 30)->default('NEW');
            $table->string('temperature', 10)->default('COLD');
            $table->integer('score')->default(0);
            $table->decimal('estimated_value', 18, 2)->nullable();
            $table->uuid('assigned_to')->nullable();
            $table->string('provider_event_id')->nullable();
            $table->timestampTz('last_activity_at')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->foreign('customer_id')->references('id')->on('customers')->onDelete('cascade');
            $table->foreign('product_id')->references('id')->on('products')->onDelete('set null');
            $table->foreign('variant_id')->references('id')->on('product_variants')->onDelete('set null');
            $table->foreign('campaign_id')->references('id')->on('campaigns')->onDelete('set null');
            $table->foreign('assigned_to')->references('id')->on('users')->onDelete('set null');
        });

        DB::statement("ALTER TABLE leads ADD CONSTRAINT leads_temperature_check CHECK (temperature IN ('COLD','WARM','HOT'))");
        DB::statement('CREATE UNIQUE INDEX uq_leads_provider_event ON leads(tenant_id, provider_event_id) WHERE provider_event_id IS NOT NULL');
        DB::statement('CREATE INDEX idx_leads_tenant_status ON leads(tenant_id, status)');
        DB::statement('CREATE INDEX idx_leads_tenant_assigned ON leads(tenant_id, assigned_to)');
        DB::statement('CREATE INDEX idx_leads_tenant_temperature ON leads(tenant_id, temperature)');
        DB::statement('CREATE INDEX idx_leads_customer ON leads(customer_id)');

        Schema::create('lead_events', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(new Expression('gen_random_uuid()'));
            $table->uuid('tenant_id');
            $table->uuid('lead_id');
            $table->string('event_type', 50);
            $table->jsonb('event_data')->default('{}');
            $table->timestampTz('occurred_at')->default(new Expression('now()'));

            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->foreign('lead_id')->references('id')->on('leads')->onDelete('cascade');
        });
        DB::statement('CREATE INDEX idx_lead_events_lead ON lead_events(lead_id, occurred_at)');

        Schema::create('lead_scores', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(new Expression('gen_random_uuid()'));
            $table->uuid('tenant_id');
            $table->uuid('lead_id');
            $table->string('event_type', 50);
            $table->integer('points');
            $table->integer('resulting_score');
            $table->timestampTz('created_at')->nullable()->useCurrent();

            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->foreign('lead_id')->references('id')->on('leads')->onDelete('cascade');
        });

        Schema::create('lead_assignments', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(new Expression('gen_random_uuid()'));
            $table->uuid('tenant_id');
            $table->uuid('lead_id');
            $table->uuid('assigned_to');
            $table->uuid('assigned_by')->nullable();
            $table->string('method', 20);
            $table->timestampTz('assigned_at')->default(new Expression('now()'));
            $table->timestampTz('unassigned_at')->nullable();

            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->foreign('lead_id')->references('id')->on('leads')->onDelete('cascade');
            $table->foreign('assigned_to')->references('id')->on('users');
            $table->foreign('assigned_by')->references('id')->on('users');
        });
        DB::statement("ALTER TABLE lead_assignments ADD CONSTRAINT lead_assignments_method_check CHECK (method IN ('round_robin','product','location','workload','manual'))");

        Schema::create('voucher_usages', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(new Expression('gen_random_uuid()'));
            $table->uuid('tenant_id');
            $table->uuid('voucher_id');
            $table->uuid('customer_id')->nullable();
            $table->uuid('lead_id')->nullable();
            $table->timestampTz('used_at')->default(new Expression('now()'));

            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->foreign('voucher_id')->references('id')->on('vouchers')->onDelete('cascade');
            $table->foreign('customer_id')->references('id')->on('customers')->onDelete('set null');
            $table->foreign('lead_id')->references('id')->on('leads')->onDelete('set null');
        });

        Schema::create('calculator_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(new Expression('gen_random_uuid()'));
            $table->uuid('tenant_id');
            $table->uuid('calculator_id');
            $table->uuid('customer_id')->nullable();
            $table->uuid('lead_id')->nullable();
            $table->jsonb('input_data');
            $table->jsonb('output_data');
            $table->timestampTz('created_at')->nullable()->useCurrent();

            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->foreign('calculator_id')->references('id')->on('calculators');
            $table->foreign('customer_id')->references('id')->on('customers')->onDelete('set null');
            $table->foreign('lead_id')->references('id')->on('leads')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calculator_sessions');
        Schema::dropIfExists('voucher_usages');
        Schema::dropIfExists('lead_assignments');
        Schema::dropIfExists('lead_scores');
        Schema::dropIfExists('lead_events');
        Schema::dropIfExists('leads');
        Schema::dropIfExists('pipeline_stages');
    }
};
