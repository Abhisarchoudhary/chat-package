<?php

namespace Revun\Chat;

use Revun\Chat\Contracts\ChatParticipant;

/**
 * Keeping Stream's copy of a person in step with the portal's.
 *
 * **Three portals write the same user, and none of them may clobber the
 * others.** A full upsert would do exactly that: Royal York writes the record,
 * the recruitment portal writes it again a minute later, and whichever went
 * last decides what the person's organisations are. So the fields a portal owns
 * outright are set, and the shared ones — which businesses this person belongs
 * to — are written one key at a time with a partial update.
 *
 * **What is synced is what chat needs**: a name, a picture, an address to find
 * them by, and where they work so the directory can group them. Nothing about
 * what they may do: that is the portal's permissions, and it stays there.
 */
final class StreamUsers
{
    public function __construct(private readonly Stream $stream) {}

    /**
     * Write this person into Stream, and record that this portal has them.
     *
     * Safe to call on every sign-in: it is one request, and it is how a changed
     * name or a new photograph reaches the people they are talking to.
     */
    public function sync(ChatParticipant $person): string
    {
        $id = Identity::forEmail($person->chatEmail());
        $organisation = $person->chatOrganisation();

        $this->stream->client()->upsertUser([
            'id' => $id,
            'name' => $person->chatName(),
            'image' => $person->chatImage(),
            'email' => mb_strtolower(trim($person->chatEmail())),
            'role' => $person->chatRole(),
            // The department is this portal's answer, so it is namespaced by it:
            // the same person can be in Leasing here and Recruiting there.
            'departments' => [$organisation => $person->chatDepartment()],
            'organisations' => [$organisation => true],
        ]);

        return $id;
    }

    /**
     * Somebody who has left.
     *
     * Deactivated, never deleted: deleting takes their messages with them, and
     * a conversation is not one person's to remove from everybody else's
     * history. Their access stops; what they said stays where it was said.
     */
    public function deactivate(ChatParticipant $person): void
    {
        $this->stream->client()->deactivateUser(
            Identity::forEmail($person->chatEmail()),
        );
    }

    public function reactivate(ChatParticipant $person): void
    {
        $this->stream->client()->reactivateUser(
            Identity::forEmail($person->chatEmail()),
        );
    }

    /**
     * Only the fields this portal owns, without touching the rest.
     *
     * @param  array<string, mixed>  $set
     */
    public function update(ChatParticipant $person, array $set): void
    {
        $this->stream->client()->partialUpdateUser([
            'id' => Identity::forEmail($person->chatEmail()),
            'set' => $set,
        ]);
    }
}
