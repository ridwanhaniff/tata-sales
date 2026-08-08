<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ProductAttribute extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $guarded = [];

    public $timestamps = false;

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
