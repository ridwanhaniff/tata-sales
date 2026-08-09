<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Workflow extends Model
{
    use BelongsToTenant;
    use HasFactory;
    use HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'definition' => 'array',
        ];
    }

    public function nodes()
    {
        return $this->hasMany(WorkflowNode::class)->orderBy('sort_order');
    }

    public function runs()
    {
        return $this->hasMany(WorkflowRun::class);
    }
}
