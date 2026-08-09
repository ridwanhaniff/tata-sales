<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Expression;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // WhatsApp Business API — log pesan keluar terkirim via provider (§25, Sprint 12)
        Schema::create('whatsapp_messages', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(new Expression('gen_random_uuid()'));
            $table->uuid('tenant_id');
            $table->uuid('lead_id');
            $table->uuid('followup_id')->nullable();
            $table->uuid('quotation_id')->nullable();
            $table->string('to_phone', 30);
            $table->string('provider', 30)->default('echo');
            $table->string('status', 20)->default('queued'); // queued/sent/failed/delivered/read
            $table->string('provider_message_id', 100)->nullable();
            $table->string('provider_error', 500)->nullable();
            $table->text('message');
            $table->jsonb('payload')->nullable();
            $table->timestampTz('sent_at')->nullable();
            $table->timestampTz('delivered_at')->nullable();
            $table->timestampTz('read_at')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->foreign('lead_id')->references('id')->on('leads')->onDelete('cascade');
            $table->foreign('followup_id')->references('id')->on('followups')->onDelete('set null');
            $table->foreign('quotation_id')->references('id')->on('quotations')->onDelete('set null');
        });

        DB::statement(
            'CREATE INDEX idx_whatsapp_messages_tenant ON whatsapp_messages(tenant_id)'
        );
        DB::statement('ALTER TABLE whatsapp_messages ENABLE ROW LEVEL SECURITY');
        DB::statement(
            "CREATE POLICY tenant_isolation ON whatsapp_messages
             USING (tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::uuid)"
        );
        DB::statement(
            'CREATE TRIGGER trg_set_updated_at BEFORE UPDATE ON whatsapp_messages
             FOR EACH ROW EXECUTE FUNCTION set_updated_at()'
        );

        // Quotation engine (§99): token publik untuk link tracking + jawaban customer.
        if (! Schema::hasColumn('quotations', 'public_token')) {
            Schema::table('quotations', function (Blueprint $table) {
                $table->string('public_token', 64)->nullable()->unique();
                $table->timestampTz('responded_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::table('quotations', function (Blueprint $table) {
            $table->dropUnique(['public_token']);
            $table->dropColumn(['public_token', 'responded_at']);
        });

        Schema::dropIfExists('whatsapp_messages');
    }
};
