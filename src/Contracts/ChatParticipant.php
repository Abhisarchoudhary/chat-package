<?php

namespace Revun\Chat\Contracts;

/**
 * What chat needs to know about a person, asked of the portal that employs
 * them.
 *
 * Each portal has its own users table, its own roles and its own idea of a
 * department, and none of that is chat's business. This is the small surface
 * where the three agree: a name to show, an address that identifies them
 * everywhere, a picture, where they work and whether they still do.
 *
 * `TalksInChat` implements all of it from what a Laravel user model usually
 * has; a portal overrides only what it keeps somewhere else.
 */
interface ChatParticipant
{
    /** The name colleagues would recognise. */
    public function chatName(): string;

    /** The address that is the same person in every portal. */
    public function chatEmail(): string;

    /**
     * A photo, or null for initials.
     *
     * **It has to be fetchable without this portal's session.** Three portals
     * read one directory, so a colleague's picture is requested by browsers
     * signed in somewhere else: a URL behind `auth` answers them with a
     * redirect to a sign-in page, the `<img>` fails, and everybody outside the
     * portal that employs that person sees initials for ever. Where the photo
     * is private -- and a photograph of a person usually is -- hand out a
     * signed URL rather than opening the route up.
     */
    public function chatImage(): ?string;

    /** Which business this person works for here: rypm, otr, crp. */
    public function chatOrganisation(): string;

    /** Their team, for grouping the directory. Null where the portal has none. */
    public function chatDepartment(): ?string;

    /**
     * What they may do in Stream itself: `user`, `moderator` or `admin`.
     *
     * Nearly everybody is a `user`. What somebody may *do* in this system is
     * decided by the portal's own permissions, not by this — see the package
     * README: a rule that lives in two places eventually disagrees with itself.
     */
    public function chatRole(): string;

    /** Somebody who has left keeps their history and loses their access. */
    public function chatActive(): bool;
}
