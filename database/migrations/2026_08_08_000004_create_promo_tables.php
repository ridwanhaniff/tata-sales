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
        Schema::create('promotions', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(new Expression('gen_random_uuid()'));
            $table->uuid('tenant_id');
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('discount_type', 20);
            $table->decimal('discount_value', 18, 2)->nullable();
            $table->decimal('minimum_purchase', 18, 2)->nullable();
            $table->integer('usage_limit')->nullable();
            $table->integer('usage_count')->default(0);
            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at');
            $table->string('status', 20)->default('draft');
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
        });
        DB::statement('CREATE INDEX idx_promotions_active_window ON promotions(tenant_id, status, starts_at, ends_at)');

        Schema::create('promotion_products', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(new Expression('gen_random_uuid()'));
            $table->uuid('tenant_id');
            $table->uuid('promotion_id');
            $table->uuid('product_id');

            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->foreign('promotion_id')->references('id')->on('promotions')->onDelete('cascade');
            $table->foreign('product_id')->references('id')->on('products')->onDelete('cascade');
            $table->unique(['promotion_id', 'product_id']);
        });

        Schema::create('promotion_rules', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(new Expression('gen_random_uuid()'));
            $table->uuid('tenant_id');
            $table->uuid('promotion_id');
            $table->string('rule_type', 30);
            $table->string('operator', 10)->default('=');
            $table->jsonb('value');
            $table->timestampTz('created_at')->nullable()->useCurrent();

            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->foreign('promotion_id')->references('id')->on('promotions')->onDelete('cascade');
        });

        Schema::create('vouchers', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(new Expression('gen_random_uuid()'));
            $table->uuid('tenant_id');
            $table->uuid('promotion_id')->nullable();
            $table->string('code', 50);
            $table->string('discount_type', 20);
            $table->decimal('discount_value', 18, 2)->nullable();
            $table->decimal('minimum_purchase', 18, 2)->nullable();
            $table->integer('usage_limit')->nullable();
            $table->integer('per_customer_limit')->default(1);
            $table->integer('usage_count')->default(0);
            $table->timestampTz('expires_at')->nullable();
            $table->string('status', 20)->default('active');
            $table->timestampTz('created_at')->nullable()->useCurrent();

            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->foreign('promotion_id')->references('id')->on('promotions')->onDelete('cascade');
            $table->unique(['tenant_id', 'code']);
        });

        DB::statement("ALTER TABLE vouchers ADD CONSTRAINT vouchers_status_check CHECK (status IN ('active','disabled','expired'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('vouchers');
        Schema::dropIfExists('promotion_rules');
        Schema::dropIfExists('promotion_products');
        Schema::dropIfExists('promotions');
    }
};
