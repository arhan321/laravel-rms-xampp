<?php

declare(strict_types=1);

return [
    // Existing trusted LAN maintenance workflow; only open guest writes when explicitly enabled outside local.
    'oem_import_enabled' => (bool) env('RMS_REDUCER_OEM_IMPORT_ENABLED', env('APP_ENV', 'production') === 'local'),
];
