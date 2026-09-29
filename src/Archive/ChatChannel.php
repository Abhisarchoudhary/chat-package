<?php

namespace Revun\Chat\Archive;

use Illuminate\Database\Eloquent\Model;

/**
 * A conversation, as the record remembers it: what it was called, who made it,
 * which portal it came from and when it was last used.
 */
class ChatChannel extends Model
{
    protected $table = 'chat_channels';

    protected $guarded = [];

    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
            'last_message_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }

    public function getConnectionName(): ?string
    {
        return config('chat.archive.connection');
    }
}
