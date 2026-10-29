<?php

declare(strict_types=1);

return [
    // Live is the normal RMS mode. Set RMS_LIVE_ENABLED=false only for diagnostics.
    'enabled' => (bool) env('RMS_LIVE_ENABLED', true),
    'public_app_key' => env('REVERB_APP_KEY', ''),
    'stale_seconds' => max(5, (int) env('RMS_LIVE_STALE_SECONDS', 30)),
    // Browser reconciliation is retained even with WebSocket in case of lost notifications.
    'reconcile_seconds' => max(5, (int) env('RMS_LIVE_RECONCILE_SECONDS', 20)),
    'fallback_seconds' => max(2, (int) env('RMS_LIVE_FALLBACK_SECONDS', 4)),
    // Never enable on real robot ids; only for records explicitly marked DEMO_.
    'allow_demo_writer' => (bool) env('RMS_LIVE_ALLOW_DEMO_WRITER', false),
];
