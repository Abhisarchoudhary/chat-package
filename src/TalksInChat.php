<?php

namespace Revun\Chat;

/**
 * The usual answers, so a portal only writes down what is unusual about it.
 *
 * Put this on the portal's User model and it satisfies `ChatParticipant` from
 * what a Laravel user already has. Override a method where the portal keeps
 * that thing somewhere else — a department through a relation, a photo on a
 * private disk, a role that is not the Stream one.
 */
trait TalksInChat
{
    public function chatName(): string
    {
        return (string) ($this->name ?? $this->email);
    }

    public function chatEmail(): string
    {
        return (string) $this->email;
    }

    /**
     * Whatever the portal already uses for a photograph.
     *
     * Override this where that URL needs the portal's own session: the picture
     * is fetched by browsers signed into the other two portals, so one behind
     * `auth` shows them initials and nothing explains why. `ChatParticipant`
     * says more about it.
     */
    public function chatImage(): ?string
    {
        return method_exists($this, 'photoUrl') ? $this->photoUrl() : null;
    }

    public function chatOrganisation(): string
    {
        return (string) config('chat.organisation');
    }

    public function chatDepartment(): ?string
    {
        $department = $this->department ?? null;

        return $department === null ? null : (string) ($department->name ?? $department);
    }

    /**
     * Everybody is an ordinary Stream user.
     *
     * Not even a super admin is given Stream's `admin` role: that would be a
     * token in a browser that can read and write every conversation in the
     * company. A super admin reads the archive instead, from the server, with
     * the reading written down.
     */
    public function chatRole(): string
    {
        return 'user';
    }

    public function chatActive(): bool
    {
        return method_exists($this, 'isActive') ? (bool) $this->isActive() : true;
    }

    /** This person's id in Stream, the same in every portal. */
    public function chatId(): string
    {
        return Identity::forEmail($this->chatEmail());
    }
}
