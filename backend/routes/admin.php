<?php

use Breefer\ModpackInstaller\Support\AdminSettingsController;
use Illuminate\Support\Facades\Route;

/*
| Endpoint: /admin/extensions/breefer-modpack-installer
*/
Route::get('/', [AdminSettingsController::class, 'index']);
Route::post('/', [AdminSettingsController::class, 'save']);
Route::get('/diagnostics', [\Breefer\ModpackInstaller\Support\DiagnosticsController::class, 'show']);
