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
        DB::statement('CREATE EXTENSION IF NOT EXISTS "pgcrypto"');

        DB::statement(<<<'SQL'
CREATE OR REPLACE FUNCTION set_updated_at()
RETURNS TRIGGER AS $$
BEGIN
  NEW.updated_at = now();
  RETURN NEW;
END;
$$ LANGUAGE plpgsql;
SQL);

        Schema::create('tenants', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(new Expression('gen_random_uuid()'));
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('domain')->nullable()->unique();
            $table->string('industry_template', 50)->nullable();
            $table->string('timezone', 50)->default('Asia/Jakarta');
            $table->string('status', 20)->default('active');
            $table->string('plan', 50)->default('starter');
            $table->jsonb('settings')->default('{}');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenants');
        DB::statement('DROP FUNCTION IF EXISTS set_updated_at()');
    }
};
