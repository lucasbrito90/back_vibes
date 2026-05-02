<?php

use App\Http\Controllers\Api\FirebaseAuthController;
use App\Http\Controllers\Api\VibeController;
use Illuminate\Support\Facades\Route;

Route::post('/auth/firebase', [FirebaseAuthController::class, 'store']);

Route::middleware('firebase.auth')->group(function () {
    Route::apiResource('vibes', VibeController::class);
});
