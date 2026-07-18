<?php

use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome')->name('home');

// Teacher-facing area. Everything here reads tenant-owned data, so an
// organization must be resolved before the request reaches a controller.
Route::middleware(['auth', 'verified', 'organization'])->group(function () {
    Route::inertia('dashboard', 'Dashboard')->name('dashboard');
});

require __DIR__.'/app.php';
require __DIR__.'/settings.php';
