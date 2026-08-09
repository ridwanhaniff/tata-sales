<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Expression;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Knowledge Base v1 (Sprint 11, §66): artikel terstruktur FAQ/policy/script,
 * approved data untuk agent — bukan sumber kebenaran AI, tapi data yang
 * hanya boleh dipakai lewat tool search_knowledge.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('knowledge_base_articles', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(new Expression('gen_random_uuid()'));
            $table->uuid('tenant_id');
            $table->string('category', 20)->default('faq'); // faq | policy | script
            $table->string('title');
            $table->text('content');
            $table->jsonb('keywords')->nullable(); // array<string> untuk retrieval sederhana
            $table->string('status', 20)->default('active'); // active | inactive
            $table->timestamp('created_at')->nullable()->useCurrent();
            $table->timestamp('updated_at')->nullable()->useCurrent();

            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
        });

        DB::statement(
            'CREATE INDEX idx_knowledge_base_articles_tenant ON knowledge_base_articles(tenant_id)'
        );
        DB::statement('ALTER TABLE knowledge_base_articles ENABLE ROW LEVEL SECURITY');
        DB::statement(
            "CREATE POLICY tenant_isolation ON knowledge_base_articles
             USING (tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::uuid)"
        );
        DB::statement(
            'CREATE TRIGGER trg_set_updated_at BEFORE UPDATE ON knowledge_base_articles
             FOR EACH ROW EXECUTE FUNCTION set_updated_at()'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('knowledge_base_articles');
    }
};
