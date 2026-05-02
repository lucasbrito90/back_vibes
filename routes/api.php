<?php

use App\Http\Controllers\Api\FirebaseAuthController;
use Illuminate\Support\Facades\Route;

Route::post('/auth/firebase', [FirebaseAuthController::class, 'store']);
