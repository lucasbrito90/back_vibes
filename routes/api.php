<?php

use App\Http\Controllers\Api\AdminAccessRequestController;
use App\Http\Controllers\Api\FirebaseAuthController;
use App\Http\Controllers\Api\FirebaseUserSyncController;
use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\SoundController;
use App\Http\Controllers\Api\VibeController;
use App\Http\Controllers\Api\VibeSoundController;
use Illuminate\Support\Facades\Route;

// Public — no authentication required.
Route::get('/health', [HealthController::class, 'index']);

Route::post('/auth/firebase', [FirebaseAuthController::class, 'store']);
Route::post('/auth/sync', FirebaseUserSyncController::class);

Route::middleware('firebase.auth')->group(function () {
    Route::post('admin/access-requests', [AdminAccessRequestController::class, 'store']);

    Route::apiResource('vibes', VibeController::class);

    Route::get('sounds', [SoundController::class, 'index']);

    Route::prefix('vibes/{vibe}')->group(function () {
        Route::get('sounds', [VibeSoundController::class, 'index']);
        Route::post('sounds', [VibeSoundController::class, 'store']);
        Route::patch('sounds/{sound}', [VibeSoundController::class, 'update']);
        Route::delete('sounds/{sound}', [VibeSoundController::class, 'destroy']);
    });
});

if (app()->environment('testing')) {
    Route::middleware(['firebase.auth', 'admin.approved'])->get('__admin_gate', fn () => response()->json(['data' => ['ok' => true]]));
}
