<?php

namespace Revun\Chat\Contracts;

/**
 * A portal that lets somebody chat without letting them chat to everybody.
 *
 * **Optional, and nothing changes for a portal that does not implement it.**
 * The three portals that share this package all put colleagues in one
 * directory, which is right for colleagues: a property manager looking for a
 * recruiter should find them. It is wrong the moment somebody outside the
 * company is let in — an institution reading a property manager's own pages
 * can be shown the people working on its property, and must not be shown the
 * rest of the company or anybody at all from another business.
 *
 * So `ParticipantDirectory` keeps answering "who are our people and may this
 * one chat", and a directory that also implements this one answers the
 * narrower question: **who may this particular person see.**
 *
 * ```php
 * final class Employees implements ParticipantDirectory, RestrictsChat { … }
 * ```
 *
 * **It narrows the screen, not Stream.** A browser holding a valid token can
 * ask Stream things the screen never offered, so a portal that means this to
 * hold has to take the two permissions away from that person's Stream role as
 * well — `search-user`, so a token cannot ask for the directory, and
 * `create-channel`, so it cannot start a conversation of its own. The
 * `chat:role` command builds exactly that role. Without it this interface is a
 * tidier screen and not a rule.
 */
interface RestrictsChat
{
    /**
     * The chat ids this person may see in the directory and write to.
     *
     * **Null is "everybody"**, which is what a colleague gets and what every
     * portal did before this existed. An empty array is a real answer too — a
     * person whose deals have nobody on them yet sees nobody, and that is not
     * the same as a mistake.
     *
     * The ids are `Revun\Chat\Identity` ids, so a portal works them out from
     * the email addresses of whoever it decides: in practice the handful of
     * colleagues actually working on this person's business, and the managers
     * above them.
     *
     * @return array<int, string>|null
     */
    public function visibleTo(ChatParticipant $person): ?array;

    /**
     * Whether this person gets conversations with one other person and nothing
     * else — no groups, no channels, no threads.
     *
     * False for a colleague, which is the whole of what the package did
     * before. True for somebody from outside: a group is a room whose
     * membership drifts, and a thread is a second place a conversation lives.
     * Neither is worth explaining to somebody who is here to ask their account
     * manager one question.
     */
    public function onlyDirectMessages(ChatParticipant $person): bool;
}
