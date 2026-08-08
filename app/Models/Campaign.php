<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Campaign extends Model
{
    use BelongsToTenant;
    use HasFactory;
    use HasUuids;

    protected $guarded = [];

    public function sources()
    {
        return $this->hasMany(CampaignSource::class);
    }
}
