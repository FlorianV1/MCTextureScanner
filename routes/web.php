<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ScanController;

Route::get('/', [ScanController::class, 'index'])->name('upload');
Route::post('/scan', [ScanController::class, 'scan'])->name('scan');
Route::get('/scan/{id}', [ScanController::class, 'results'])->name('results');
Route::get('/scan/{id}/settings', [ScanController::class, 'viewSettings'])->name('view-settings');
Route::get('/scan/{id}/export', [ScanController::class, 'export'])->name('export');

Route::post('/scan/add-texture', [ScanController::class, 'addTexture'])->name('add-texture');
Route::post('/scan/bulk-add-missing', [ScanController::class, 'bulkAddMissing'])->name('bulk-add-missing');
Route::post('/scan/edit-texture', [ScanController::class, 'editTexture'])->name('edit-texture');
Route::post('/scan/delete-textures', [ScanController::class, 'deleteTextures'])->name('delete-textures');
