<?php

namespace Revun\Chat\Calls;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Revun\Chat\Archive\ChatCall;

/**
 * Keeping a call's recording once Stream has finished making it.
 *
 * **Stream keeps it for a while and then does not.** A call worth recording is
 * worth keeping, and the archive rule is the same one the messages follow: the
 * vendor carries it, we keep it. So the file is copied into our own bucket as
 * soon as it is ready, under this portal's own prefix, beside the phone
 * recordings.
 *
 * **Named for the call it belongs to.** A key nobody can trace back to a
 * conversation is the kind of file that is still being paid for in three years
 * because nobody dares delete it.
 */
final class CallRecordings
{
    public function disk(): string
    {
        return (string) config('chat.calls.disk', 's3');
    }

    public function pathFor(ChatCall $call): string
    {
        $prefix = trim((string) config('chat.calls.prefix', 'royalyork/chat-calls'), '/');
        $when = $call->started_at ?? now();

        return sprintf('%s/%s/%s.mp4', $prefix, $when->format('Y/m'), str_replace(':', '-', $call->call_cid));
    }

    public function stored(ChatCall $call): bool
    {
        return filled($call->recording_path) && $call->recording_disk === $this->disk();
    }

    /**
     * Fetch what Stream made and write it where we can keep it.
     *
     * Returns the key, or null with the reason already on the row: "it did not
     * copy" and "there was nothing to copy" send somebody looking in different
     * places.
     */
    public function store(ChatCall $call): ?string
    {
        if ($this->stored($call)) {
            return $call->recording_path;
        }

        if (blank($call->recording_url)) {
            return null;
        }

        $response = rescue(fn () => Http::timeout(120)->get((string) $call->recording_url), null, false);

        if (! $response || $response->failed()) {
            Log::warning('A chat call recording could not be fetched from Stream.', [
                'call' => $call->call_cid,
                'status' => $response?->status(),
            ]);

            return null;
        }

        $path = $this->pathFor($call);

        if (! Storage::disk($this->disk())->put($path, $response->body())) {
            Log::warning('A chat call recording could not be written to the bucket.', [
                'call' => $call->call_cid,
                'disk' => $this->disk(),
            ]);

            return null;
        }

        $call->forceFill(['recording_path' => $path, 'recording_disk' => $this->disk()])->save();

        return $path;
    }
}
