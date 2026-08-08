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
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(new Expression('gen_random_uuid()'));
            $table->uuid('tenant_id');
            $table->uuid('user_id');
            $table->string('channel', 20)->default('dashboard');
            $table->string('type', 50);
            $table->string('title');
            $table->text('body')->nullable();
            $table->jsonb('data')->default('{}');
            $table->timestampTz('read_at')->nullable();
            $table->timestampTz('sent_at')->nullable();
            $table->timestampTz('created_at')->nullable()->useCurrent();

            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
        });

        DB::statement("ALTER TABLE notifications ADD CONSTRAINT notifications_channel_check CHECK (channel IN ('dashboard','email','whatsapp','webhook'))");
        DB::statement('CREATE INDEX idx_notifications_user_unread ON notifications(user_id, read_at)');
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
