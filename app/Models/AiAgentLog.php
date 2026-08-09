<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AiAgentLog extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $guarded = [];

    public $timestamps = false;

    public const STATUS_SUCCESS = 'success';

    public const STATUS_FAILED = 'failed';

    public const STATUS_DENIED = 'denied';

    public const STATUS_HANDOFF = 'handoff';

    protected function casts(): array
    {
        return [
            'input' => 'array',
            'output' => 'array',
            'confidence' => 'float',
        ];
    }

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }
}
