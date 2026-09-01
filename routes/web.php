<?php

use App\Http\Controllers\DealController;
use App\Http\Controllers\FavoriteController;
use App\Http\Controllers\MarketController;
use App\Http\Controllers\NicheController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\SearchProfileController;
use App\Http\Controllers\TradeController;
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
    Route::post('deals/analyze', [DealController::class, 'analyze'])->name('deals.analyze');
    Route::delete('deals/runs', [DealController::class, 'dismissRun'])->name('deals.runs.dismiss');
    Route::post('deals/{deal}/ai-report', [DealController::class, 'aiReport'])->name('deals.ai-report');

    Route::get('favorites', [FavoriteController::class, 'index'])->name('favorites.index');
    Route::post('favorites/{deal}/complete', [FavoriteController::class, 'complete'])->name('favorites.complete');
    Route::post('favorites/{deal}/cancel', [FavoriteController::class, 'cancel'])->name('favorites.cancel');

    Route::get('trades', [TradeController::class, 'index'])->name('trades.index');
    Route::post('trades', [TradeController::class, 'store'])->name('trades.store');
    Route::patch('trades/{trade}', [TradeController::class, 'update'])->name('trades.update');
    Route::delete('trades/{trade}', [TradeController::class, 'destroy'])->name('trades.destroy');

    Route::get('reports', [ReportController::class, 'index'])->name('reports.index');

    Route::get('niches', [NicheController::class, 'index'])->name('niches.index');
    Route::post('niches/{searchProfile}/scan', [NicheController::class, 'scan'])->name('niches.scan');
    Route::post('niches/{searchProfile}/full', [NicheController::class, 'full'])->name('niches.full');

    Route::get('sources', [SearchProfileController::class, 'index'])->name('sources.index');
    Route::post('sources', [SearchProfileController::class, 'store'])->name('sources.store');
    Route::patch('sources/{searchProfile}', [SearchProfileController::class, 'update'])->name('sources.update');
    Route::delete('sources/{searchProfile}', [SearchProfileController::class, 'destroy'])->name('sources.destroy');
    Route::post('sources/{searchProfile}/collect', [SearchProfileController::class, 'collect'])->name('sources.collect');

    Route::get('market', [MarketController::class, 'index'])->name('market.index');
    Route::get('market/{marketPrice}', [MarketController::class, 'show'])->name('market.show');
});

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
