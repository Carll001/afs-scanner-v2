<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DocumentGeneratorController;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Features;

Route::inertia('/', 'Welcome', [
    'canRegister' => Features::enabled(Features::registration()),
])->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');

    Route::get('document-generator', [DocumentGeneratorController::class, 'index'])
        ->name('document-generator.index');
    Route::get('document-generator/signature', [DocumentGeneratorController::class, 'signature'])
        ->name('document-generator.signature.show');
    Route::post('document-generator/signature', [DocumentGeneratorController::class, 'storeSignature'])
        ->name('document-generator.signature.store');
    Route::delete('document-generator/signature', [DocumentGeneratorController::class, 'destroySignature'])
        ->name('document-generator.signature.destroy');
    Route::get('document-generator/signature/preview', [DocumentGeneratorController::class, 'signaturePreview'])
        ->name('document-generator.signature.preview');
    Route::get('document-generator/template-mapping', [DocumentGeneratorController::class, 'templateMapping'])
        ->name('document-generator.template-mapping');
    Route::get('generated-files', [DocumentGeneratorController::class, 'generatedFiles'])
        ->name('generated-files.index');
    Route::get('generated-files/{batch}/template-mapping', [DocumentGeneratorController::class, 'generatedFilesTemplateMapping'])
        ->name('generated-files.template-mapping');
    Route::get('generated-files/{batch}', [DocumentGeneratorController::class, 'generatedFilesBatch'])
        ->name('generated-files.show');
    Route::post('document-generator/batches', [DocumentGeneratorController::class, 'store'])
        ->name('document-generator.batches.store');
    Route::post('document-generator/templates/default', [DocumentGeneratorController::class, 'updateGlobalDefaultTemplate'])
        ->name('document-generator.templates.default');
    Route::post('document-generator/templates', [DocumentGeneratorController::class, 'storeGlobalTemplate'])
        ->name('document-generator.templates.store');
    Route::post('document-generator/templates/{template}/update', [DocumentGeneratorController::class, 'updateGlobalTemplate'])
        ->name('document-generator.templates.update');
    Route::delete('document-generator/templates/{template}', [DocumentGeneratorController::class, 'destroyGlobalTemplate'])
        ->name('document-generator.templates.destroy');
    Route::get('document-generator/batches/history', [DocumentGeneratorController::class, 'history'])
        ->name('document-generator.batches.history');
    Route::get('document-generator/items', [DocumentGeneratorController::class, 'allItems'])
        ->name('document-generator.items');
    Route::get('document-generator/batches/{batch}/template-mapping', [DocumentGeneratorController::class, 'generatedFilesTemplateMapping'])
        ->name('document-generator.batches.template-mapping');
    Route::get('document-generator/batches/{batch}/progress', [DocumentGeneratorController::class, 'progress'])
        ->name('document-generator.batches.progress');
    Route::delete('document-generator/batches/{batch}', [DocumentGeneratorController::class, 'destroyBatch'])
        ->name('document-generator.batches.destroy');
    Route::get('document-generator/batches/{batch}/items', [DocumentGeneratorController::class, 'items'])
        ->name('document-generator.batches.items');
    Route::get('document-generator/batches/{batch}/items/{item}', [DocumentGeneratorController::class, 'showItem'])
        ->name('document-generator.batches.items.show');
    Route::post('document-generator/batches/{batch}/items/{item}/signature', [DocumentGeneratorController::class, 'signItem'])
        ->name('document-generator.batches.items.signature');
    Route::put('document-generator/batches/{batch}/items/{item}', [DocumentGeneratorController::class, 'updateItem'])
        ->name('document-generator.batches.items.update');
    Route::delete('document-generator/batches/{batch}/items/{item}', [DocumentGeneratorController::class, 'destroyItem'])
        ->name('document-generator.batches.items.destroy');
    Route::get('document-generator/batches/{batch}/items/{item}/{type}', [DocumentGeneratorController::class, 'download'])
        ->name('document-generator.batches.items.download');
    Route::get('document-generator/batches/{batch}/logs', [DocumentGeneratorController::class, 'logs'])
        ->name('document-generator.batches.logs');
    Route::post('document-generator/items/signature/bulk', [DocumentGeneratorController::class, 'signItemsBulk'])
        ->name('document-generator.items.signature.bulk');
    Route::post('document-generator/batches/{batch}/templates/default', [DocumentGeneratorController::class, 'updateDefaultTemplate'])
        ->name('document-generator.batches.templates.default');
    Route::post('document-generator/batches/{batch}/templates', [DocumentGeneratorController::class, 'storeTemplate'])
        ->name('document-generator.batches.templates.store');
    Route::post('document-generator/batches/{batch}/templates/{template}/update', [DocumentGeneratorController::class, 'updateTemplate'])
        ->name('document-generator.batches.templates.update');
    Route::delete('document-generator/batches/{batch}/templates/{template}', [DocumentGeneratorController::class, 'destroyTemplate'])
        ->name('document-generator.batches.templates.destroy');
});

require __DIR__.'/settings.php';
