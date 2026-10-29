<?php

declare(strict_types=1);

namespace App\Services\Rms;

use App\Models\RobotControllerStateSample;
use App\Models\RobotTelemetrySample;

/** Stores only complete, structured, successful YMConnect state reads. */
final class ControllerStateIngestor
{
    public function ingest(RobotTelemetrySample $sample): bool
    {
        $raw = $sample->raw_payload;
        if (! is_array($raw) || ($raw['simulated'] ?? false) === true
            || data_get($raw, 'state_read.read_ok') !== true) {
            return false;
        }

        $state = app(ControllerStatePayload::class)->parse($raw);
        if (! is_array($state) || ($state['known'] ?? false) !== true) {
            return false;
        }

        foreach (['running', 'servo_on', 'alarm', 'hold', 'error'] as $field) {
            if (! array_key_exists($field, $state) || ! is_bool($state[$field])) {
                return false;
            }
        }

        $control = $state['control_mode'] ?? null;
        $cycle = $state['cycle_mode'] ?? null;
        RobotControllerStateSample::query()->firstOrCreate(
            ['telemetry_sample_id' => $sample->id],
            [
                'robot_id' => $sample->robot_id,
                'sampled_at' => $sample->sampled_at,
                'received_at' => $sample->received_at ?? $sample->sampled_at,
                'running' => $state['running'], 'servo_on' => $state['servo_on'],
                'alarm' => $state['alarm'], 'hold' => $state['hold'], 'error' => $state['error'],
                'control_mode' => in_array($control, ['Teach', 'Play', 'Remote'], true) ? $control : null,
                'cycle_mode' => in_array($cycle, ['Step', 'Cycle', 'Automatic'], true) ? $cycle : null,
            ]
        );

        return true;
    }
}
