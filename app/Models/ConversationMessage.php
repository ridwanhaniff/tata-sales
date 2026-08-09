<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ConversationMessage extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $guarded = [];

    public $timestamps = false;

    public const SENDER_CUSTOMER = 'customer';

    public const SENDER_AI = 'ai';

    public const SENDER_SALES = 'sales';

    public const SENDER_SYSTEM = 'system';

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function conversation()
    {
        return $this->belongsTo(Conversation::class);
    }

    public function sender()
    {
        return $this->belongsTo(User::class, 'sender_id');
    }
}
