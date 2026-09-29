<?php

namespace Revun\Chat;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Everything this package says to Stream, and the only place the secret is
 * used.
 *
 * **Written against the API rather than the vendor's PHP SDK**, which pins
 * Guzzle to 7 while Laravel 13 ships 8 — and the three portals are on three
 * different Laravel versions. A shared package that cannot be installed in one
 * of them is not a shared package. What we need is a dozen REST calls and a
 * JWT; that is cheaper to own than a dependency conflict is to carry.
 *
 * Authentication is two kinds of token, and the difference is the whole
 * security model: a **server token** (`{"server": true}`) can do anything and
 * never leaves this process; a **user token** says only who somebody is, and is
 * what the browser gets.
 */
final class Stream
{
    private const BASE = 'https://chat.stream-io-api.com';

    public function configured(): bool
    {
        return filled(config('chat.key')) && filled(config('chat.secret'));
    }

    /** The key the browser is allowed to know. */
    public function key(): string
    {
        return (string) config('chat.key');
    }

    /**
     * A token that says who somebody is, and nothing more.
     *
     * Stream reads `user_id` and grants that person's own access — what they
     * may do is the app's permission settings, not this claim.
     */
    public function userToken(string $userId, int $expiresInMinutes = 1440): string
    {
        return $this->jwt([
            'user_id' => $userId,
            'iat' => time(),
            'exp' => time() + ($expiresInMinutes * 60),
        ]);
    }

    /**
     * @param  array<string, mixed>  $body
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>|null
     */
    public function post(string $path, array $body = [], array $query = []): ?array
    {
        return $this->send('post', $path, $body, $query);
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>|null
     */
    public function patch(string $path, array $body = []): ?array
    {
        return $this->send('patch', $path, $body);
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>|null
     */
    public function get(string $path, array $query = []): ?array
    {
        return $this->send('get', $path, [], $query);
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>|null
     */
    public function delete(string $path, array $query = []): ?array
    {
        return $this->send('delete', $path, [], $query);
    }

    /**
     * Stream's own JSON-in-a-query-parameter convention, for the read
     * endpoints that take a filter.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>|null
     */
    public function query(string $path, array $payload): ?array
    {
        return $this->get($path, ['payload' => json_encode($payload)]);
    }

    /**
     * @param  array<string, mixed>  $body
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>|null
     */
    private function send(string $method, string $path, array $body = [], array $query = []): ?array
    {
        if (! $this->configured()) {
            throw new \RuntimeException('Chat is not configured: set STREAM_KEY and STREAM_SECRET.');
        }

        $url = self::BASE.'/'.ltrim($path, '/');
        $query = ['api_key' => $this->key()] + $query;

        /*
         * A GET's second argument is its query string and a POST's is its body,
         * and Laravel's client replaces the query it finds in the URL with
         * whatever that argument holds — which is how `api_key` went missing
         * from every read while every write worked.
         */
        $response = in_array($method, ['get', 'delete'], true)
            ? $this->request()->{$method}($url, $query)
            : $this->request()->{$method}($url.'?'.http_build_query($query), $body);

        if ($response->failed()) {
            /*
             * Logged with what it was, never with what it carried: a failed
             * message send would otherwise put the message in the log file.
             */
            Log::warning('Stream refused a request.', [
                'path' => $path,
                'status' => $response->status(),
                'code' => $response->json('code'),
                'message' => $response->json('message'),
            ]);

            return null;
        }

        return (array) $response->json();
    }

    private function request(): PendingRequest
    {
        return Http::withHeaders([
            'Authorization' => $this->jwt(['server' => true]),
            'Stream-Auth-Type' => 'jwt',
            'Content-Type' => 'application/json',
        ])->timeout(15)->retry(2, 200, throw: false);
    }

    /**
     * HS256, by hand.
     *
     * Fifteen lines against a JWT library that would be a fourth dependency to
     * agree on across three portals. The algorithm is fixed here on purpose:
     * reading it from the token is how "alg: none" happens to people.
     *
     * @param  array<string, mixed>  $claims
     */
    private function jwt(array $claims): string
    {
        $encode = static fn (array $part): string => rtrim(strtr(base64_encode(
            (string) json_encode($part, JSON_UNESCAPED_SLASHES),
        ), '+/', '-_'), '=');

        $head = $encode(['typ' => 'JWT', 'alg' => 'HS256']);
        $body = $encode($claims);

        $signature = hash_hmac('sha256', $head.'.'.$body, (string) config('chat.secret'), true);

        return $head.'.'.$body.'.'.rtrim(strtr(base64_encode($signature), '+/', '-_'), '=');
    }

    /**
     * Whether a webhook really came from Stream.
     *
     * Stream signs the raw body with the API secret; anybody can post to an
     * open URL, and an archive that believes them is an archive of whatever
     * they felt like writing.
     */
    public function signatureIsValid(string $body, string $signature): bool
    {
        if ($signature === '' || ! $this->configured()) {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $body, (string) config('chat.secret')), $signature);
    }
}
