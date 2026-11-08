<?php

use App\Http\Controllers\Api\EventController;
use Illuminate\Support\Facades\Route;

// App protocol v1: apps → Core. Authenticated with each app's own service token.
Route::post('/v1/events', EventController::class)->name('api.events');
