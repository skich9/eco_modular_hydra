<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\TestController;

// Rutas API para Laravel 12
Route::get('test', [TestController::class, 'test']);
Route::get('hello', [TestController::class, 'test']);
Route::get('health', function() {
    return response()->json([
        'status' => 'ok',
        'timestamp' => now(),
        'service' => 'Laravel API'
    ]);
});