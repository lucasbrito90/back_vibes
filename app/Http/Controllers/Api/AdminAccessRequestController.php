<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\AdminAccessStatusResource;
use App\Mail\AdminAccessRequestedMail;
use App\Models\AdminAccessRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;

final class AdminAccessRequestController extends Controller
{
    public function store(): JsonResponse
    {
        /** @var User $user */
        $user = Auth::user();

        if ($user->admin_access_status === 'approved' && $user->role === 'admin') {
            return AdminAccessStatusResource::make([
                'status' => 'approved',
                'requested_at' => null,
            ])->response();
        }

        $pending = AdminAccessRequest::query()
            ->where('user_id', $user->id)
            ->where('status', AdminAccessRequest::STATUS_PENDING)
            ->latest()
            ->first();

        if ($pending !== null) {
            return AdminAccessStatusResource::make([
                'status' => 'pending',
                'requested_at' => $pending->requested_at,
            ])->response();
        }

        $reviewEmail = config('admin_access.review_email');
        $ttlDays = max(1, (int) config('admin_access.signed_url_ttl_days'));
        $expires = now()->addDays($ttlDays);

        $accessRequest = DB::transaction(function () use ($user): AdminAccessRequest {
            $created = AdminAccessRequest::query()->create([
                'user_id' => $user->id,
                'status' => AdminAccessRequest::STATUS_PENDING,
                'requested_at' => now(),
            ]);

            $user->update([
                'admin_access_status' => 'pending',
            ]);

            return $created->load('user');
        });

        $approveUrl = URL::temporarySignedRoute(
            'admin.access-requests.approve',
            $expires,
            ['adminAccessRequest' => $accessRequest->id],
        );

        $rejectUrl = URL::temporarySignedRoute(
            'admin.access-requests.reject',
            $expires,
            ['adminAccessRequest' => $accessRequest->id],
        );

        Mail::to($reviewEmail)->queue(new AdminAccessRequestedMail(
            accessRequest: $accessRequest,
            approveUrl: $approveUrl,
            rejectUrl: $rejectUrl,
        ));

        return AdminAccessStatusResource::make([
            'status' => 'pending',
            'requested_at' => $accessRequest->requested_at,
        ])->response()->setStatusCode(201);
    }
}
