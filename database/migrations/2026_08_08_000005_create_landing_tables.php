<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Expression;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('landing_pages', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(new Expression('gen_random_uuid()'));
            $table->uuid('tenant_id');
            $table->string('title');
            $table->string('slug');
            $table->string('template', 50)->nullable();
            $table->string('status', 20)->default('draft');
            $table->string('seo_title')->nullable();
            $table->string('seo_description', 500)->nullable();
            $table->string('seo_keywords', 500)->nullable();
            $table->string('og_title')->nullable();
            $table->text('og_image_url')->nullable();
            $table->text('canonical_url')->nullable();
            $table->timestampTz('published_at')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->unique(['tenant_id', 'slug']);
        });

        Schema::create('page_sections', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(new Expression('gen_random_uuid()'));
            $table->uuid('tenant_id');
            $table->uuid('landing_page_id');
            $table->string('block_type', 30);
            $table->integer('sort_order')->default(0);
            $table->jsonb('config')->default('{}');
            $table->string('status', 20)->default('active');
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->foreign('landing_page_id')->references('id')->on('landing_pages')->onDelete('cascade');
        });

        Schema::create('media', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(new Expression('gen_random_uuid()'));
            $table->uuid('tenant_id');
            $table->text('url');
            $table->string('type', 20)->default('image');
            $table->string('mime_type', 100)->nullable();
            $table->bigInteger('size_bytes')->nullable();
            $table->string('alt_text')->nullable();
            $table->uuid('uploaded_by')->nullable();
            $table->timestampTz('created_at')->nullable()->useCurrent();

            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->foreign('uploaded_by')->references('id')->on('users')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media');
        Schema::dropIfExists('page_sections');
        Schema::dropIfExists('landing_pages');
    }
};
