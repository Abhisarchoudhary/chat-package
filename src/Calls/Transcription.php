<?php

namespace Revun\Chat\Calls;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Revun\Chat\Archive\ChatCall;

/**
 * The words of a call held in chat, read the same way the phone calls are.
 *
 * **Two drivers, and the difference is where the audio goes.** `worker` hands
 * our own machine a bucket key — it fetches the file itself, splits the call
 * into its channels so the speakers are read rather than guessed at, and
 * answers later by webhook. `openai` uploads the audio to the paid endpoint and
 * answers in the same request, with no speakers and a size ceiling.
 *
 * **Its own return address.** A chat call and a phone call can both be number
 * seven; one webhook answering for both would write the wrong words on the
 * wrong conversation, so the worker is told to answer here.
 *
 * **Every refusal is written down.** A recording too large, a worker that would
 * not take it, an endpoint having an afternoon — each leaves a call with no
 * words, and a blank transcript tells them apart for nobody.
 */
final class Transcription
{
    public function __construct(private readonly CallRecordings $recordings) {}

    public function enabled(): bool
    {
        return (bool) config('chat.calls.transcription.enabled');
    }

    public function driver(): string
    {
        return (string) config('chat.calls.transcription.driver', 'worker');
    }

    /** Whether this call is worth asking about at all. */
    public function wants(ChatCall $call, bool $again = false): bool
    {
        if (! $this->enabled() || ! $this->recordings->stored($call)) {
            return false;
        }

        if ($again) {
            return true;
        }

        if ($call->transcribed_at !== null || filled($call->transcribe_error)) {
            return false;
        }

        /*
         * Handed over and still being read. The worker answers minutes later,
         * and without this a sweep would send the same audio again on every
         * pass — bounded, because a handover can be lost.
         */
        if ($call->transcribe_sent_at !== null) {
            $hours = max(1, (int) config('chat.calls.transcription.worker.retry_after_hours', 6));

            return $call->transcribe_sent_at->lt(now()->subHours($hours));
        }

        return true;
    }

    public function handle(ChatCall $call, bool $again = false): bool
    {
        if (! $this->wants($call, $again)) {
            return false;
        }

        return $this->driver() === 'worker'
            ? $this->handOver($call)
            : $this->readHere($call);
    }

    /**
     * Our own machine: a key and a return address, never the audio.
     *
     * The stamp is written only once the worker has accepted — a refused
     * handover that looked like one in progress would never be asked about
     * again.
     */
    private function handOver(ChatCall $call): bool
    {
        $url = (string) config('chat.calls.transcription.worker.url');
        $key = (string) config('chat.calls.transcription.worker.key');

        if ($url === '' || $key === '') {
            return $this->refuse($call, 'The transcription worker is not configured.');
        }

        $response = rescue(fn () => Http::withHeaders(['X-API-Key' => $key])
            ->timeout((int) config('chat.calls.transcription.worker.timeout', 20))
            ->asJson()
            ->post(rtrim($url, '/'), [
                'id' => $call->id,
                'type' => 'chat-call',
                'file_name' => $call->recording_path,
                'webhook_url' => route('chat.transcript'),
            ]), null, false);

        if (! $response || ! $response->successful()) {
            Log::warning('A chat call recording could not be handed over.', [
                'call' => $call->call_cid,
                'status' => $response?->status(),
            ]);

            return false;
        }

        $call->forceFill(['transcribe_sent_at' => now(), 'transcribe_error' => null])->save();

        return true;
    }

    /**
     * The paid endpoint: one request, an answer now, a size ceiling.
     *
     * The audio is read from the bucket into a temporary file and deleted in
     * the same breath — the copy we keep is the one in the bucket.
     */
    private function readHere(ChatCall $call): bool
    {
        $key = (string) config('services.openai.key');

        if ($key === '') {
            return $this->refuse($call, 'No OpenAI key is set, so there is nothing to read the call with.');
        }

        $audio = rescue(fn () => Storage::disk($this->recordings->disk())->get((string) $call->recording_path), null, false);

        if ($audio === null) {
            return $this->refuse($call, 'The recording could not be read back from the bucket.');
        }

        $megabytes = strlen($audio) / 1_048_576;
        $ceiling = (int) config('chat.calls.transcription.max_megabytes', 24);

        if ($megabytes > $ceiling) {
            return $this->refuse($call, sprintf(
                'The recording is %.1f MB, over the %d MB the transcription endpoint accepts.',
                $megabytes,
                $ceiling,
            ));
        }

        $path = tempnam(sys_get_temp_dir(), 'chat-call-').'.mp4';
        file_put_contents($path, $audio);

        try {
            $model = (string) config('services.openai.transcription_model', 'whisper-1');
            $handle = fopen($path, 'r');

            $response = Http::withToken($key)
                ->timeout(600)
                ->attach('file', $handle, basename($path))
                ->asMultipart()
                ->post('https://api.openai.com/v1/audio/transcriptions', [
                    'model' => $model,
                    'response_format' => 'json',
                ]);
        } finally {
            @unlink($path);
        }

        if ($response->failed() || blank($response->json('text'))) {
            return $this->refuse($call, 'The transcription service did not return any words.');
        }

        $call->forceFill([
            'transcript' => trim((string) $response->json('text')),
            'transcript_source' => 'openai/'.(string) config('services.openai.transcription_model', 'whisper-1'),
            'transcribed_at' => now(),
            'transcribe_error' => null,
        ])->save();

        $this->summarise($call);

        return true;
    }

    /**
     * Three sentences read off the transcript.
     *
     * It is allowed to say less than the call did and never more: a summary
     * that invents a commitment is worse than no summary at all.
     */
    public function summarise(ChatCall $call): void
    {
        $key = (string) config('services.openai.key');

        if ($key === '' || blank($call->transcript) || ! (bool) config('chat.calls.transcription.summarise', true)) {
            return;
        }

        $response = rescue(fn () => Http::withToken($key)->timeout(60)->post('https://api.openai.com/v1/chat/completions', [
            'model' => (string) config('services.openai.summary_model', 'gpt-4o-mini'),
            'temperature' => 0,
            'response_format' => ['type' => 'json_object'],
            'messages' => [
                ['role' => 'system', 'content' => implode("\n", [
                    'You write a short note from the transcript of a call between colleagues at a property management company.',
                    'Answer as JSON: {"summary": "..."}.',
                    'Three sentences at most: what it was about, what was agreed, and anything somebody now owes.',
                    'Use only what the transcript says. If something was not discussed, leave it out — never guess at a price, a date or a commitment.',
                ])],
                ['role' => 'user', 'content' => mb_substr((string) $call->transcript, 0, 12000)],
            ],
        ]), null, false);

        $answer = json_decode((string) $response?->json('choices.0.message.content'), true);
        $summary = trim((string) ($answer['summary'] ?? ''));

        if ($summary !== '') {
            $call->forceFill(['summary' => $summary])->save();
        }
    }

    private function refuse(ChatCall $call, string $reason): bool
    {
        Log::info('A chat call was not transcribed: '.$reason, ['call' => $call->call_cid]);

        $call->forceFill(['transcribe_error' => mb_substr($reason, 0, 250)])->save();

        return false;
    }
}
