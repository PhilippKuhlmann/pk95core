<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\SetupController;
use App\Http\Controllers\ModulePageController;
use App\Http\Controllers\ProfileController;
use App\Livewire\Apps\Index as AppsIndex;
use App\Livewire\Apps\Show as AppShow;
use App\Livewire\Dashboard;
use App\Livewire\Events\Index as EventsIndex;
use App\Livewire\Notifications\Index as NotificationsIndex;
use App\Livewire\Settings\Index as SettingsIndex;
use Illuminate\Support\Facades\Route;

// --- first run + authentication ---------------------------------------------------
Route::get('/setup', [SetupController::class, 'show'])->name('setup');
Route::post('/setup', [SetupController::class, 'store'])->name('setup.store');

Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'show'])->name('login');
    Route::post('/login', [LoginController::class, 'store'])->name('login.store');
});

Route::middleware('auth')->group(function () {
    Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::post('/profile', [ProfileController::class, 'update'])->name('profile.update');

    Route::livewire('/', Dashboard::class)->name('dashboard');

    Route::livewire('/events', EventsIndex::class)->name('events.index');
    Route::livewire('/notifications', NotificationsIndex::class)->name('notifications.index');
    Route::livewire('/settings', SettingsIndex::class)->name('settings');

    // Catalog + lifecycle of the app containers
    Route::livewire('/apps', AppsIndex::class)->name('apps.index');
    Route::livewire('/apps/{installedApp}', AppShow::class)->name('apps.show');

    // Pages a Core module renders for a running app (menu entries from its manifest)
    Route::get('/apps/{installedApp}/{page}', ModulePageController::class)
        ->where('page', '[a-z0-9-]+')
        ->name('apps.page');
});
