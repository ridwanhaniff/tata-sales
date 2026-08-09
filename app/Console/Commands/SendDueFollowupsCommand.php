<?php

namespace App\Console\Commands;

use App\Jobs\SendFollowupJob;
use App\Models\Followup;
use Illuminate\Console\Command;

class SendDueFollowupsCommand extends Command
{
    protected $signature = 'followups:send';

    protected $description = 'Kirim follow-up yang jatuh tempo (dijalankan tiap menit oleh scheduler)';

    public function handle(): int
    {
        $due = Followup::query()
            ->where('status', 'pending')
            ->where('scheduled_at', '<=', now())
            ->pluck('id');

        foreach ($due as $id) {
            SendFollowupJob::dispatch($id);
        }

        $this->info("Scheduled {$due->count()} follow-up(s).");

        return self::SUCCESS;
    }
}
