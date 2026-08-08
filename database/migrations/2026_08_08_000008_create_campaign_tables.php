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
        Schema::create('campaigns', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(new Expression('gen_random_uuid()'));
            $table->uuid('tenant_id');
            $table->string('name');
            $table->string('utm_campaign')->nullable();
            $table->string('status', 20)->default('active');
            $table->decimal('budget', 18, 2)->nullable();
            $table->timestampTz('starts_at')->nullable();
            $table->timestampTz('ends_at')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
        });

        Schema::create('campaign_sources', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(new Expression('gen_random_uuid()'));
            $table->uuid('tenant_id');
            $table->uuid('campaign_id');
            $table->string('utm_source', 100)->nullable();
            $table->string('utm_medium', 100)->nullable();
            $table->string('utm_content', 100)->nullable();
            $table->string('utm_term', 100)->nullable();
            $table->text('referrer')->nullable();
            $table->uuid('landing_page_id')->nullable();

            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->foreign('campaign_id')->references('id')->on('campaigns')->onDelete('cascade');
            $table->foreign('landing_page_id')->references('id')->on('landing_pages')->onDelete('set null');
        });

        Schema::create('campaign_events', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(new Expression('gen_random_uuid()'));
            $table->uuid('tenant_id');
            $table->uuid('campaign_id')->nullable();
            $table->string('visitor_id', 100)->nullable();
            $table->string('event_type', 50);
            $table->jsonb('event_data')->default('{}');
            $table->timestampTz('occurred_at')->default(new Expression('now()'));

            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->foreign('campaign_id')->references('id')->on('campaigns')->onDelete('set null');
        });
        DB::statement('CREATE INDEX idx_campaign_events_tenant_type ON campaign_events(tenant_id, event_type, occurred_at)');
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_events');
        Schema::dropIfExists('campaign_sources');
        Schema::dropIfExists('campaigns');
    }
};
