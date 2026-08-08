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
        Schema::create('product_categories', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(new Expression('gen_random_uuid()'));
            $table->uuid('tenant_id');
            $table->uuid('parent_id')->nullable();
            $table->string('name');
            $table->string('slug');
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->unique(['tenant_id', 'slug']);
        });

        Schema::table('product_categories', function (Blueprint $table) {
            $table->foreign('parent_id')->references('id')->on('product_categories')->onDelete('set null');
        });

        Schema::create('products', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(new Expression('gen_random_uuid()'));
            $table->uuid('tenant_id');
            $table->uuid('category_id')->nullable();
            $table->string('name');
            $table->string('slug');
            $table->text('description')->nullable();
            $table->string('short_description', 500)->nullable();
            $table->decimal('base_price', 18, 2)->default(0);
            $table->string('status', 20)->default('draft');
            $table->string('stock_status', 20)->default('available');
            $table->boolean('featured')->default(false);
            $table->string('seo_title')->nullable();
            $table->string('seo_description', 500)->nullable();
            $table->text('og_image_url')->nullable();
            $table->timestampTz('published_at')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->foreign('category_id')->references('id')->on('product_categories')->onDelete('set null');
            $table->unique(['tenant_id', 'slug']);
        });

        Schema::create('product_variants', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(new Expression('gen_random_uuid()'));
            $table->uuid('tenant_id');
            $table->uuid('product_id');
            $table->string('name');
            $table->string('sku', 100)->nullable();
            $table->decimal('price', 18, 2);
            $table->integer('stock')->default(0);
            $table->string('status', 20)->default('active');
            $table->jsonb('metadata')->default('{}');
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->foreign('product_id')->references('id')->on('products')->onDelete('cascade');
        });

        Schema::create('product_images', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(new Expression('gen_random_uuid()'));
            $table->uuid('tenant_id');
            $table->uuid('product_id');
            $table->uuid('variant_id')->nullable();
            $table->text('url');
            $table->string('alt_text')->nullable();
            $table->integer('sort_order')->default(0);
            $table->timestampTz('created_at')->nullable()->useCurrent();

            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->foreign('product_id')->references('id')->on('products')->onDelete('cascade');
            $table->foreign('variant_id')->references('id')->on('product_variants')->onDelete('cascade');
        });

        Schema::create('product_attributes', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(new Expression('gen_random_uuid()'));
            $table->uuid('tenant_id');
            $table->uuid('product_id');
            $table->string('attribute_key', 100);
            $table->text('attribute_value')->nullable();
            $table->string('attribute_type', 20)->default('text');
            $table->timestampTz('created_at')->nullable()->useCurrent();

            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->foreign('product_id')->references('id')->on('products')->onDelete('cascade');
            $table->unique(['product_id', 'attribute_key']);
        });

        DB::statement("ALTER TABLE products ADD CONSTRAINT products_stock_status_check CHECK (stock_status IN ('available','low_stock','out_of_stock','preorder','hidden'))");
        DB::statement("ALTER TABLE product_attributes ADD CONSTRAINT product_attributes_type_check CHECK (attribute_type IN ('text','number','boolean','date'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('product_attributes');
        Schema::dropIfExists('product_images');
        Schema::dropIfExists('product_variants');
        Schema::dropIfExists('products');
        Schema::dropIfExists('product_categories');
    }
};
