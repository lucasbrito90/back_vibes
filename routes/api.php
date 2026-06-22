<?php

use App\Http\Controllers\Api\Admin\UploadAssetController;
use App\Http\Controllers\Api\AdminAccessRequestController;
use App\Http\Controllers\Api\CoverBundleController;
use App\Http\Controllers\Api\DebugMeController;
use App\Http\Controllers\Api\DeviceController;
use App\Http\Controllers\Api\FirebaseAuthController;
use App\Http\Controllers\Api\FirebaseUserSyncController;
use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\PresetVibeController;
use App\Http\Controllers\Api\ProviderConnectionController;
use App\Http\Controllers\Api\PushTokenController;
use App\Http\Controllers\Api\ScheduleController;
use App\Http\Controllers\Api\ScheduleExecutionController;
use App\Http\Controllers\Api\SoundController;
use App\Http\Controllers\Api\VibeController;
use App\Http\Controllers\Api\VibeDeviceActionController;
use App\Http\Controllers\Api\VibeSmartHomeDispatchController;
use App\Http\Controllers\Api\VibeSoundController;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Route;

// Public — no authentication required.
Route::get('/health', [HealthController::class, 'index']);

Route::post('/auth/firebase', [FirebaseAuthController::class, 'store']);
Route::post('/auth/sync', FirebaseUserSyncController::class);

Route::middleware('firebase.auth')->group(function () {
    Route::post('admin/access-requests', [AdminAccessRequestController::class, 'store']);

    Route::apiResource('vibes', VibeController::class);
    Route::apiResource('schedules', ScheduleController::class);
    Route::apiResource('provider-connections', ProviderConnectionController::class);
    Route::post('provider-connections/{providerConnection}/sync', [ProviderConnectionController::class, 'sync'])
        ->name('provider-connections.sync');
    Route::apiResource('devices', DeviceController::class);

    Route::get('schedules/{schedule}/executions', [ScheduleExecutionController::class, 'index']);
    Route::post('schedules/{schedule}/executions/{occurrence_key}/ack', [ScheduleExecutionController::class, 'acknowledge'])
        ->where('occurrence_key', '[^/]+');

    Route::get('sounds', [SoundController::class, 'index']);
    Route::get('sounds/{sound}', [SoundController::class, 'show']);

    Route::get('cover-bundles', [CoverBundleController::class, 'index']);
    Route::get('cover-bundles/{cover_bundle}', [CoverBundleController::class, 'show']);

    Route::get('preset-vibes', [PresetVibeController::class, 'index']);
    Route::get('preset-vibes/{preset_vibe}', [PresetVibeController::class, 'show']);
    Route::post('preset-vibes/{preset_vibe}/import', [PresetVibeController::class, 'import']);

    Route::middleware('admin.approved')->group(function () {
        Route::post('admin/sounds', [SoundController::class, 'store']);
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

    // Push token registration, rotation, and deactivation.
    // refresh MUST be registered before the {pushToken} wildcard to avoid route conflict.
    Route::post('push-tokens/refresh', [PushTokenController::class, 'refresh']);
    Route::post('push-tokens', [PushTokenController::class, 'store']);
    Route::delete('push-tokens/{pushToken}', [PushTokenController::class, 'destroy']);

    Route::prefix('vibes/{vibe}')->group(function () {
        Route::get('sounds', [VibeSoundController::class, 'index']);
        Route::post('sounds', [VibeSoundController::class, 'store']);
        Route::patch('sounds/{sound}', [VibeSoundController::class, 'update']);
        Route::delete('sounds/{sound}', [VibeSoundController::class, 'destroy']);

        Route::post('smart-home/dispatch', VibeSmartHomeDispatchController::class);

        Route::get('device-actions', [VibeDeviceActionController::class, 'index']);
        Route::post('device-actions', [VibeDeviceActionController::class, 'store']);
        // reorder MUST be registered before the {action} wildcard routes.
        Route::post('device-actions/reorder', [VibeDeviceActionController::class, 'reorder']);
        Route::patch('device-actions/{action}', [VibeDeviceActionController::class, 'update']);
        Route::delete('device-actions/{action}', [VibeDeviceActionController::class, 'destroy']);
    });
});

// Smoke route for admin middleware (used by tests; harmless in other envs).
Route::middleware(['firebase.auth', 'admin.approved'])->get('__admin_gate', fn () => response()->json(['data' => ['ok' => true]]));

if (! App::environment('production')) {
    /*
     | Authenticated Laravel user QA snapshot (staging/local/testing only).
     | Never registered while APP_ENV=production.
     */
    Route::middleware(['firebase.auth', 'diagnostics.non_production'])
        ->get('/debug/me', DebugMeController::class)
        ->name('api.debug-me');
}
