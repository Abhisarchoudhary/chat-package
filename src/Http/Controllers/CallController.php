<?php

namespace Revun\Chat\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Revun\Chat\Archive\ChatCall;
use Revun\Chat\Contracts\ParticipantDirectory;
use Revun\Chat\Identity;

/**
 * Starting a call, which the browser is not trusted to decide for itself.
 *
 * The id is derived from the conversation, so both sides of a ringing phone
 * join the same call without negotiating one — and a second person pressing
 * call in the same conversation joins the one already running rather than
 * starting a rival.
 *
 * Whether somebody may call at all is the portal's permission, checked here.
 * The interface hides the button too, but a hidden button is a courtesy and
 * this is the rule.
 */
final class CallController
{
    public function start(Request $request, ParticipantDirectory $directory): JsonResponse
    {
        $person = $directory->current();

        if ($person === null || ! $directory->may('call')) {
            return response()->json(['message' => 'You cannot start a call.'], 403);
        }

        $data = $request->validate([
            'cid' => ['required', 'string', 'max:120'],
            'members' => ['array', 'max:50'],
            'members.*' => ['string', 'max:64'],
        ]);

        $me = Identity::forEmail($person->chatEmail());
        $members = array_values(array_unique(array_merge(
            array_filter((array) ($data['members'] ?? []), Identity::looksLikeOurs(...)),
            [$me],
        )));

        // Derived from the conversation, so it is the same call for everybody
        // in it however many people press the button.
        $callId = 'chat_'.substr(hash('sha256', $data['cid']), 0, 26);

        if ((bool) config('chat.archive.enabled')) {
            ChatCall::query()->updateOrCreate(['call_cid' => 'default:'.$callId], [
                'cid' => $data['cid'],
                'kind' => 'audio',
                'started_by' => $me,
                'participants' => $members,
                'started_at' => now(),
                'app' => (string) config('chat.archive.app'),
            ]);
        }

        return response()->json([
            'call_type' => 'default',
            'call_id' => $callId,
            'members' => $members,
            // Recording every call is the decision; the interface says so
            // rather than leaving people to assume either way.
            'recording' => (bool) config('chat.calls.record', true),
        ]);
    }
}
