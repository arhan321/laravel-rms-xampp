<?php

declare(strict_types=1);

namespace App\Services\Rms;

use App\Models\Robot;
use App\Models\RobotTelemetrySample;

/** A comparison with a technician-approved pose, never a motion command. */
final class HomePositionMonitor
{
    public const AXES = ['S', 'L', 'U', 'R', 'B', 'T'];

    public function forSample(Robot $robot, ?RobotTelemetrySample $sample, bool $fresh): array
    {
        $reference = data_get($robot->metadata, 'home_position');
        $result = [
            'status' => 'unknown', 'label' => 'UNKNOWN', 'configured' => false,
            'reason' => 'Home position belum ditetapkan oleh teknisi.',
            'axes' => [], 'tolerance_pulses' => null, 'sampled_at' => null,
        ];
        if (! is_array($reference) || ($reference['verified'] ?? false) !== true
            || ($reference['group'] ?? null) !== $robot->control_group
            || blank($reference['serial_number'] ?? null)
            || ($reference['serial_number'] ?? null) !== $robot->serial_number
            || blank($reference['verified_by'] ?? null) || blank($reference['source_reference'] ?? null)
            || (int) $robot->axis_count !== 6) {
            return $result;
        }
        $tolerance = $reference['tolerance_pulses'] ?? null;
        if (! is_int($tolerance) || $tolerance < 0 || $tolerance > 1_000_000) {
            return $result;
        }
        foreach (self::AXES as $axis) {
            $target = $reference['axes'][$axis] ?? null;
            if (! is_int($target) || $target < -2147483648 || $target > 2147483647) {
                return $result;
            }
            $result['axes'][$axis] = ['target' => $target, 'current' => null, 'delta' => null, 'within_tolerance' => null];
        }
        $result['configured'] = true;
        $result['tolerance_pulses'] = $tolerance;
        $result['reason'] = 'Menunggu pembacaan posisi yang segar dan lengkap.';
        if (! $fresh || ! $sample?->position_ok
            || ! $sample->sampled_at?->betweenIncluded(now()->subSeconds(60), now()->addSeconds(5))
            || data_get($sample->raw_payload, 'simulated') === true
            || data_get($sample->raw_payload, 'group') !== $reference['group']
            || ! in_array(data_get($sample->raw_payload, 'position_unit'), ['pulses', 'pulse'], true)) {
            return $result;
        }

        $allInside = true;
        foreach (self::AXES as $axis) {
            $pulse = $sample->position_pulses[$axis] ?? null;
            if (! is_numeric($pulse) || ! is_finite((float) $pulse)
                || floor((float) $pulse) !== (float) $pulse
                || $pulse < -2147483648 || $pulse > 2147483647) {
                return array_replace($result, ['reason' => 'Pembacaan enam axis belum lengkap atau valid.']);
            }
            $delta = (int) $pulse - $reference['axes'][$axis];
            $inside = abs($delta) <= $tolerance;
            $result['axes'][$axis] = ['target' => $reference['axes'][$axis], 'current' => (int) $pulse,
                'delta' => $delta, 'within_tolerance' => $inside];
            $allInside = $allInside && $inside;
        }
        $result['status'] = $allInside ? 'at_home' : 'away';
        $result['label'] = $allInside ? 'AT HOME' : 'OUTSIDE HOME';
        $result['reason'] = $allInside ? 'Seluruh axis berada dalam toleransi posisi acuan.'
            : 'Ada axis di luar toleransi posisi acuan.';
        $result['sampled_at'] = $sample->sampled_at?->toIso8601String();

        return $result;
    }
}
