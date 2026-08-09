<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Conversation extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $guarded = [];

    public const STATUS_AI_ACTIVE = 'AI_ACTIVE';

    public const STATUS_WAITING_HUMAN = 'WAITING_HUMAN';

    public const STATUS_HUMAN_ACTIVE = 'HUMAN_ACTIVE';

    public const STATUS_AI_RESUMED = 'AI_RESUMED';

    public const STATUS_CLOSED = 'CLOSED';

    protected function casts(): array
    {
        return [
            'context' => 'array',
        ];
    }

    public function lead()
    {
        return $this->belongsTo(Lead::class);
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function assignedUser()
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function messages()
    {
        return $this->hasMany(ConversationMessage::class, 'conversation_id');
    }

    public function lastMessage()
    {
        return $this->hasOne(ConversationMessage::class, 'conversation_id')
            ->orderByDesc('created_at')
            ->orderByDesc('id');
    }

    public function markHumanActive(?string $userId): void
    {
        $this->forceFill([
            'status' => self::STATUS_HUMAN_ACTIVE,
            'assigned_to' => $userId,
            'updated_at' => now(),
        ])->save();
    }
}
