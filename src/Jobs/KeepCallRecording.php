<?php

namespace Revun\Chat\Jobs;

use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Revun\Chat\Archive\ChatCall;
use Revun\Chat\Calls\CallRecordings;
use Revun\Chat\Calls\Transcription;

/**
 * A finished recording: copied to our bucket, then read.
 *
 * Queued because both halves are slow — a download and either an upload to a
 * paid endpoint or a handover to our own machine — and the webhook that started
 * it has a few seconds to answer.
 */
final class KeepCallRecording implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $backoff = 180;

    public int $timeout = 900;

    public function __construct(public int $callId)
    {
        $this->onQueue((string) config('chat.calls.queue', 'integrations'));
    }

    public function uniqueId(): string
    {
        return (string) $this->callId;
    }

    public function uniqueFor(): int
    {
        return 3600;
    }

    public function handle(CallRecordings $recordings, Transcription $transcription): void
    {
        $call = ChatCall::query()->find($this->callId);

        if ($call === null) {
            return;
        }

        if ($recordings->store($call) === null) {
            return;
        }

        $transcription->handle($call);
    }
}
