<?php

namespace Revun\Chat\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Revun\Chat\Contracts\DescribesPeople;
use Revun\Chat\Contracts\ParticipantDirectory;
use Revun\Chat\Contracts\RestrictsChat;
use Revun\Chat\Identity;
use Revun\Chat\Stream;
use Revun\Chat\StreamUsers;

/**
 * The few things the browser is not allowed to do for itself.
 *
 * Stream's own permissions are set so an ordinary user's token cannot create a
 * channel or add somebody to one. Those requests come here instead, where the
 * portal's permissions decide — which is the only way a super admin can change
 * the rule on the roles page and have it mean anything.
 *
 * Everything else — sending, editing, reacting, uploading, reading — the
 * browser does directly against Stream with its own token. Proxying that would
 * be a second copy of a chat system and a slower one.
 */
final class ChatController
{
    /**
     * Everybody this person could start a conversation with, grouped the way a
     * directory is read: their own organisation first, then the rest.
     */
    public function directory(Request $request, ParticipantDirectory $directory, StreamUsers $users): JsonResponse
    {
        if ($directory->current() === null) {
            return response()->json(['message' => 'This account cannot use chat.'], 403);
        }

        try {
            $people = $users->directory(
                most: (int) config('chat.directory_limit', 1000),
                search: $request->string('q')->toString() ?: null,
            );
        } catch (\RuntimeException) {
            /*
             * Said rather than swallowed. An empty list here would tell
             * somebody that nobody in the company matches what they typed,
             * which is a different and much more convincing sort of wrong.
             */
            return response()->json(['message' => 'The directory is not answering just now.'], 503);
        }

        /*
         * Narrowed where the portal narrows it (Contracts\RestrictsChat).
         * Stream answers with everybody it holds, which is right for colleagues
         * and wrong for somebody from outside the company — and the portal is
         * the only thing that knows which this is.
         */
        $allowed = $directory instanceof RestrictsChat
            ? $directory->visibleTo($directory->current())
            : null;

        if ($allowed !== null) {
            $allowed = array_flip($allowed);
            $people = array_values(array_filter($people, fn ($person) => isset($allowed[$person['id'] ?? ''])));
        }

        $here = (string) config('chat.organisation');
        $labels = (array) config('chat.organisations', []);
        $groups = [];

        foreach ($people as $person) {
            $organisations = array_keys(array_filter((array) ($person['organisations'] ?? [])));
            $organisation = in_array($here, $organisations, true) ? $here : ($organisations[0] ?? 'other');

            $groups[$organisation]['label'] ??= $labels[$organisation] ?? ucfirst($organisation);
            $groups[$organisation]['people'][] = [
                'id' => $person['id'],
                'name' => $person['name'] ?? $person['id'],
                'image' => $person['image'] ?? null,
                'email' => $person['email'] ?? null,
                'department' => ($person['departments'][$organisation] ?? null),
                'title' => ($person['titles'][$organisation] ?? null),
                'online' => (bool) ($person['online'] ?? false),
                'organisation' => $organisation,
            ];
        }

        // This portal's own people first: that is who somebody is usually after.
        uksort($groups, fn (string $a, string $b) => $a === $here ? -1 : ($b === $here ? 1 : strcmp($a, $b)));

        return response()->json(['groups' => $groups]);
    }

    /**
     * One person, for the card that opens when their name is pressed.
     *
     * **It used to open the conversation instead**, which is the right thing
     * to do second and the wrong thing to do first: a directory is opened to
     * find out who somebody is — what they do, whether they are even at work
     * today — and answering that with a message box forces a guess before the
     * question is answered, in front of a colleague who sees you typing.
     *
     * Only this portal's own people have a card. `find()` answers null for
     * anybody else, and the browser then draws what the directory row already
     * told it; nothing is invented and nothing is asked of another portal.
     */
    public function person(Request $request, ParticipantDirectory $directory): JsonResponse
    {
        $me = $directory->current();

        if ($me === null) {
            return response()->json(['message' => 'This account cannot use chat.'], 403);
        }

        $id = $request->string('user')->toString();

        if (! Identity::looksLikeOurs($id)) {
            return response()->json(['message' => 'That is not somebody here.'], 422);
        }

        /*
         * The same rule as opening a conversation: somebody who may not be
         * written to may not be read about either. A card is less than a
         * message and still more than the portal said this person could see.
         */
        if ($directory instanceof RestrictsChat) {
            $allowed = $directory->visibleTo($me);

            if ($allowed !== null && ! in_array($id, $allowed, true)) {
                return response()->json(['message' => 'You cannot see this person.'], 403);
            }
        }

        $person = $directory->find($id);

        return response()->json([
            /* Said plainly, because the browser draws two different things:
               a card for a colleague here, and the directory row it already
               has for somebody in another portal. */
            'ours' => $person !== null,
            'card' => $person !== null && $directory instanceof DescribesPeople
                ? $directory->card($person)
                : null,
        ]);
    }

    /**
     * A channel or a group, created by somebody the portal says may.
     *
     * A group of two is still a group here rather than a direct message: a DM
     * is made by the browser as a distinct channel, so writing to the same
     * person twice lands in the same conversation instead of a second one.
     */
    public function createChannel(Request $request, ParticipantDirectory $directory, Stream $stream): JsonResponse
    {
        $person = $directory->current();

        if ($person === null || ! $directory->may('create-channel')) {
            return response()->json(['message' => 'You cannot create a channel.'], 403);
        }

        /*
         * Said here as well as left off the screen. Somebody who only gets
         * direct messages has no button for this, and a route that answered it
         * anyway would be a group made by whoever thought to post to it.
         */
        if ($directory instanceof RestrictsChat && $directory->onlyDirectMessages($person)) {
            return response()->json(['message' => 'You can only message people directly.'], 403);
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'type' => ['required', 'in:team,messaging'],
            'members' => ['array', 'max:200'],
            'members.*' => ['string', 'max:64'],
            'description' => ['nullable', 'string', 'max:500'],
            'private' => ['boolean'],
        ]);

