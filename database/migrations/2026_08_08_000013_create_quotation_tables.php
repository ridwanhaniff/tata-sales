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
        Schema::create('quotations', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(new Expression('gen_random_uuid()'));
            $table->uuid('tenant_id');
            $table->uuid('lead_id')->nullable();
            $table->uuid('customer_id');
            $table->uuid('created_by')->nullable();
            $table->string('status', 20)->default('draft');
            $table->decimal('subtotal', 18, 2)->default(0);
            $table->decimal('discount_total', 18, 2)->default(0);
            $table->decimal('total', 18, 2)->default(0);
            $table->text('notes')->nullable();
            $table->timestampTz('valid_until')->nullable();
            $table->timestampTz('sent_at')->nullable();
            $table->timestampTz('viewed_at')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->foreign('lead_id')->references('id')->on('leads')->onDelete('set null');
            $table->foreign('customer_id')->references('id')->on('customers');
            $table->foreign('created_by')->references('id')->on('users')->onDelete('set null');
        });

        DB::statement("ALTER TABLE quotations ADD CONSTRAINT quotations_status_check CHECK (status IN ('draft','sent','viewed','accepted','rejected','expired'))");

        Schema::create('quotation_items', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(new Expression('gen_random_uuid()'));
            $table->uuid('tenant_id');
            $table->uuid('quotation_id');
            $table->uuid('product_id')->nullable();
            $table->uuid('variant_id')->nullable();
            $table->string('description', 500);
            $table->integer('quantity')->default(1);
            $table->decimal('unit_price', 18, 2);
            $table->decimal('discount', 18, 2)->default(0);
            $table->decimal('line_total', 18, 2);

            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->foreign('quotation_id')->references('id')->on('quotations')->onDelete('cascade');
            $table->foreign('product_id')->references('id')->on('products')->onDelete('set null');
            $table->foreign('variant_id')->references('id')->on('product_variants')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quotation_items');
        Schema::dropIfExists('quotations');
    }
};
