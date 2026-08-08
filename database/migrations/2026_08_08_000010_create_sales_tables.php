<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Expression;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_teams', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(new Expression('gen_random_uuid()'));
            $table->uuid('tenant_id');
            $table->string('name');
            $table->string('region', 100)->nullable();
            $table->uuid('product_category_id')->nullable();
            $table->timestampTz('created_at')->nullable()->useCurrent();

            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->foreign('product_category_id')->references('id')->on('product_categories')->onDelete('set null');
        });

        Schema::create('sales_team_members', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(new Expression('gen_random_uuid()'));
            $table->uuid('tenant_id');
            $table->uuid('sales_team_id');
            $table->uuid('user_id');

            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->foreign('sales_team_id')->references('id')->on('sales_teams')->onDelete('cascade');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->unique(['sales_team_id', 'user_id']);
        });

        Schema::create('sales_targets', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(new Expression('gen_random_uuid()'));
            $table->uuid('tenant_id');
            $table->uuid('user_id')->nullable();
            $table->uuid('sales_team_id')->nullable();
            $table->string('period', 20);
            $table->integer('target_leads')->nullable();
            $table->decimal('target_revenue', 18, 2)->nullable();
            $table->timestampTz('created_at')->nullable()->useCurrent();

            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('sales_team_id')->references('id')->on('sales_teams')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_targets');
        Schema::dropIfExists('sales_team_members');
        Schema::dropIfExists('sales_teams');
    }
};
