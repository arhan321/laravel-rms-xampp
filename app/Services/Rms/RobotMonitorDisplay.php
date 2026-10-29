<?php

declare(strict_types=1);

namespace App\Services\Rms;

use App\Models\Robot;
use App\Models\RobotHealthAssessment;
use App\Models\RobotTelemetrySample;

final class RobotMonitorDisplay
{
    public function __construct(private readonly ControllerStateTextReader $states,
        private readonly HomePositionMonitor $home) {}

    public function forRobot(Robot $robot, ?RobotTelemetrySample $sample,
        ?RobotHealthAssessment $assessment, bool $fresh): array
    {
        $fresh = $fresh && $sample?->sampled_at?->betweenIncluded(now()->subSeconds(60), now()->addSeconds(5))
            && data_get($sample?->raw_payload, 'simulated') !== true;
        $state = $fresh ? $this->states->latest($robot) : null;
        $servo = [
            'state' => $state === null ? 'unknown' : ($state['servo_on'] ? 'on' : 'off'),
            'label' => $state === null ? 'SERVO UNKNOWN' : ($state['servo_on'] ? 'SERVO ON' : 'SERVO OFF'),
            'sampled_at' => $state['sampled_at'] ?? null,
            'received_at' => $state['received_at'] ?? null,
            'valid_until' => $state['valid_until'] ?? null,
            'reason' => $state === null ? 'Status controller belum tersedia atau tidak segar.' : 'Pembacaan status servo dari controller.',
        ];
        $health = $this->health($assessment, $fresh, $state);

        return ['servo' => $servo, 'health_display' => $health,
            'home_position' => $this->home->forSample($robot, $sample, $fresh)];
    }

    public function health(?RobotHealthAssessment $assessment, bool $fresh, ?array $state = null): array
    {
        $status = 'unknown';
        $validUntil = null;
        $reason = 'Data atau assessment health belum segar dan lengkap.';
        if ($fresh && (($state['alarming'] ?? false) === true || ($state['error'] ?? false) === true)) {
            $status = 'upnormal';
            $validUntil = $state['valid_until'] ?? null;
            $reason = 'Controller melaporkan alarm/error. Tinjau Alarm Center dan pendant.';
        } elseif ($fresh && $assessment?->evaluated_at?->betweenIncluded(
            now()->subSeconds(max(60, (int) config('rms.telemetry_stale_seconds', 30) * 2)), now()->addSeconds(5)
        )) {
            $status = match ($assessment->health_status) {
                'healthy', 'normal' => data_get($assessment->indicators, 'evidence_complete') === true ? 'normal' : 'unknown',
                'critical', 'abnormal', 'upnormal' => 'upnormal',
                'warning' => 'warning',
                default => 'unknown',
            };
            $reason = $assessment->summary ?: $reason;
            $validUntil = $assessment->evaluated_at->addSeconds(max(60, (int) config('rms.telemetry_stale_seconds', 30) * 2))->toIso8601String();
        }

        return ['status' => $status, 'label' => strtoupper($status), 'reason' => $reason, 'valid_until' => $validUntil];
    }
}
