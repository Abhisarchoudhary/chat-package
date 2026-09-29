<?php

namespace Revun\Chat\Archive;

use Carbon\CarbonImmutable;

/**
 * Writing down what happened, so the record outlives the vendor's retention.
 *
 * Every write is an upsert on Stream's own id. The webhook can deliver the same
 * event twice, and the nightly sweep deliberately re-reads messages we may
 * already hold — an archive that duplicated on a retry would be worse than one
 * with gaps, because nothing about it would look wrong.
 *
 * Nothing here decides anything. It records: a message arrived, a message was
 * edited, somebody removed one, somebody joined. What any of that *means* is
 * the audit page's question.
 */
final class Archive
{
    public function enabled(): bool
    {
        return (bool) config('chat.archive.enabled');
    }

    /**
     * One event from Stream.
     *
     * @param  array<string, mixed>  $event
     */
    public function record(array $event): void
    {
        if (! $this->enabled()) {
            return;
        }

        match ((string) ($event['type'] ?? '')) {
            'message.new', 'message.updated' => $this->message($event),
            'message.deleted' => $this->deleted($event),
            'channel.created', 'channel.updated' => $this->channel($event),
            'channel.deleted' => $this->channelDeleted($event),
            'member.added', 'member.updated' => $this->member($event),
            'member.removed' => $this->memberLeft($event),
            default => null,
        };
    }

    /**
     * A message, however many times we are told about it.
     *
     * @param  array<string, mixed>  $event
     */
    public function message(array $event): ?ChatMessage
    {
        $message = (array) ($event['message'] ?? []);
        $id = (string) ($message['id'] ?? '');
        $cid = (string) ($event['cid'] ?? $message['cid'] ?? '');

        if ($id === '' || $cid === '') {
            return null;
        }

        $this->seenChannel($cid, $event);

        $sent = $this->moment($message['created_at'] ?? null);

        /*
         * Only what this event actually carried.
         *
         * A `message.deleted` event has an id and nothing else, and an update
         * that wrote its missing fields as null would erase the words the
         * archive exists to keep — the deletion would take the record with it,
         * which is precisely the thing a super admin's purge is supposed to be
         * the only way to do.
         */
        $attributes = array_filter([
            'cid' => $cid,
            'user_id' => $message['user']['id'] ?? null,
            'user_name' => $message['user']['name'] ?? null,
            'text' => $message['text'] ?? null,
            'parent_id' => $message['parent_id'] ?? null,
            'attachments' => $message['attachments'] ?? null,
            'app' => (string) config('chat.archive.app'),
            'sent_at' => $sent,
            'edited_at' => ($message['updated_at'] ?? null) && $message['updated_at'] !== ($message['created_at'] ?? null)
                ? $this->moment($message['updated_at'])
                : null,
            'deleted_at' => $this->moment($message['deleted_at'] ?? null),
        ], static fn ($value) => $value !== null);

        $row = ChatMessage::query()->updateOrCreate(['stream_id' => $id], $attributes);

        if ($sent !== null) {
            ChatChannel::query()->where('cid', $cid)->update(['last_message_at' => $sent]);
        }

        return $row;
    }

    /**
     * Somebody removed their own message.
     *
     * The text is left where it is. Hiding a message from a conversation and
     * erasing it from the record are different things, and only one of them is
     * a super admin's to do.
     *
     * @param  array<string, mixed>  $event
     */
    private function deleted(array $event): void
    {
        $id = (string) ($event['message']['id'] ?? '');

        if ($id === '') {
            return;
        }

        // Record it first if this is the first we have heard of it at all.
        $this->message($event);

        ChatMessage::query()->where('stream_id', $id)->update([
            'deleted_at' => $this->moment($event['message']['deleted_at'] ?? null) ?? CarbonImmutable::now(),
            'deleted_by' => $event['user']['id'] ?? ($event['message']['user']['id'] ?? null),
        ]);
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function channel(array $event): void
    {
        $this->seenChannel((string) ($event['cid'] ?? ''), $event);
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function channelDeleted(array $event): void
    {
        ChatChannel::query()
            ->where('cid', (string) ($event['cid'] ?? ''))
            ->update(['deleted_at' => CarbonImmutable::now()]);
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function member(array $event): void
    {
        $cid = (string) ($event['cid'] ?? '');
        $user = (string) ($event['member']['user_id'] ?? $event['user']['id'] ?? '');

        if ($cid === '' || $user === '') {
            return;
        }

        ChatMember::query()->updateOrCreate(['cid' => $cid, 'user_id' => $user], [
            'role' => $event['member']['channel_role'] ?? null,
            'joined_at' => $this->moment($event['member']['created_at'] ?? null) ?? CarbonImmutable::now(),
            'left_at' => null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function memberLeft(array $event): void
    {
        ChatMember::query()
            ->where('cid', (string) ($event['cid'] ?? ''))
            ->where('user_id', (string) ($event['user']['id'] ?? ''))
            ->update(['left_at' => CarbonImmutable::now()]);
    }

    /**
     * The channel a message or an event belongs to, created on first sight.
     *
     * Channels made before the archive existed are learnt from their first
     * message rather than being absent from a record of their own conversation.
     *
     * @param  array<string, mixed>  $event
     */
    public function seenChannel(string $cid, array $event = []): void
    {
        if ($cid === '') {
            return;
        }

        [$type, $id] = array_pad(explode(':', $cid, 2), 2, '');
        $channel = (array) ($event['channel'] ?? []);

        ChatChannel::query()->updateOrCreate(['cid' => $cid], array_filter([
            'type' => $type,
            'channel_id' => $id,
            'name' => $channel['name'] ?? null,
            'portal' => $channel['portal'] ?? null,
            'app' => (string) config('chat.archive.app'),
            'created_by' => $channel['created_by']['id'] ?? ($channel['created_by_id'] ?? null),
            'created_at' => $this->moment($channel['created_at'] ?? null),
        ], static fn ($value) => $value !== null && $value !== ''));
    }

    private function moment(mixed $value): ?CarbonImmutable
    {
        return blank($value) ? null : CarbonImmutable::parse((string) $value)->utc();
    }
}
