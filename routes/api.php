<?php

use App\Http\Controllers\Api\FirebaseAuthController;
use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\SoundController;
use App\Http\Controllers\Api\VibeSoundController;
use App\Http\Controllers\Api\VibeController;
use Illuminate\Support\Facades\Route;

// Public — no authentication required.
Route::get('/health', [HealthController::class, 'index']);

Route::post('/auth/firebase', [FirebaseAuthController::class, 'store']);

Route::middleware('firebase.auth')->group(function () {
    Route::apiResource('vibes', VibeController::class);

    Route::get('sounds', [SoundController::class, 'index']);

    Route::prefix('vibes/{vibe}')->group(function () {
        Route::get('sounds', [VibeSoundController::class, 'index']);
        Route::post('sounds', [VibeSoundController::class, 'store']);
        Route::patch('sounds/{sound}', [VibeSoundController::class, 'update']);
        Route::delete('sounds/{sound}', [VibeSoundController::class, 'destroy']);
    });
});
