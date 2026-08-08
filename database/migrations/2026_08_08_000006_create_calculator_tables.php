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
        Schema::create('calculators', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(new Expression('gen_random_uuid()'));
            $table->uuid('tenant_id');
            $table->string('name');
            $table->string('type', 50);
            $table->string('status', 20)->default('active');
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
        });

        Schema::create('calculator_inputs', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(new Expression('gen_random_uuid()'));
            $table->uuid('tenant_id');
            $table->uuid('calculator_id');
            $table->string('key', 100);
            $table->string('label');
            $table->string('data_type', 20)->default('number');
            $table->decimal('min_value', 18, 2)->nullable();
            $table->decimal('max_value', 18, 2)->nullable();
            $table->jsonb('options')->nullable();
            $table->boolean('is_required')->default(true);
            $table->integer('sort_order')->default(0);

            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->foreign('calculator_id')->references('id')->on('calculators')->onDelete('cascade');
            $table->unique(['calculator_id', 'key']);
        });

        Schema::create('calculator_rules', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(new Expression('gen_random_uuid()'));
            $table->uuid('tenant_id');
            $table->uuid('calculator_id');
            $table->text('formula');
            $table->string('rounding_policy', 20)->default('round');
            $table->integer('sort_order')->default(0);
            $table->timestampTz('created_at')->nullable()->useCurrent();

            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->foreign('calculator_id')->references('id')->on('calculators')->onDelete('cascade');
        });

        Schema::create('calculator_outputs', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(new Expression('gen_random_uuid()'));
            $table->uuid('tenant_id');
            $table->uuid('calculator_id');
            $table->string('key', 100);
            $table->string('label');
            $table->string('format', 20)->default('currency');
            $table->integer('sort_order')->default(0);

            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->foreign('calculator_id')->references('id')->on('calculators')->onDelete('cascade');
        });

        DB::statement("ALTER TABLE calculator_inputs ADD CONSTRAINT calculator_inputs_data_type_check CHECK (data_type IN ('number','select','boolean'))");
        DB::statement("ALTER TABLE calculator_rules ADD CONSTRAINT calculator_rules_rounding_check CHECK (rounding_policy IN ('round','floor','ceil'))");
        DB::statement("ALTER TABLE calculator_outputs ADD CONSTRAINT calculator_outputs_format_check CHECK (format IN ('currency','number','text'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('calculator_outputs');
        Schema::dropIfExists('calculator_rules');
        Schema::dropIfExists('calculator_inputs');
        Schema::dropIfExists('calculators');
    }
};
