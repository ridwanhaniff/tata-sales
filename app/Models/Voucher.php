<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Voucher extends Model
{
    use BelongsToTenant;
    use HasFactory;
    use HasUuids;

    protected $guarded = [];

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'discount_value' => 'decimal:2',
            'minimum_purchase' => 'decimal:2',
            'usage_limit' => 'integer',
            'per_customer_limit' => 'integer',
            'usage_count' => 'integer',
            'expires_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function promotion()
    {
        return $this->belongsTo(Promotion::class);
    }

    public function usages()
    {
        return $this->hasMany(VoucherUsage::class);
    }
}
