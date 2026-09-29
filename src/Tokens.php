<?php

namespace Revun\Chat;

use Revun\Chat\Contracts\ChatParticipant;

/**
 * The token a browser is given to be somebody in chat.
 *
 * **It is minted for the signed-in person and nobody else.** The id is derived
 * from their email here, on the server — never taken from the request — so
 * asking for a token is not a way to become a colleague.
 *
 * **It expires.** A day, because a token is a key to every conversation the
 * holder is in, and a page that is open for a week should have to ask again.
 * The client asks for a new one when Stream tells it the old one is done, so
 * nobody sees this happen.
 *
 * The same token works for video: Stream's call product reads the same claim.
 */
final class Tokens
{
    public function __construct(private readonly Stream $stream) {}

    public function for(ChatParticipant $person, ?int $minutes = null): string
    {
        if (! $person->chatActive()) {
            throw new \RuntimeException('A deactivated account cannot be given a chat token.');
        }

        $minutes = $minutes ?? (int) config('chat.token_minutes', 1440);

        return $this->stream->client()->createToken(
            Identity::forEmail($person->chatEmail()),
            expiration: time() + ($minutes * 60),
        );
    }
}
