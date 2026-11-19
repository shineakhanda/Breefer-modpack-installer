<?php

use App\Http\Middleware\Activity\ServerSubject;
use App\Http\Middleware\Api\Client\Server\AuthenticateServerAccess;
use App\Http\Middleware\Api\Client\Server\ResourceBelongsToServer;
use Breefer\ModpackInstaller\Http\ModpackController;
use Illuminate\Support\Facades\Route;

/*
| Endpoint: /api/client/extensions/breefer-modpack-installer/servers/{server}
*/
Route::group([
    'prefix' => '/servers/{server}',
    'middleware' => [ServerSubject::class, AuthenticateServerAccess::class, ResourceBelongsToServer::class],
], function () {
    Route::get('/status', [ModpackController::class, 'status']);
    Route::get('/search', [ModpackController::class, 'search'])->middleware('throttle:90,1');
    Route::get('/packs/{provider}/{pack}/versions', [ModpackController::class, 'versions'])
        ->where('provider', 'modrinth|curseforge|ftb')->middleware('throttle:90,1');
    Route::post('/install', [ModpackController::class, 'install'])->middleware('throttle:5,1');
    Route::post('/restore', [ModpackController::class, 'restore']);
});
