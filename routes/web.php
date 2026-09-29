<?php

use Illuminate\Support\Facades\Route;
use Revun\Chat\Http\Controllers\TokenController;

Route::middleware(config('chat.routes.middleware', ['web', 'auth']))
    ->prefix((string) config('chat.routes.prefix', 'chat'))
    ->name('chat.')
    ->group(function () {
        // What the page asks for before it can connect, for the signed-in
        // person and nobody else.
        Route::post('token', TokenController::class)->middleware('throttle:30,1')->name('token');
    });
