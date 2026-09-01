<?php

use App\Http\Controllers\DealController;
use App\Http\Controllers\FavoriteController;
use App\Http\Controllers\MarketController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return auth()->check()
        ? redirect()->route('deals.index')
        : Inertia::render('welcome');
})->name('home');

Route::middleware(['auth'])->group(function () {
    Route::get('dashboard', fn () => redirect()->route('deals.index'))->name('dashboard');

    Route::get('deals', [DealController::class, 'index'])->name('deals.index');
    Route::patch('deals/{deal}', [DealController::class, 'updateStatus'])->name('deals.update');
    Route::post('deals/{deal}/favorite', [FavoriteController::class, 'store'])->name('deals.favorite');
    Route::delete('deals/{deal}/favorite', [FavoriteController::class, 'destroy'])->name('deals.unfavorite');
    Route::post('deals/collect', [DealController::class, 'collect'])->name('deals.collect');
    Route::post('deals/refresh-analytics', [DealController::class, 'refreshAnalytics'])->name('deals.refresh-analytics');
    Route::post('deals/import', [DealController::class, 'importUrl'])->name('deals.import');

    Route::get('favorites', [FavoriteController::class, 'index'])->name('favorites.index');
    Route::post('favorites/{deal}/complete', [FavoriteController::class, 'complete'])->name('favorites.complete');
    Route::post('favorites/{deal}/cancel', [FavoriteController::class, 'cancel'])->name('favorites.cancel');

    Route::get('market', [MarketController::class, 'index'])->name('market.index');
    Route::get('market/{marketPrice}', [MarketController::class, 'show'])->name('market.show');
});

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
