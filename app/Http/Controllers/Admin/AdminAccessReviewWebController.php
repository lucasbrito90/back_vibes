<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminAccessRequest;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;

final class AdminAccessReviewWebController extends Controller
{
    public function approve(AdminAccessRequest $adminAccessRequest): View
    {
        if (! $adminAccessRequest->isPending()) {
            return view('admin.access-review-result', [
                'title' => 'Already processed',
                'message' => 'This access request was already reviewed.',
            ]);
        }

        DB::transaction(function () use ($adminAccessRequest): void {
            $adminAccessRequest->update([
                'status' => AdminAccessRequest::STATUS_APPROVED,
                'reviewed_at' => now(),
            ]);

            $adminAccessRequest->user->update([
                'role' => 'admin',
                'admin_access_status' => 'approved',
            ]);
        });

        return view('admin.access-review-result', [
            'title' => 'Access approved',
            'message' => 'The user now has approved admin access.',
        ]);
    }

    public function reject(AdminAccessRequest $adminAccessRequest): View
    {
        if (! $adminAccessRequest->isPending()) {
            return view('admin.access-review-result', [
                'title' => 'Already processed',
                'message' => 'This access request was already reviewed.',
            ]);
        }

        DB::transaction(function () use ($adminAccessRequest): void {
            $adminAccessRequest->update([
                'status' => AdminAccessRequest::STATUS_REJECTED,
                'reviewed_at' => now(),
            ]);

            $adminAccessRequest->user->update([
                'role' => 'user',
                'admin_access_status' => 'rejected',
            ]);
        });

        return view('admin.access-review-result', [
            'title' => 'Access rejected',
            'message' => 'This admin access request has been rejected.',
        ]);
    }
}
