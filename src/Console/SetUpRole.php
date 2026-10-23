<?php

namespace Revun\Chat\Console;

use Illuminate\Console\Command;
use Revun\Chat\Stream;

/**
 * Make a Stream role that can talk in the conversations it is put in, and do
 * nothing else.
 *
 * **For somebody who is not a colleague.** An institution reads this portal's
 * own pages and should be able to message the people working on its property —
 * and nobody else, and not by going around the screen. Two of Stream's
 * permissions decide that, and this takes both away:
 *
 *  - `search-user` (`.app`): without it a token cannot ask Stream for the
 *    directory. The list on screen is then the only list there is.
 *  - `create-channel` (`messaging`): without it a browser cannot start a
 *    conversation at all. They are started by the portal's own server, which
 *    uses a server token and so is not bound by any of this — and the portal
 *    decides who may be in one.
 *
 * Messaging inside a conversation is untouched: a member of a channel holds
 * `channel_member` there, and that is where `read-channel` and `create-message`
 * come from. So the rule is exactly "wherever the portal has put you, and
 * nowhere else".
 *
 * **Built from the `user` role rather than from a list in this file.** A list
 * written here is a list that is wrong the next time Stream adds a permission:
 * too few and chat breaks in a way nobody can see from the code, too many and
 * the whole point is lost. So the role is given what an ordinary user has,
 * less the two above.
 *
 * It writes nothing without `--apply`.
 */
class SetUpRole extends Command
{
    protected $signature = 'chat:role
        {name=institution : The role to create}
        {--from=user : The role whose permissions it starts from}
        {--drop=* : Permissions to leave out; the two that matter by default}
        {--channel-type=messaging : The channel type to grant on}
        {--apply : Write it. Without this nothing is sent.}';

    protected $description = 'Create a Stream role that can only talk where it has been put.';

    /** What this role must not be able to do, and why it is named here. */
    private const DROP = [
        // Asking Stream for the directory, around whatever the screen shows.
        'search-user',
        // Starting a conversation from the browser, with anybody at all.
        'create-channel',
    ];

    public function handle(Stream $stream): int
    {
        if (! $stream->configured()) {
            $this->error('STREAM_KEY and STREAM_SECRET are not set, so there is nothing to talk to.');

            return self::FAILURE;
        }

        $name = (string) $this->argument('name');
        $from = (string) $this->option('from');
        $type = (string) $this->option('channel-type');
        $drop = $this->option('drop') ?: self::DROP;

        $app = $stream->get('app');
        $channelType = $stream->get('channeltypes/' . $type);

        if ($app === null || $channelType === null) {
            $this->error('Stream did not answer. Nothing has been changed.');

            return self::FAILURE;
        }

        /*
         * What the ordinary user may do today, in both places a grant lives —
         * and they are not the same place. The application's are on the app
         * itself; a channel type's are only on the channel type, which has to
         * be asked for by name. Read from the app alone they come back empty,
         * which reads as "the user may do nothing in messaging" and would have
         * built a role that cannot say a word.
         */
        $appGrants = (array) data_get($app, 'app.grants.' . $from, []);
        $typeGrants = (array) data_get($channelType, 'grants.' . $from, []);

        /*
         * Either one empty is wrong, not just both: a role copied from a half
         * answer is a role that is broken in a way only somebody using it finds.
         */
        if ($appGrants === [] || $typeGrants === []) {
            $this->error('The "' . $from . '" role has no grants on '
                . ($appGrants === [] ? 'the application' : $type) . ', so there is nothing to copy.');

            return self::FAILURE;
        }

        $keep = fn (array $grants) => array_values(array_filter(
            $grants,
            fn ($permission) => ! in_array($permission, $drop, true),
        ));

        $newApp = $keep($appGrants);
        $newType = $keep($typeGrants);

        $this->line('Role <info>' . $name . '</info>, from <info>' . $from . '</info>');
        $this->newLine();
        $this->table(
            ['scope', $from . ' has', $name . ' gets', 'left out'],
            [
                ['.app', count($appGrants), count($newApp), implode(', ', array_diff($appGrants, $newApp)) ?: '—'],
                [$type, count($typeGrants), count($newType), implode(', ', array_diff($typeGrants, $newType)) ?: '—'],
            ],
        );

        /*
         * Said plainly, because a permission that was never there cannot be
         * taken away — and a role that was built without noticing that is a
         * role somebody believes is locked and is not.
         */
        foreach ($drop as $permission) {
            if (! in_array($permission, $appGrants, true) && ! in_array($permission, $typeGrants, true)) {
                $this->warn('"' . $permission . '" is not granted to "' . $from . '" either, so nothing was taken away for it.');
            }
        }

        if (! $this->option('apply')) {
            $this->newLine();
            $this->line('<comment>Nothing sent.</comment> Run it again with --apply.');

            return self::SUCCESS;
        }

        /*
         * The role first and the grants after. A role with no permissions is
         * harmless — it is the state every new role starts in — so a run that
         * stops between the two leaves nothing that can do anything.
         */
        if (! $this->createRole($stream, $name)) {
            return self::FAILURE;
        }

        if ($stream->patch('app', ['grants' => [$name => $newApp]]) === null) {
            $this->error('The application grants were refused. The role exists with no permissions.');

            return self::FAILURE;
        }

        if ($stream->put('channeltypes/' . $type, ['grants' => [$name => $newType]]) === null) {
            $this->error('The ' . $type . ' grants were refused. The role can do nothing in a channel.');

            return self::FAILURE;
        }

        $this->newLine();
        $this->info('"' . $name . '" is ready. Nobody holds it until chatRole() hands it to somebody.');

        return self::SUCCESS;
    }

    /**
     * A role that is already there is not an error.
     *
     * This is run again whenever Stream adds a permission the ordinary user
     * gets, so that the role keeps up with it; refusing on the second run would
     * make that impossible.
     */
    private function createRole(Stream $stream, string $name): bool
    {
        $roles = (array) data_get($stream->get('roles'), 'roles', []);

        foreach ($roles as $role) {
            if (($role['name'] ?? null) === $name) {
                $this->line('Role "' . $name . '" is already there; its grants are being rewritten.');

                return true;
            }
        }

        if ($stream->post('roles', ['name' => $name]) === null) {
            $this->error('Stream would not create the role.');

            return false;
        }

        return true;
    }
}
