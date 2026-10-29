<?php

declare(strict_types=1);

return [
    // No login/approval. Enabling permits writes from anyone who can reach the
    // RMS website; only enable behind a trusted internal network / access proxy.
    'writes_enabled' => (bool) env('RMS_RECOMMENDATION_WRITES_ENABLED', false),
    // Uses existing approved RMS health rules; not an OEM Yaskawa predictor.
    'auto_enabled' => (bool) env('RMS_RECOMMENDATION_AUTO_ENABLED', false),
];
