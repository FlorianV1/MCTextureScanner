<?php

use App\Http\Controllers\ScanController;
use Illuminate\Support\Facades\Route;

Route::get('/', [ScanController::class, 'index'])->name('upload');
Route::post('/scan', [ScanController::class, 'scan'])->name('scan');
Route::get('/scan/{id}', [ScanController::class, 'results'])->name('results');

// New routes for editing
Route::post('/scan/add-texture', [ScanController::class, 'addTexture'])->name('scan.add');
Route::post('/scan/edit-texture', [ScanController::class, 'editTexture'])->name('scan.edit');
Route::post('/scan/delete-texture', [ScanController::class, 'deleteTexture'])->name('scan.delete');
Route::get('/scan/{id}/export', [ScanController::class, 'export'])->name('scan.export');
