<?php

use Illuminate\Support\Facades\Route;
use Revun\Chat\Http\Controllers\ChatController;
use Revun\Chat\Http\Controllers\TokenController;

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
    });
