<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class WorkflowRun extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $guarded = [];

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function workflow()
    {
        return $this->belongsTo(Workflow::class);
    }

    public function lead()
    {
        return $this->belongsTo(Lead::class);
    }

    public function logs()
    {
        return $this->hasMany(WorkflowLog::class, 'workflow_run_id');
    }

    public function currentNode()
    {
        return $this->belongsTo(WorkflowNode::class, 'current_node_id');
    }
}
