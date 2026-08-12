<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Delivery log webhook CRM keluar (§78, Sprint 13) — setiap event yang
 * dikirim ke konektor tercatat di sini (status pending/sent/failed,
 * http_status, attempt, error) supaya sinkronisasi observable.
 */
class CrmDelivery extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $guarded = [];

    public const STATUS_PENDING = 'pending';

    public const STATUS_SENT = 'sent';

    public const STATUS_FAILED = 'failed';

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'http_status' => 'integer',
            'attempt' => 'integer',
            'sent_at' => 'datetime',
        ];
    }

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    public function markSent(?int $httpStatus = null): void
    {
        $this->forceFill([
            'status' => self::STATUS_SENT,
            'http_status' => $httpStatus,
            'error' => null,
            'sent_at' => now(),
            'updated_at' => now(),
        ])->save();
    }

    public function markFailed(string $error, ?int $httpStatus = null): void
    {
        $this->forceFill([
            'status' => self::STATUS_FAILED,
            'http_status' => $httpStatus,
            'error' => $error,
            'updated_at' => now(),
        ])->save();
    }
}
