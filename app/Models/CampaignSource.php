<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class CampaignSource extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $guarded = [];

    public $timestamps = false;

    public function campaign()
    {
        return $this->belongsTo(Campaign::class);
    }

    public function landingPage()
    {
        return $this->belongsTo(LandingPage::class);
    }
}
