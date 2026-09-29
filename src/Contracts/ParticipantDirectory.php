<?php

namespace Revun\Chat\Contracts;

/**
 * Who this portal's chat people are.
 *
 * **The package must not know what the table is called.** Royal York keeps
 * employees in `users`; the recruitment portal has recruiters; MSR has whatever
 * MSR has. And it is never only a table — it is a table with conditions on it:
 * active, not a client, not an applicant, in a role that was given chat. A
 * config line holding a table name would be wrong the first time one of those
 * conditions appears.
 *
 * So each portal writes a small class that answers three questions, and the
 * package asks rather than assumes. It is the same shape as the rest of this
 * system: the portal owns who its people are, and chat owns what happens once
 * they are here.
 *
 * ```php
 * // config/chat.php
 * 'directory' => App\Modules\Chat\Employees::class,
 * ```
 *
 * A portal whose user model already implements `ChatParticipant` and whose
 * answer is simply "the signed-in person" can leave it unset and get
 * `Revun\Chat\Directory\AuthenticatedUsers`.
 */
interface ParticipantDirectory
{
    /**
     * The person using the portal right now, or null where they may not chat.
     *
     * Null is the answer for a signed-out visitor, a deactivated account, and
     * anybody whose role does not have chat — the token endpoint asks this and
     * nothing else, so "may this person chat" is decided in one place.
     */
    public function current(): ?ChatParticipant;

    /**
     * Everybody in this portal who may chat.
     *
     * Used by `chat:sync` to fill the directory in the first place and to keep
     * names and photographs current afterwards. Return them lazily — a portal
     * with ten thousand people should not build ten thousand objects to sync a
     * hundred.
     *
     * @return iterable<ChatParticipant>
     */
    public function all(): iterable;

    /**
     * Whether the person using the portal may do one of chat's guarded things.
     *
     * The abilities are `create-channel`, `manage-channels`, `call`,
     * `read-archive`, `play-recording` and `purge`. The package asks in these
     * words and the portal answers in its own permissions, which is what lets a
     * super admin change any of them on the roles page instead of in a vendor
     * dashboard.
     */
    public function may(string $ability): bool;

    /**
     * The person behind a Stream id, for the archive and the audit page.
     *
     * A conversation archived today is read in two years, by which time the
     * person may have left; answer with what the portal still has rather than
     * nothing, and null only where this id was never one of ours.
     */
    public function find(string $streamId): ?ChatParticipant;
}
