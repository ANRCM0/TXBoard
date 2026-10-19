<?php

use App\Http\Controllers\Browser\CoreBrowserController;
use App\Http\Middleware\BrowserIngress;
use Illuminate\Support\Facades\Route;

Route::prefix('gateway/v1')->middleware(BrowserIngress::class)->group(function (): void {
    Route::get('bootstrap', [CoreBrowserController::class, 'bootstrap']);
    Route::get('core/{domain}/capabilities', [CoreBrowserController::class, 'capabilities'])->where('domain', 'user|admin');
    Route::post('core/{domain}/operations/{operation}', [CoreBrowserController::class, 'operation'])
        ->where(['domain' => 'user|admin', 'operation' => '[a-z][a-z0-9.-]{1,100}']);
});
