<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Balasan AI chat via WhatsApp bisa dikirim ke customer yang belum punya
     * lead (belum ada consent) — lead_id wajib menjadi nullable.
     */
    public function up(): void
    {
        Schema::table('whatsapp_messages', function (Blueprint $table) {
            $table->uuid('lead_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_messages', function (Blueprint $table) {
            $table->uuid('lead_id')->nullable(false)->change();
        });
    }
};
