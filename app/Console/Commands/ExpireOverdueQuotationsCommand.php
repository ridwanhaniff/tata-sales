<?php

namespace App\Console\Commands;

use App\Services\Quotation\QuotationService;
use Illuminate\Console\Command;

class ExpireOverdueQuotationsCommand extends Command
{
    protected $signature = 'quotations:expire {--tenant= : batasi ke satu tenant}';

    protected $description = 'Tandai quotation yang lewat valid_until sebagai expired (+ lead LOST)';

    public function handle(QuotationService $quotations): int
    {
        $expired = $quotations->expireOverdue(tenantId: $this->option('tenant'));

        $this->info("Expired quotations: {$expired}.");

        return self::SUCCESS;
    }
}
