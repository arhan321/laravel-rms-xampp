<?php

declare(strict_types=1);

return [
    // Same trusted-network, no-login write policy as the existing Recommendation form.
    'writes_enabled' => (bool) env('RMS_ALARM_COUNTERMEASURES_WRITES_ENABLED',
        env('RMS_RECOMMENDATION_WRITES_ENABLED', false)),
];
