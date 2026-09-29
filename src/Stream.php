<?php

namespace Revun\Chat;

use GetStream\StreamChat\Client;

/**
 * The one place a Stream client is made.
 *
 * It holds the API secret, which is why nothing outside this package talks to
 * the vendor directly: a token minted anywhere else would be a second answer to
 * "who is this person", and the secret in a second file is a secret in two
 * files.
 *
 * `configured()` is asked before anything is attempted. A portal with no keys
 * is a portal whose chat page says so, rather than one that throws.
 */
final class Stream
{
    private ?Client $client = null;

    public function configured(): bool
    {
        return filled(config('chat.key')) && filled(config('chat.secret'));
    }

    public function client(): Client
    {
        if (! $this->configured()) {
            throw new \RuntimeException('Chat is not configured: set STREAM_KEY and STREAM_SECRET.');
        }

        return $this->client ??= new Client(
            (string) config('chat.key'),
            (string) config('chat.secret'),
        );
    }

    /** The key the browser is allowed to know. */
    public function key(): string
    {
        return (string) config('chat.key');
    }
}
