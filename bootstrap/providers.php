<?php

use App\Providers\AppServiceProvider;
use App\Providers\PushNotificationServiceProvider;
use App\Providers\SmartHomeServiceProvider;
use App\Telemetry\Providers\TelemetryServiceProvider;

return [
    AppServiceProvider::class,
    SmartHomeServiceProvider::class,
    PushNotificationServiceProvider::class,
    TelemetryServiceProvider::class,
];
