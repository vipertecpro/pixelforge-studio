<?php

use App\Http\Controllers\Admin\ReportController;
use App\Http\Controllers\CreditController;
use App\Http\Controllers\GenerationController;
use App\Http\Controllers\PixelforgeWebhookController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome')->name('home');

// Called by Pixelforge, not by a browser: signed instead of CSRF-protected
// (the CSRF exception is set in bootstrap/app.php).
Route::post('webhooks/pixelforge', PixelforgeWebhookController::class)->name('webhooks.pixelforge');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::redirect('dashboard', '/generate')->name('dashboard');

    Route::get('generate', [GenerationController::class, 'create'])->name('generations.create');
    Route::post('generations/estimate', [GenerationController::class, 'estimate'])
        ->middleware('throttle:60,1')
        ->name('generations.estimate');
    Route::post('generations', [GenerationController::class, 'store'])
        ->middleware('throttle:20,1')
        ->name('generations.store');
    Route::get('generations', [GenerationController::class, 'index'])->name('generations.index');

    Route::get('credits', [CreditController::class, 'index'])->name('credits.index');
    Route::post('credits', [CreditController::class, 'store'])->middleware('throttle:10,1')->name('credits.store');

    Route::get('admin/report', ReportController::class)->middleware('can:view-report')->name('admin.report');
});

require __DIR__.'/settings.php';
