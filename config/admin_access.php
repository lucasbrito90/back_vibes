<?php

return [
    'review_email' => env('ADMIN_ACCESS_REVIEW_EMAIL', 'lucas_brito@outlook.com'),

    'signed_url_ttl_days' => (int) env('ADMIN_ACCESS_SIGNED_URL_TTL_DAYS', 7),
];
