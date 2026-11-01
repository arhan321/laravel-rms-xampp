<?php

declare(strict_types=1);

return [
    'writes_enabled' => (bool) env('RMS_ROBOT_GROUP_WRITES_ENABLED',
        env('RMS_IDENTITY_GUEST_WRITE_ENABLED', env('APP_ENV', 'production') === 'local')),
];
