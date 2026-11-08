<?php

use App\Http\Controllers\Api\ContextController;
use App\Http\Controllers\Api\EventController;
use Illuminate\Support\Facades\Route;

// App protocol v1: apps → Core. Authenticated with each app's own service token.
Route::post('/v1/events', EventController::class)->name('api.events');
// Settings apps need from Core (base domain for deployments, which links are switched on).
Route::get('/v1/context', ContextController::class)->name('api.context');
