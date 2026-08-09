<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class SalesTeam extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $guarded = [];

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    public function members()
    {
        return $this->belongsToMany(User::class, 'sales_team_members')
            ->withPivot('id', 'tenant_id');
    }

    public function productCategory()
    {
        return $this->belongsTo(ProductCategory::class);
    }

    public function targets()
    {
        return $this->hasMany(SalesTarget::class);
    }
}
