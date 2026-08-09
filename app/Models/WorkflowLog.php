<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class WorkflowLog extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $guarded = [];

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'input' => 'array',
            'output' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function run()
    {
        return $this->belongsTo(WorkflowRun::class, 'workflow_run_id');
    }

    public function node()
    {
        return $this->belongsTo(WorkflowNode::class, 'node_id');
    }
}
