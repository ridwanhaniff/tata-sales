<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class WebhookEvent extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $guarded = [];

    public $timestamps = false;

    public const STATUS_RECEIVED = 'received';

    public const STATUS_PROCESSED = 'processed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_DUPLICATE = 'duplicate';

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'processed_at' => 'datetime',
        ];
    }

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    public function markProcessed(): void
    {
        $this->forceFill([
            'status' => self::STATUS_PROCESSED,
            'processed_at' => now(),
        ])->save();
    }

    public function markFailed(): void
    {
        $this->forceFill([
            'status' => self::STATUS_FAILED,
            'processed_at' => now(),
        ])->save();
    }
}
