<?php

declare(strict_types=1);

return [
    // The collector may sample the controller time and the web server may
    // receive it a moment later. Freshness is therefore based on received_at.
    'telemetry_stale_seconds' => max(5, (int) env('RMS_TELEMETRY_STALE_SECONDS', 30)),

    'robot_probe' => [
        'enabled' => (bool) env('RMS_ROBOT_PROBE_ENABLED', true),
        'timeout_seconds' => max(1, min(5, (int) env('RMS_ROBOT_PROBE_TIMEOUT_SECONDS', 2))),
        'ping_binary' => env('RMS_ROBOT_PROBE_PING_BINARY', ''),
        // YMConnect uses UDP 10040/10041. These are shown in the UI as
        // controller communication ports; Laravel does not send commands.
        'udp_ports' => array_values(array_filter(array_map(
            static fn (string $port): int => (int) trim($port),
            explode(',', (string) env('RMS_ROBOT_PROBE_UDP_PORTS', '10040,10041'))
        ), static fn (int $port): bool => $port > 0 && $port <= 65535)),
    ],
];
