<?php

use App\Providers\AppServiceProvider;
use App\Providers\PushNotificationServiceProvider;
use App\Providers\SmartHomeServiceProvider;

return [
    AppServiceProvider::class,
    SmartHomeServiceProvider::class,
    PushNotificationServiceProvider::class,
];
