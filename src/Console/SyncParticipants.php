<?php

namespace Revun\Chat\Console;

use Illuminate\Console\Command;
use Revun\Chat\Contracts\ParticipantDirectory;
use Revun\Chat\Stream;
use Revun\Chat\StreamUsers;

/**
 * Puts this portal's people into the shared directory.
 *
 * Run once when chat is switched on, and nightly afterwards. The token endpoint
 * already syncs whoever signs in, which covers the people who use chat — this
 * covers the rest, so somebody can be *found* and written to before they have
 * ever opened it.
 *
 * Three portals run this against one Stream application, and none of them can
 * tread on the others: each writes the fields it owns and adds itself to the
 * shared ones.
 */
final class SyncParticipants extends Command
{
    protected $signature = 'chat:sync {--limit= : Stop after this many, for a first look}';

    protected $description = 'Sync this portal’s chat people into the shared Stream directory';

    public function handle(ParticipantDirectory $directory, StreamUsers $users, Stream $stream): int
    {
        if (! $stream->configured()) {
            $this->components->warn('Chat is not configured: set STREAM_KEY and STREAM_SECRET.');

            return self::SUCCESS;
        }

        $limit = (int) ($this->option('limit') ?: 0);
        $synced = 0;

        foreach ($directory->all() as $person) {
            $users->sync($person);
            $synced++;

            if ($limit > 0 && $synced >= $limit) {
                break;
            }
        }

        $this->components->info(sprintf(
            '%d people synced as %s.',
            $synced,
            (string) config('chat.organisation'),
        ));

        return self::SUCCESS;
    }
}
