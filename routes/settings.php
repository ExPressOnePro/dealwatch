<?php

use App\Http\Controllers\Admin\SettingsController as AdminSettingsController;
use App\Http\Controllers\Settings\PasswordController;
use App\Http\Controllers\Settings\ProfileController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::middleware('auth')->group(function () {
    Route::redirect('settings', 'settings/profile');

    Route::get('settings/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('settings/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('settings/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::get('settings/password', [PasswordController::class, 'edit'])->name('password.edit');
    Route::put('settings/password', [PasswordController::class, 'update'])->name('password.update');

    Route::get('settings/appearance', function () {
        return Inertia::render('settings/appearance');
    })->name('appearance');
});

// Ключи интеграций и лимиты расхода — только для администратора.
Route::middleware(['auth', 'admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('settings', [AdminSettingsController::class, 'edit'])->name('settings.edit');
    Route::patch('settings', [AdminSettingsController::class, 'update'])->name('settings.update');
    Route::delete('settings/secret', [AdminSettingsController::class, 'forget'])->name('settings.forget');
    Route::post('settings/test-connection', [AdminSettingsController::class, 'testConnection'])->name('settings.test');
    Route::post('settings/refresh-models', [AdminSettingsController::class, 'refreshModels'])->name('settings.models');
});
