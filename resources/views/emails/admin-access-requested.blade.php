<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Admin access requested</title>
</head>
<body style="font-family: system-ui, sans-serif; line-height: 1.5; color: #1e293b;">
<p>Someone requested access to the Ixora admin API.</p>

<ul>
    <li><strong>Name:</strong> {{ $accessRequest->user->name }}</li>
    <li><strong>Email:</strong> {{ $accessRequest->user->email }}</li>
    <li><strong>Firebase UID:</strong> {{ $accessRequest->user->firebase_uid }}</li>
    <li><strong>Requested at:</strong> {{ $accessRequest->requested_at?->toIso8601String() }}</li>
</ul>

<p style="margin-top: 1.5rem;">
    <a href="{{ $approveUrl }}" style="display: inline-block; padding: 10px 18px; background: #16a34a; color: #fff; text-decoration: none; border-radius: 8px;">Approve</a>
    &nbsp;&nbsp;
    <a href="{{ $rejectUrl }}" style="display: inline-block; padding: 10px 18px; background: #dc2626; color: #fff; text-decoration: none; border-radius: 8px;">Reject</a>
</p>

<p style="margin-top: 1.5rem; font-size: 12px; color: #64748b;">
    These links expire in {{ config('admin_access.signed_url_ttl_days') }} days.
</p>
</body>
</html>
