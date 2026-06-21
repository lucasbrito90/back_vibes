<?php

use App\Http\Controllers\Admin\AdminAccessReviewWebController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::middleware('signed')->group(function () {
    Route::get('/admin/access-requests/{adminAccessRequest}/approve', [AdminAccessReviewWebController::class, 'approve'])
        ->name('admin.access-requests.approve');
    Route::get('/admin/access-requests/{adminAccessRequest}/reject', [AdminAccessReviewWebController::class, 'reject'])
        ->name('admin.access-requests.reject');
});
