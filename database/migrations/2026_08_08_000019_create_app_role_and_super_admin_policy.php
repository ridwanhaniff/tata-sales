<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    /**
     * Role aplikasi `tata_app` (§9): koneksi non-owner dengan RLS aktif.
     * Pembuatan role bersifat opsional-resilien — di Supabase provision
     * role bisa dibatasi (CREATEROLE tidak selalu tersedia untuk postgres),
     * jadi kalau gagal migrasi tetap lanjut: koneksi `postgres` (owner)
     * mem-bypass RLS dan isolasi tenant tetap dijaga di lapisan aplikasi.
     *
     * Default privileges di-set supaya kelak bisa migrasi ke role ini
     * tanpa kehilangan akses ke tabel yang dibuat setelah migrasi.
     */
    public function up(): void
    {
        try {
            DB::statement("DO \$\$ BEGIN IF NOT EXISTS (SELECT FROM pg_roles WHERE rolname = 'tata_app') THEN CREATE ROLE tata_app LOGIN PASSWORD 'tata_app_dev'; END IF; END \$\$;");

            DB::statement('GRANT USAGE ON SCHEMA public TO tata_app');
            DB::statement('GRANT ALL PRIVILEGES ON ALL TABLES IN SCHEMA public TO tata_app');
            DB::statement('GRANT ALL PRIVILEGES ON ALL SEQUENCES IN SCHEMA public TO tata_app');
            DB::statement('GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA public TO tata_app');
            DB::statement('ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT ALL PRIVILEGES ON TABLES TO tata_app');
            DB::statement('ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT ALL PRIVILEGES ON SEQUENCES TO tata_app');
        } catch (Throwable $e) {
            Log::warning('migration.tata_app_role_skipped', ['error' => $e->getMessage()]);
        }

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

        try {
            DB::statement('REVOKE ALL PRIVILEGES ON ALL TABLES IN SCHEMA public FROM tata_app');
            DB::statement('DROP ROLE IF EXISTS tata_app');
        } catch (Throwable $e) {
            Log::warning('migration.tata_app_role_drop_skipped', ['error' => $e->getMessage()]);
        }
    }
};
