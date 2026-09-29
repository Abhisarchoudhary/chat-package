<?php

namespace Revun\Chat\Directory;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Revun\Chat\Contracts\ChatParticipant;
use Revun\Chat\Contracts\ParticipantDirectory;
use Revun\Chat\Identity;

/**
 * The simple answer, for a portal whose chat people are simply its users.
 *
 * It works where the user model implements `ChatParticipant` and everybody
 * active may chat. The moment a portal needs a condition — only recruiters,
 * not applicants, only staff with the permission — it writes its own directory
 * instead, which is a dozen lines and keeps the rule where the rule belongs.
 *
 * `all()` and `find()` need a model to query, so a portal that wants
 * `chat:sync` or the audit page sets `chat.model`. Without it this still mints
 * tokens for whoever is signed in.
 */
final class AuthenticatedUsers implements ParticipantDirectory
{
    public function current(): ?ChatParticipant
    {
        $user = Auth::user();

        return $user instanceof ChatParticipant && $user->chatActive() ? $user : null;
    }

    /**
     * Without a portal saying otherwise, anybody who may chat may do the
     * ordinary things — and nobody reads the archive or erases a message.
     */
    public function may(string $ability): bool
    {
        return in_array($ability, ['create-channel', 'call'], true) && $this->current() !== null;
    }

    public function all(): iterable
    {
        foreach ($this->query()?->cursor() ?? [] as $user) {
            if ($user instanceof ChatParticipant && $user->chatActive()) {
                yield $user;
            }
        }
    }

    public function find(string $streamId): ?ChatParticipant
    {
        if (! Identity::looksLikeOurs($streamId)) {
            return null;
        }

        foreach ($this->query()?->cursor() ?? [] as $user) {
            if ($user instanceof ChatParticipant && Identity::forEmail($user->chatEmail()) === $streamId) {
                return $user;
            }
        }

        return null;
    }

    /** @return Builder<Model>|null */
    private function query()
    {
        $model = config('chat.model');

        return $model === null ? null : $model::query();
    }
}
