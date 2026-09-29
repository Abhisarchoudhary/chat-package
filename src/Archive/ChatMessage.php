<?php

namespace Revun\Chat\Archive;

use Illuminate\Database\Eloquent\Model;

/**
 * One message, kept for good.
 *
 * `deleted_at` is somebody removing their own message from the conversation:
 * the words stay here, with who removed them. `purged_at` is a super admin
 * erasing it on purpose, which is the only thing that empties the text — two
 * different acts that a single "deleted" flag would have made
 * indistinguishable.
 *
 * @property string $stream_id
 * @property string $cid
 * @property string|null $user_id
 * @property string|null $text
 * @property array<int, mixed>|null $attachments
 */
class ChatMessage extends Model
{
    protected $table = 'chat_messages';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'attachments' => 'array',
            'sent_at' => 'datetime',
            'edited_at' => 'datetime',
            'deleted_at' => 'datetime',
            'purged_at' => 'datetime',
        ];
    }

    public function getConnectionName(): ?string
    {
        return config('chat.archive.connection');
    }
}
