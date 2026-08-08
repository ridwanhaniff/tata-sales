<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Calculator extends Model
{
    use BelongsToTenant;
    use HasFactory;
    use HasUuids;

    protected $guarded = [];

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    public function inputs()
    {
        return $this->hasMany(CalculatorInput::class);
    }

    public function rules()
    {
        return $this->hasMany(CalculatorRule::class);
    }

    public function outputs()
    {
        return $this->hasMany(CalculatorOutput::class);
    }

    public function sessions()
    {
        return $this->hasMany(CalculatorSession::class);
    }
}
