<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Lead extends Model
{
    use BelongsToTenant;
    use HasFactory;
    use HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'estimated_value' => 'decimal:2',
            'last_activity_at' => 'datetime',
        ];
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function variant()
    {
        return $this->belongsTo(ProductVariant::class);
    }

    public function campaign()
    {
        return $this->belongsTo(Campaign::class);
    }

    public function events()
    {
        return $this->hasMany(LeadEvent::class);
    }

    public function scores()
    {
        return $this->hasMany(LeadScore::class);
    }

    public function assignments()
    {
        return $this->hasMany(LeadAssignment::class);
    }

    public function notes()
    {
        return $this->hasMany(Note::class);
    }

    public function calculatorSessions()
    {
        return $this->hasMany(CalculatorSession::class);
    }

    public function assignedUser()
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }
}
