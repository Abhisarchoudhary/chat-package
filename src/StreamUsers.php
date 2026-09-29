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

        /*
         * A partial update rather than an upsert, so three portals writing the
         * same person cannot undo each other: each sets the fields it owns and
         * adds itself to the shared ones, one key at a time.
         */
        $set = [
            'name' => $person->chatName(),
            'image' => $person->chatImage(),
            'email' => mb_strtolower(trim($person->chatEmail())),
            'organisations.'.$organisation => true,
            'departments.'.$organisation => $person->chatDepartment(),
        ];

        /*
         * Stream will not partially update a user it has never seen, so the
         * first write has to create them. Afterwards this is one request.
         */
        if (! $this->exists($id)) {
            $this->stream->post('users', ['users' => [$id => ['id' => $id, 'role' => $person->chatRole()] + $this->unflatten($set)]]);

            return $id;
        }

        $this->stream->patch('users', ['users' => [['id' => $id, 'set' => $set]]]);

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
        $this->stream->post('users/'.Identity::forEmail($person->chatEmail()).'/deactivate');
    }

    public function reactivate(ChatParticipant $person): void
    {
        $this->stream->post('users/'.Identity::forEmail($person->chatEmail()).'/reactivate');
    }

    /** Whether Stream already holds this person. */
    public function exists(string $id): bool
    {
        $answer = $this->stream->query('users', ['filter_conditions' => ['id' => $id], 'limit' => 1]);

        return ($answer['users'] ?? []) !== [];
    }

    /**
     * Everybody this person may start a conversation with.
     *
     * The directory is Stream's, not a portal's: a Royal York manager looking
     * for a recruiter cannot query the recruitment portal's database, and does
     * not need to — every portal writes its people here.
     *
     * @return list<array<string, mixed>>
     */
    public function directory(int $limit = 100, ?string $search = null, ?string $after = null): array
    {
        $filter = ['id' => ['$ne' => 'system']];

        if (filled($search)) {
            $filter['$or'] = [
                ['name' => ['$autocomplete' => $search]],
                ['email' => ['$autocomplete' => $search]],
            ];
        }

        $answer = $this->stream->query('users', array_filter([
            'filter_conditions' => $filter,
            'sort' => [['field' => 'name', 'direction' => 1]],
            'limit' => max(1, min($limit, 100)),
            'next' => $after,
        ]));

        return array_values((array) ($answer['users'] ?? []));
    }

    /**
     * Stream takes nested fields as dotted paths when setting and as a tree
     * when creating; this is the one place that difference is handled.
     *
     * @param  array<string, mixed>  $set
     * @return array<string, mixed>
     */
    private function unflatten(array $set): array
    {
        $tree = [];

        foreach ($set as $key => $value) {
            $node = &$tree;

            foreach (explode('.', $key) as $segment) {
                $node[$segment] ??= [];
                $node = &$node[$segment];
            }

            $node = $value;
            unset($node);
        }

        return $tree;
    }

    /**
     * Only the fields this portal owns, without touching the rest.
     *
     * @param  array<string, mixed>  $set
     */
    public function update(ChatParticipant $person, array $set): void
    {
        $this->stream->patch('users', ['users' => [[
            'id' => Identity::forEmail($person->chatEmail()),
            'set' => $set,
        ]]]);
    }
}
