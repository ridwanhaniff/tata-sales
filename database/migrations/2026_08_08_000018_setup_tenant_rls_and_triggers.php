<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $tenantTables = [
            'users', 'product_categories', 'products', 'product_variants', 'product_images',
            'product_attributes', 'promotions', 'promotion_products', 'promotion_rules',
            'vouchers', 'landing_pages', 'page_sections', 'media', 'calculators',
            'calculator_inputs', 'calculator_rules', 'calculator_outputs', 'customers',
            'campaigns', 'campaign_sources', 'campaign_events', 'pipeline_stages', 'leads',
            'lead_events', 'lead_scores', 'lead_assignments', 'voucher_usages',
            'calculator_sessions', 'sales_teams', 'sales_team_members', 'sales_targets',
            'conversations', 'conversation_messages', 'ai_agent_logs', 'followups',
            'followup_steps', 'quotations', 'quotation_items', 'activities', 'tasks',
            'notes', 'notifications', 'workflows', 'workflow_nodes', 'workflow_runs',
            'workflow_logs', 'audit_logs',
        ];

        foreach ($tenantTables as $t) {
            DB::statement("CREATE INDEX IF NOT EXISTS idx_{$t}_tenant ON {$t}(tenant_id)");
            DB::statement("ALTER TABLE {$t} ENABLE ROW LEVEL SECURITY");
            DB::statement(
                "CREATE POLICY tenant_isolation ON {$t} USING (tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::uuid)"
            );
        }

        $updatedAtTables = [
            'tenants', 'users', 'product_categories', 'products', 'product_variants',
            'promotions', 'vouchers', 'landing_pages', 'page_sections', 'calculators',
            'customers', 'campaigns', 'leads', 'conversations', 'quotations', 'workflows',
        ];

        foreach ($updatedAtTables as $t) {
            DB::statement(
                "CREATE TRIGGER trg_set_updated_at BEFORE UPDATE ON {$t} FOR EACH ROW EXECUTE FUNCTION set_updated_at()"
            );
        }
    }

    public function down(): void
    {
        $tenantTables = [
            'users', 'product_categories', 'products', 'product_variants', 'product_images',
            'product_attributes', 'promotions', 'promotion_products', 'promotion_rules',
            'vouchers', 'landing_pages', 'page_sections', 'media', 'calculators',
            'calculator_inputs', 'calculator_rules', 'calculator_outputs', 'customers',
            'campaigns', 'campaign_sources', 'campaign_events', 'pipeline_stages', 'leads',
            'lead_events', 'lead_scores', 'lead_assignments', 'voucher_usages',
            'calculator_sessions', 'sales_teams', 'sales_team_members', 'sales_targets',
            'conversations', 'conversation_messages', 'ai_agent_logs', 'followups',
            'followup_steps', 'quotations', 'quotation_items', 'activities', 'tasks',
            'notes', 'notifications', 'workflows', 'workflow_nodes', 'workflow_runs',
            'workflow_logs', 'audit_logs',
        ];

        foreach ($tenantTables as $t) {
            DB::statement("DROP POLICY IF EXISTS tenant_isolation ON {$t}");
            DB::statement("ALTER TABLE {$t} DISABLE ROW LEVEL SECURITY");
        }

        $updatedAtTables = [
            'tenants', 'users', 'product_categories', 'products', 'product_variants',
            'promotions', 'vouchers', 'landing_pages', 'page_sections', 'calculators',
            'customers', 'campaigns', 'leads', 'conversations', 'quotations', 'workflows',
        ];

        foreach ($updatedAtTables as $t) {
            DB::statement("DROP TRIGGER IF EXISTS trg_set_updated_at ON {$t}");
        }
    }
};
