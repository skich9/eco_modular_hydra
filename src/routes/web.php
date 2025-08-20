<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\TestController;

Route::get('/', function () {
    return view('welcome');
});

// Rutas API
Route::prefix('api')->group(function () {
    Route::get('/test', [TestController::class, 'test']);
    Route::get('/hello', [TestController::class, 'test']);
    Route::get('/health', function() {
        return response()->json([
            'status' => 'ok',
            'timestamp' => now(),
            'service' => 'Laravel API'
        ]);
    });
});
