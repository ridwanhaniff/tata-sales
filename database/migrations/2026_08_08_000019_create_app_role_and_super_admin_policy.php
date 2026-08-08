<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("DO \$\$ BEGIN IF NOT EXISTS (SELECT FROM pg_roles WHERE rolname = 'tata_app') THEN CREATE ROLE tata_app LOGIN PASSWORD 'tata_app_dev'; END IF; END \$\$;");

        DB::statement('GRANT USAGE ON SCHEMA public TO tata_app');
        DB::statement('GRANT ALL PRIVILEGES ON ALL TABLES IN SCHEMA public TO tata_app');
        DB::statement('GRANT ALL PRIVILEGES ON ALL SEQUENCES IN SCHEMA public TO tata_app');
        DB::statement('GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA public TO tata_app');

        // Super admin adalah satu-satunya user dengan tenant_id NULL (§9 docs).
        // Policy tenant_isolation standar (tenant_id = app.tenant_id) menolak
        // baris dengan tenant_id NULL, jadi dibutuhkan policy khusus agar
        // super_admin bisa login & mengelola tenant tanpa tenant context.
        DB::statement(
            'CREATE POLICY tenant_isolation_super_admin ON users
             USING (tenant_id IS NULL)
             WITH CHECK (tenant_id IS NULL)'
        );
    }

    public function down(): void
    {
        DB::statement('DROP POLICY IF EXISTS tenant_isolation_super_admin ON users');
        DB::statement('REVOKE ALL PRIVILEGES ON ALL TABLES IN SCHEMA public FROM tata_app');
        DB::statement('DROP ROLE IF EXISTS tata_app');
    }
};
