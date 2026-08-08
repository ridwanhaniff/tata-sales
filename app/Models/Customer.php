<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Customer extends Model
{
    use BelongsToTenant;
    use HasFactory;
    use HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'tags' => 'array',
            'consent_marketing' => 'boolean',
        ];
    }

    public function leads()
    {
        return $this->hasMany(Lead::class);
    }

    public function calculatorSessions()
    {
        return $this->hasMany(CalculatorSession::class);
    }

    public function voucherUsages()
    {
        return $this->hasMany(VoucherUsage::class);
    }
}