        $me = Identity::forEmail($person->chatEmail());
        $members = array_values(array_unique(array_merge(
            array_filter((array) ($data['members'] ?? []), Identity::looksLikeOurs(...)),
            [$me],
        )));

        $id = 'c_'.substr(hash('sha256', $data['name'].microtime()), 0, 24);

        $answer = $stream->post("channels/{$data['type']}/{$id}/query", [
            'data' => [
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'members' => $members,
                'created_by_id' => $me,
                // Where it came from, for the archive and for a directory that
                // can say "this is a Royal York channel".
                'portal' => (string) config('chat.organisation'),
                'private' => (bool) ($data['private'] ?? false),
            ],
            'state' => true,
        ]);

        if ($answer === null) {
            return response()->json(['message' => 'The channel could not be created.'], 502);
        }

        return response()->json([
            'cid' => $answer['channel']['cid'] ?? "{$data['type']}:{$id}",
            'type' => $data['type'],
            'id' => $id,
        ]);
    }

    /**
     * A conversation with one other person, opened by the server.
     *
     * **The browser used to make this itself**, and for a colleague it still
     * could: `create-channel` is an ordinary user's permission and a distinct
     * channel is the same conversation however many times it is started. But a
     * browser that can start a conversation can start one with anybody whose id
     * it can work out — and the id is a hash of an email address, which is not
     * a secret. For somebody outside the company that is the whole rule gone.
     *
     * So it is made here, where the portal says who may be written to. A
     * portal that narrows nothing gets exactly what it had, one hop later.
     */
    public function direct(Request $request, ParticipantDirectory $directory, Stream $stream): JsonResponse
    {
        $person = $directory->current();

        if ($person === null) {
            return response()->json(['message' => 'This account cannot use chat.'], 403);
        }

        $data = $request->validate([
            'user' => ['required', 'string', 'max:64'],
        ]);

        $them = $data['user'];

        if (! Identity::looksLikeOurs($them)) {
            return response()->json(['message' => 'That is not somebody here.'], 422);
        }

        $me = Identity::forEmail($person->chatEmail());

        if ($them === $me) {
            return response()->json(['message' => 'That is you.'], 422);
        }

        /*
         * The same answer the directory was drawn from, asked again — a list
         * that was right when the page loaded is not a permission, and this is
         * the request that actually opens the conversation.
         */
        if ($directory instanceof RestrictsChat) {
            $allowed = $directory->visibleTo($person);

            if ($allowed !== null && ! in_array($them, $allowed, true)) {
                return response()->json(['message' => 'You cannot message this person.'], 403);
            }
        }

        /*
         * No id of our own: Stream makes a **distinct** channel from the pair
         * of members, which is what makes writing to the same person twice land
         * in the same conversation instead of a second one beside it.
         */
        $answer = $stream->post('channels/messaging/query', [
            'data' => [
                'members' => [$me, $them],
                'created_by_id' => $me,
                'portal' => (string) config('chat.organisation'),
            ],
            'state' => true,
        ]);

        $channel = $answer['channel'] ?? null;

        if ($channel === null || ! isset($channel['id'])) {
            return response()->json(['message' => 'The conversation could not be opened.'], 502);
        }

        return response()->json([
            'cid' => $channel['cid'] ?? 'messaging:'.$channel['id'],
            'type' => $channel['type'] ?? 'messaging',
            'id' => $channel['id'],
        ]);
    }

    /** Adding or removing people, which is the same permission as making one. */
    public function members(Request $request, ParticipantDirectory $directory, Stream $stream): JsonResponse
    {
        $person = $directory->current();

        if ($person === null) {
            return response()->json(['message' => 'This account cannot use chat.'], 403);
        }

        $data = $request->validate([
            'type' => ['required', 'in:team,messaging'],
            'id' => ['required', 'string', 'max:64'],
            'add' => ['array'],
            'add.*' => ['string', 'max:64'],
            'remove' => ['array'],
            'remove.*' => ['string', 'max:64'],
        ]);

        $me = Identity::forEmail($person->chatEmail());
        $leavingAlone = ($data['remove'] ?? []) === [$me] && ($data['add'] ?? []) === [];

        /*
         * Leaving a channel is nobody's business but your own; adding somebody
         * else, or removing them, is the permission.
         */
        if (! $leavingAlone && ! $directory->may('create-channel') && ! $directory->may('manage-channels')) {
            return response()->json(['message' => 'You cannot change who is in this channel.'], 403);
        }

        $answer = $stream->post("channels/{$data['type']}/{$data['id']}", array_filter([
            'add_members' => array_values(array_filter((array) ($data['add'] ?? []), Identity::looksLikeOurs(...))),
            'remove_members' => array_values(array_filter((array) ($data['remove'] ?? []), Identity::looksLikeOurs(...))),
        ]));

        return $answer === null
            ? response()->json(['message' => 'Stream would not change the members.'], 502)
            : response()->json(['ok' => true]);
    }

    /** What this person may do, so the interface can stop offering what they cannot. */
    public function abilities(ParticipantDirectory $directory): JsonResponse
    {
        if ($directory->current() === null) {
            return response()->json(['message' => 'This account cannot use chat.'], 403);
        }

        $abilities = [];

        foreach (['create-channel', 'manage-channels', 'call', 'read-archive', 'play-recording', 'purge'] as $ability) {
            $abilities[$ability] = $directory->may($ability);
        }

        return response()->json($abilities);
    }
}
