<?php

namespace Revun\Chat;

use Illuminate\Support\ServiceProvider;

/**
 * Chat, installed the same way in three portals.
 *
 * The package brings its own routes and configuration and asks the portal for
 * almost nothing: which business it is, and a User model that can answer
 * `ChatParticipant`. Everything else — who may create a channel, who may hear a
 * recording — stays in the portal's own permissions, where a super admin can
 * see and change it.
 */
final class ChatServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/chat.php', 'chat');

        $this->app->singleton(Stream::class);
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');

        /*
         * One archive, in one portal. Where it is off the package still mints
         * tokens and syncs people; it simply does not listen, and its tables
         * are not created in a database that would then hold half a history.
         */
        if ((bool) config('chat.archive.enabled')) {
            $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        }

        $this->publishes([
            __DIR__.'/../config/chat.php' => config_path('chat.php'),
        ], 'chat-config');
    }
}
