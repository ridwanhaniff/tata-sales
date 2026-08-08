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
        Schema::create('customers', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(new Expression('gen_random_uuid()'));
            $table->uuid('tenant_id');
            $table->string('name')->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('email')->nullable();
            $table->string('location')->nullable();
            $table->string('source', 50)->nullable();
            $table->text('tags')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('consent_marketing')->default(false);
            $table->timestampTz('consent_at')->nullable();
            $table->string('consent_version', 20)->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
        });

        DB::statement('CREATE UNIQUE INDEX uq_customers_tenant_phone ON customers(tenant_id, phone) WHERE phone IS NOT NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
