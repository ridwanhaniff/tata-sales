<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class CalculatorInput extends Model
{
    use BelongsToTenant;
    use HasUuids;

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'min_value' => 'decimal:2',
            'max_value' => 'decimal:2',
            'options' => 'array',
            'is_required' => 'boolean',
        ];
    }

    public function calculator()
    {
        return $this->belongsTo(Calculator::class);
    }
}
