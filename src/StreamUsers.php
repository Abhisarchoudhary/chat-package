<?php

namespace Revun\Chat;

use Illuminate\Support\Facades\Cache;
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
    /** How many users Stream will answer in one request, whatever it is asked for. */
    private const PAGE = 100;

    public function __construct(private readonly Stream $stream) {}

    /**
     * Write this person into Stream, and record that this portal has them.
     *
     * Called on every sign-in and on every chat page, so it remembers what it
     * last wrote and says nothing when nothing has changed. It used to cost a
     * look-up and a write — two round trips to another continent — in front of
     * a page that could not draw until they came back, which is three seconds
     * of "Connecting" to tell Stream a name it already had.
     *
     * `$force` is for the nightly job, which is meant to write regardless.
     */
    public function sync(ChatParticipant $person, bool $force = false): string
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

        $mark = 'chat.synced.'.$id;
        $written = hash('sha256', (string) json_encode($set));
        $last = Cache::get($mark);

        if (! $force && $last === $written) {
            return $id;
        }

        /*
         * Stream will not partially update a user it has never seen, so the
         * first write has to create them. Having written them once, we know
         * they are there and can go straight to the update.
         */
        if ($last === null && ! $this->exists($id)) {
            $this->stream->post('users', ['users' => [$id => ['id' => $id, 'role' => $person->chatRole()] + $this->unflatten($set)]]);
        } else {
            $this->stream->patch('users', ['users' => [['id' => $id, 'set' => $set]]]);
        }

        Cache::forever($mark, $written);

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
     * **A hundred is Stream's page, not the company.** It will not answer more
     * than that in one request however many there are, so asking once and
     * returning the answer is a directory that quietly stops at the hundredth
     * name — everybody after it missing, and missing in a way nobody notices
     * until somebody cannot find a colleague. So the pages are walked until
     * Stream runs out of them or `$most` is reached, which is the only number
     * here a portal should ever need to think about.
     *
     * @return list<array<string, mixed>>
     */
    public function directory(int $most = 1000, ?string $search = null): array
    {
        $filter = ['id' => ['$ne' => 'system']];

        if (filled($search)) {
            $term = trim($search);

            /*
             * `$autocomplete` works on `name` and on nothing else here.
             * `email` is a custom field, and Stream indexes custom fields for
             * exact matches only — it answers anything else on one with a 400,
             * which this used to ask it for and which took the whole search
             * down with it rather than only the email half of it.
             *
             * So a whole address finds somebody and half of one does not, and
             * the id cannot stand in for it: it is a hash of the address on
             * purpose, so that three portals agree on who a person is.
             */
            $filter['$or'] = [
                ['name' => ['$autocomplete' => $term]],
                ['email' => ['$eq' => mb_strtolower($term)]],
            ];
        }

        $people = [];
        $after = null;
        $offset = 0;

        while (count($people) < max(1, $most)) {
            $asking = min(self::PAGE, max(1, $most) - count($people));

            /*
             * Stream pages with a cursor where it gives one and with an offset
             * where it does not, and it refuses both together — so the offset
             * is sent only until a cursor arrives to replace it.
             */
            $answer = $this->stream->query('users', array_filter([
                'filter_conditions' => $filter,
                'sort' => [['field' => 'name', 'direction' => 1]],
                'limit' => $asking,
                'next' => $after,
                'offset' => $after === null && $offset > 0 ? $offset : null,
            ], static fn ($value) => $value !== null));

            /*
             * Null is Stream refusing the question, not Stream having nobody to
             * answer it with — and the two must not look alike. A refused
             * filter that reads as an empty directory is exactly how a search
             * that found nobody at all went on looking like a search.
             */
            if ($answer === null) {
                if ($people === []) {
                    throw new \RuntimeException('Stream would not answer the directory.');
                }

                break;
            }

            $page = array_values((array) ($answer['users'] ?? []));

            /*
             * Keyed by id, because offset paging over a list somebody is being
             * added to can hand back a name that was already on the last page,
             * and twice in the directory is its own kind of wrong.
             */
            foreach ($page as $person) {
                $people[(string) ($person['id'] ?? count($people))] = $person;
            }

            $after = filled($answer['next'] ?? null) ? (string) $answer['next'] : null;
            $offset += count($page);

            /* A page shorter than the one asked for is the last page. */
            if (count($page) < $asking) {
                break;
            }
        }

        return array_values($people);
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
