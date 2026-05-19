<?php

use App\Http\Controllers\Api\Admin\UploadAssetController;
use App\Http\Controllers\Api\AdminAccessRequestController;
use App\Http\Controllers\Api\CoverBundleController;
use App\Http\Controllers\Api\FirebaseAuthController;
use App\Http\Controllers\Api\FirebaseUserSyncController;
use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\PresetVibeController;
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
    Route::get('sounds/{sound}', [SoundController::class, 'show']);

    Route::get('cover-bundles', [CoverBundleController::class, 'index']);
    Route::get('cover-bundles/{cover_bundle}', [CoverBundleController::class, 'show']);

    Route::get('preset-vibes', [PresetVibeController::class, 'index']);
    Route::get('preset-vibes/{preset_vibe}', [PresetVibeController::class, 'show']);
    Route::post('preset-vibes/{preset_vibe}/import', [PresetVibeController::class, 'import']);

    Route::middleware('admin.approved')->group(function () {
        Route::post('sounds', [SoundController::class, 'store']);
        Route::patch('sounds/{sound}', [SoundController::class, 'update']);
        Route::put('sounds/{sound}', [SoundController::class, 'update']);
        Route::delete('sounds/{sound}', [SoundController::class, 'destroy']);

        Route::post('cover-bundles', [CoverBundleController::class, 'store']);
        Route::patch('cover-bundles/{cover_bundle}', [CoverBundleController::class, 'update']);
        Route::put('cover-bundles/{cover_bundle}', [CoverBundleController::class, 'update']);
        Route::delete('cover-bundles/{cover_bundle}', [CoverBundleController::class, 'destroy']);

        Route::post('preset-vibes', [PresetVibeController::class, 'store']);
        Route::patch('preset-vibes/{preset_vibe}', [PresetVibeController::class, 'update']);
        Route::put('preset-vibes/{preset_vibe}', [PresetVibeController::class, 'update']);
        Route::delete('preset-vibes/{preset_vibe}', [PresetVibeController::class, 'destroy']);
        Route::put('preset-vibes/{preset_vibe}/sounds', [PresetVibeController::class, 'syncSounds']);

        Route::post('admin/uploads', [UploadAssetController::class, 'store']);
    });

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
