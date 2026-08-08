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
        Schema::create('users', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(new Expression('gen_random_uuid()'));
            $table->uuid('tenant_id')->nullable();
            $table->string('name');
            $table->string('email');
            $table->string('phone', 30)->nullable();
            $table->string('password_hash');
            $table->string('role', 30);
            $table->string('status', 20)->default('active');
            $table->timestampTz('last_login_at')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
        });

        DB::statement("ALTER TABLE users ADD CONSTRAINT users_role_check CHECK (role IN ('super_admin','owner','manager','sales','content_manager'))");
        DB::statement('CREATE UNIQUE INDEX uq_users_tenant_email ON users(tenant_id, email)');
        DB::statement('CREATE INDEX idx_users_tenant ON users(tenant_id)');
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
