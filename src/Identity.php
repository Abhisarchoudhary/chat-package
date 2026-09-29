<?php

namespace Revun\Chat;

/**
 * Who a person is in chat, decided the same way in every portal.
 *
 * **A person is their email address.** That is already the rule for signing in
 * across the three portals, and chat does not get to invent a second one: a
 * property manager who also recruits is one person with one conversation
 * history, not two accounts that happen to look alike.
 *
 * **The id is derived, because an email cannot be one.** Stream ids allow
 * `a-z A-Z 0-9 @ _ -` and an email has a dot in it. So the id is a hash of the
 * lowered, trimmed address: three codebases, with nothing shared between them
 * and no call to make, arrive at the same id for the same person. That is the
 * whole trick that makes one account across three portals work.
 *
 * **Thirty hex characters** is 120 bits. Two different addresses colliding is
 * not something that happens before the sun goes out, and a shorter id is
 * readable in a dashboard while a full hash is not.
 *
 * The address itself is stored on the user record as a field, so a person can
 * still be found by it — the hash hides nothing, it only makes a legal id.
 */
final class Identity
{
    public const PREFIX = 'u_';

    /** The Stream user id for an email address. */
    public static function forEmail(string $email): string
    {
        $email = mb_strtolower(trim($email));

        if ($email === '') {
            throw new \InvalidArgumentException('A chat identity needs an email address.');
        }

        return self::PREFIX.substr(hash('sha256', $email), 0, 30);
    }

    /**
     * Whether a string is one of ours.
     *
     * Used where an id arrives from outside — a webhook, a client request — and
     * has to be recognised before it is trusted.
     */
    public static function looksLikeOurs(string $id): bool
    {
        return (bool) preg_match('/^'.preg_quote(self::PREFIX, '/').'[0-9a-f]{30}$/', $id);
    }
}
