<?php

namespace Revun\Chat\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Revun\Chat\Archive\ChatCall;
use Revun\Chat\Calls\Transcription;

/**
 * Our transcription worker answering with the words of a chat call.
 *
 * **Its own address, deliberately.** A chat call and a phone call can both be
 * number seven; sharing the recruitment portal's result endpoint would write
 * one conversation's words onto another's. The worker answers wherever it was
 * told to, so this costs nothing but a route.
 *
 * Validate, write, reply — the worker gives this a few seconds and does not try
 * again, so the note read off the words is queued rather than written here.
 */
final class TranscriptionResultController
{
    public function __invoke(Request $request, Transcription $transcription): JsonResponse
    {
        $secret = (string) config('chat.calls.transcription.worker.secret');

        if (blank($secret) || ! hash_equals($secret, (string) $request->header('X-Webhook-Secret'))) {
            return response()->json(['message' => 'Unauthorised.'], 401);
        }

        $payload = $request->validate([
            'id' => ['required', 'integer'],
            'status' => ['required', 'string'],
            'text' => ['nullable', 'string'],
            'error' => ['nullable', 'string', 'max:2000'],
            'segments' => ['nullable', 'array'],
            'segments.*.start' => ['nullable', 'numeric'],
            'segments.*.end' => ['nullable', 'numeric'],
            'segments.*.speaker' => ['nullable', 'string', 'max:60'],
            'segments.*.text' => ['nullable', 'string'],
        ]);

        $call = ChatCall::query()->find((int) $payload['id']);

        if ($call === null) {
            Log::info('A transcript arrived for a chat call that is no longer here.', ['call' => $payload['id']]);

            return response()->json(['status' => 'ignored']);
        }

        if ($payload['status'] !== 'success') {
            $call->forceFill([
                'transcribed_at' => now(),
                'transcribe_error' => mb_substr((string) ($payload['error'] ?? 'The worker could not read this recording.'), 0, 250),
            ])->save();

            return response()->json(['status' => 'recorded']);
        }

        $segments = $this->segmentsOf((array) ($payload['segments'] ?? []));

        $text = $segments === []
            ? trim((string) ($payload['text'] ?? ''))
            : trim(implode(' ', array_column($segments, 'text')));

        if ($text === '') {
            $call->forceFill(['transcribed_at' => now(), 'transcribe_error' => 'The worker returned nothing to store.'])->save();

            return response()->json(['status' => 'recorded']);
        }

        $call->forceFill([
            'transcript' => $text,
            'transcript_segments' => $segments === [] ? null : $segments,
            'transcript_source' => 'worker',
            'transcribed_at' => now(),
            'transcribe_error' => null,
        ])->save();

        $transcription->summarise($call);

        return response()->json(['status' => 'stored', 'segments' => count($segments)]);
    }

    /**
     * The four fields worth keeping, in the order they were said. A segment
     * with no words is not a segment: they appear over silence, and on a
     * two-channel call that is half the conversation.
     *
     * @param  array<int, mixed>  $segments
     * @return list<array{start: float, end: float, speaker: string|null, text: string}>
     */
    private function segmentsOf(array $segments): array
    {
        $clean = [];

        foreach ($segments as $segment) {
            $segment = (array) $segment;
            $text = trim((string) ($segment['text'] ?? ''));

            if ($text === '') {
                continue;
            }

            $clean[] = [
                'start' => round((float) ($segment['start'] ?? 0), 2),
                'end' => round((float) ($segment['end'] ?? 0), 2),
                'speaker' => filled($segment['speaker'] ?? null) ? (string) $segment['speaker'] : null,
                'text' => $text,
            ];
        }

        usort($clean, fn (array $a, array $b) => $a['start'] <=> $b['start']);

        return $clean;
    }
}
