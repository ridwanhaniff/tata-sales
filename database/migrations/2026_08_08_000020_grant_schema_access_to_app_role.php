<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('GRANT CREATE ON SCHEMA public TO tata_app');
        DB::statement('ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT ALL PRIVILEGES ON TABLES TO tata_app');
        DB::statement('ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT ALL PRIVILEGES ON SEQUENCES TO tata_app');
    }

    public function down(): void
    {
        DB::statement('REVOKE CREATE ON SCHEMA public FROM tata_app');
        DB::statement('ALTER DEFAULT PRIVILEGES IN SCHEMA public REVOKE ALL PRIVILEGES ON TABLES FROM tata_app');
        DB::statement('ALTER DEFAULT PRIVILEGES IN SCHEMA public REVOKE ALL PRIVILEGES ON SEQUENCES FROM tata_app');
    }
};
