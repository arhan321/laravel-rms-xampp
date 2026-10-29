<?php

declare(strict_types=1);

return [
    // Safe default: editable in local development, closed in other environments.
    // Production / factory LAN: prefer authenticated user + role/permission.
    // If an internal operator workstation truly needs guest writes, explicitly
    // opt in ONLY after IT/CIT approves network and access restrictions.
    'guest_write_enabled' => (bool) env(
        'RMS_IDENTITY_GUEST_WRITE_ENABLED',
        env('APP_ENV', 'production') === 'local'
    ),
];
