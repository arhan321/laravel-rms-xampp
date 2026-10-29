<?php

return [
    // This RMS instance has no login. Enable guest writes by default only
    // in local development; explicitly opt in on a trusted factory LAN.
    'guest_write_enabled' => env('RMS_PM_GUEST_WRITE_ENABLED', env('APP_ENV', 'production') === 'local'),
];
