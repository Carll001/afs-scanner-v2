<?php

use App\Http\Controllers\DocumentGeneratorController;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Features;

Route::inertia('/', 'Welcome', [
    'canRegister' => Features::enabled(Features::registration()),
])->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'Dashboard')->name('dashboard');

    Route::get('document-generator', [DocumentGeneratorController::class, 'index'])
        ->name('document-generator.index');
    Route::get('generated-files', [DocumentGeneratorController::class, 'generatedFiles'])
        ->name('generated-files.index');
    Route::get('generated-files/{batch:uuid}', [DocumentGeneratorController::class, 'generatedFilesBatch'])
        ->name('generated-files.show');
    Route::post('document-generator/batches', [DocumentGeneratorController::class, 'store'])
        ->name('document-generator.batches.store');
    Route::get('document-generator/batches/history', [DocumentGeneratorController::class, 'history'])
        ->name('document-generator.batches.history');
    Route::get('document-generator/batches/{batch:uuid}/progress', [DocumentGeneratorController::class, 'progress'])
        ->name('document-generator.batches.progress');
    Route::get('document-generator/batches/{batch:uuid}/items', [DocumentGeneratorController::class, 'items'])
        ->name('document-generator.batches.items');
    Route::get('document-generator/batches/{batch:uuid}/items/{item:uuid}', [DocumentGeneratorController::class, 'showItem'])
        ->name('document-generator.batches.items.show');
    Route::put('document-generator/batches/{batch:uuid}/items/{item:uuid}', [DocumentGeneratorController::class, 'updateItem'])
        ->name('document-generator.batches.items.update');
    Route::get('document-generator/batches/{batch:uuid}/items/{item:uuid}/{type}', [DocumentGeneratorController::class, 'download'])
        ->name('document-generator.batches.items.download');
    Route::get('document-generator/batches/{batch:uuid}/logs', [DocumentGeneratorController::class, 'logs'])
        ->name('document-generator.batches.logs');
});

require __DIR__.'/settings.php';
