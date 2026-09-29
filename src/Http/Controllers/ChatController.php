<?php

namespace Revun\Chat\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Revun\Chat\Contracts\ParticipantDirectory;
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

        $people = $users->directory(
            limit: 100,
            search: $request->string('q')->toString() ?: null,
        );

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
                'online' => (bool) ($person['online'] ?? false),
                'organisation' => $organisation,
            ];
        }

        // This portal's own people first: that is who somebody is usually after.
        uksort($groups, fn (string $a, string $b) => $a === $here ? -1 : ($b === $here ? 1 : strcmp($a, $b)));

        return response()->json(['groups' => $groups]);
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
