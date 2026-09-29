<?php

namespace Revun\Chat\Archive;

use Illuminate\Database\Eloquent\Model;

/**
 * A call held in chat: who, when, how long, and — once Stream sends it — the
 * recording and the words read off it.
 */
class ChatCall extends Model
{
    protected $table = 'chat_calls';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'participants' => 'array',
            'transcript_segments' => 'array',
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'transcribed_at' => 'datetime',
            'transcribe_sent_at' => 'datetime',
            'duration_seconds' => 'integer',
        ];
    }

    public function getConnectionName(): ?string
    {
        return config('chat.archive.connection');
    }

    public function durationForHumans(): string
    {
        $seconds = max(0, $this->duration_seconds);

        return sprintf('%d:%02d', intdiv($seconds, 60), $seconds % 60);
    }
}
