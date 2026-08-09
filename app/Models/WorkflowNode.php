<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class WorkflowNode extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $guarded = [];

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'config' => 'array',
            'sort_order' => 'integer',
        ];
    }

    public function workflow()
    {
        return $this->belongsTo(Workflow::class);
    }

    public function nextNode()
    {
        return $this->belongsTo(WorkflowNode::class, 'next_node_id');
    }
}
