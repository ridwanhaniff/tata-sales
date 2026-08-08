<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class VoucherUsage extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $guarded = [];

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'used_at' => 'datetime',
        ];
    }

    public function voucher()
    {
        return $this->belongsTo(Voucher::class);
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }
}
