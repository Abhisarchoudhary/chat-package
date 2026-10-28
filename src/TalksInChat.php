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
     * What they do, where the portal happens to keep it under that name.
     *
     * **Null is a real answer**, not a gap to be filled by guessing: a portal
     * that does not record job titles shows the department alone, exactly as
     * every portal did before this was asked for. Overriding this is one
     * method in a model, and `job_title` is read here only because three of
     * the four already call the column that.
     */
    public function chatTitle(): ?string
    {
        $title = $this->job_title ?? null;

        return filled($title) ? (string) $title : null;
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

    /**
     * A colleague, and so in the directory.
     *
     * Overridden on the model for somebody who is in chat without working
     * here -- `ChatParticipant` says what that costs and what it does not.
     */
    public function chatListed(): bool
    {
        return true;
    }

    /** This person's id in Stream, the same in every portal. */
    public function chatId(): string
    {
        return Identity::forEmail($this->chatEmail());
    }
}
