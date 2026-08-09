<?php

namespace App\Jobs;

use App\Models\Followup;
use App\Services\FollowUp\FollowUpService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SendFollowupJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $followupId) {}

    public function handle(FollowUpService $service): void
    {
        $followup = Followup::query()
            ->withoutGlobalScope('tenant')
            ->find($this->followupId);

        if ($followup) {
            $service->send($followup);
        }
    }
}
