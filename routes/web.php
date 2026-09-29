<?php

use Illuminate\Support\Facades\Route;
use Revun\Chat\Http\Controllers\CallController;
use Revun\Chat\Http\Controllers\ChatController;
use Revun\Chat\Http\Controllers\TokenController;
use Revun\Chat\Http\Controllers\WebhookController;

Route::middleware(config('chat.routes.middleware', ['web', 'auth']))
    ->prefix((string) config('chat.routes.prefix', 'chat'))
    ->name('chat.')
    ->group(function () {
        // What the page asks for before it can connect, for the signed-in
        // person and nobody else.
        Route::post('token', TokenController::class)->middleware('throttle:30,1')->name('token');

        Route::get('directory', [ChatController::class, 'directory'])->name('directory');
        Route::get('abilities', [ChatController::class, 'abilities'])->name('abilities');

        /*
         * The few things a browser is not allowed to do for itself: Stream's
         * permissions refuse them to a user token, and they come here so the
         * portal's own permissions decide.
         */
        Route::post('channels', [ChatController::class, 'createChannel'])->name('channels.create');
        Route::post('channels/members', [ChatController::class, 'members'])->name('channels.members');

        /* Which call to join, decided here so two people press one button and
           land in the same conversation rather than two beside each other. */
        Route::post('calls', [CallController::class, 'start'])->middleware('throttle:60,1')->name('calls.start');
    });

/*
 * Stream telling the archive what happened. No session — Stream has none — so
 * the signature on the body is the authentication, and a secret in the path
 * keeps the endpoint from being found at all.
 */
if (filled(config('chat.routes.webhook_secret'))) {
    Route::post(
        trim((string) config('chat.routes.prefix', 'chat'), '/').'/webhook/'.config('chat.routes.webhook_secret'),
        WebhookController::class,
    )->middleware('throttle:600,1')->name('chat.webhook');
}
