<?php

namespace Revun\Chat\Console;

use Illuminate\Console\Command;
use Revun\Chat\Archive\Archive;
use Revun\Chat\Archive\ChatChannel;
use Revun\Chat\Archive\ChatMessage;
use Revun\Chat\Stream;

/**
 * Re-reads the conversations and fills in whatever the webhook missed.
 *
 * **Because webhooks are best-effort.** A deploy, a restart, a network minute:
 * each loses events, and an archive with invisible holes is worse than no
 * archive, because nobody knows to look. This runs nightly, asks Stream for
 * each channel's recent messages, and writes the ones we do not hold.
 *
 * **And because the window closes.** Stream deletes its copy after the
 * retention the app is set to — past that we are the only copy and a gap can
 * never be repaired. So this reports what it found rather than fixing it
 * quietly: while it is still finding gaps, that retention should stay off.
 */
final class SweepArchive extends Command
{
    protected $signature = 'chat:sweep
        {--days=2 : How far back to re-read each conversation}
        {--channels=200 : How many conversations to check this run}';

    protected $description = 'Re-read conversations from Stream and backfill anything the archive is missing';

    public function handle(Stream $stream, Archive $archive): int
    {
        if (! $archive->enabled()) {
            $this->components->warn('This portal does not keep the archive (CHAT_ARCHIVE is off).');

            return self::SUCCESS;
        }

        if (! $stream->configured()) {
            $this->components->warn('Chat is not configured.');

            return self::SUCCESS;
        }

        $since = now()->subDays(max(1, (int) $this->option('days')));
        $limit = max(1, (int) $this->option('channels'));

        $answer = $stream->query('channels', [
            'filter_conditions' => ['last_message_at' => ['$gte' => $since->toIso8601String()]],
            'sort' => [['field' => 'last_message_at', 'direction' => -1]],
            'limit' => min($limit, 30),
            'state' => true,
            'message_limit' => 100,
        ]);

        $channels = (array) ($answer['channels'] ?? []);
        $found = 0;
        $filled = 0;

        foreach ($channels as $channel) {
            $cid = (string) ($channel['channel']['cid'] ?? '');

            if ($cid === '') {
                continue;
            }

            $archive->seenChannel($cid, ['channel' => $channel['channel']]);

            foreach ((array) ($channel['messages'] ?? []) as $message) {
                $found++;

                $known = ChatMessage::query()->where('stream_id', (string) ($message['id'] ?? ''))->exists();

                if ($known) {
                    continue;
                }

                $archive->message(['type' => 'message.new', 'cid' => $cid, 'message' => $message]);
                $filled++;
            }
        }

        $this->components->info(sprintf(
            '%d conversations read, %d messages seen, %d were missing and are now kept.',
            count($channels),
            $found,
            $filled,
        ));

        /*
         * Said plainly, because it is the thing somebody has to act on: while
         * the sweep is still finding messages the webhook did not deliver, the
         * vendor's retention must not be switched on.
         */
        if ($filled > 0) {
            $this->components->warn(sprintf(
                'The webhook missed %d messages. Leave Stream\'s %d-day deletion off until a sweep finds none.',
                $filled,
                (int) config('chat.archive.vendor_retention_days', 30),
            ));
        }

        $this->components->info(sprintf(
            'The archive holds %s messages across %s conversations.',
            number_format(ChatMessage::query()->count()),
            number_format(ChatChannel::query()->count()),
        ));

        return self::SUCCESS;
    }
}
