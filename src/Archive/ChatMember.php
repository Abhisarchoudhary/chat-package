<?php

namespace Revun\Chat\Archive;

use Illuminate\Database\Eloquent\Model;

/** Who was in a conversation, and when they joined or left it. */
class ChatMember extends Model
{
    protected $table = 'chat_members';

    protected $guarded = [];

    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'joined_at' => 'datetime',
            'left_at' => 'datetime',
        ];
    }

    public function getConnectionName(): ?string
    {
        return config('chat.archive.connection');
    }
}
