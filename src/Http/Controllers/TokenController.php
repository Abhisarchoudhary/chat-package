<?php

namespace Revun\Chat\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Revun\Chat\Contracts\ChatParticipant;
use Revun\Chat\Stream;
use Revun\Chat\StreamUsers;
use Revun\Chat\Tokens;

/**
 * What the chat page asks for before it can connect.
 *
 * The person is taken from the session, never from the request: the only
 * account this hands out a key to is the one already signed in. The reply also
 * carries the API key and the user's own id, so the page has everything it
 * needs in one round trip instead of three.
 *
 * The sync happens here, on the way past, because this is the moment we know
 * somebody is about to use chat — and a name or photograph that changed
 * yesterday should be right before their colleagues see it, not at the next
 * nightly job.
 */
final class TokenController
{
    public function __invoke(Request $request, Stream $stream, Tokens $tokens, StreamUsers $users): JsonResponse
    {
        $person = $request->user();

        if (! $person instanceof ChatParticipant) {
            return response()->json(['message' => 'This account cannot use chat.'], 403);
        }

        if (! $stream->configured()) {
            return response()->json(['message' => 'Chat is not configured on this portal.'], 503);
        }

        $id = $users->sync($person);

        return response()->json([
            'api_key' => $stream->key(),
            'user_id' => $id,
            'token' => $tokens->for($person),
            'user' => [
                'id' => $id,
                'name' => $person->chatName(),
                'image' => $person->chatImage(),
            ],
        ]);
    }
}
