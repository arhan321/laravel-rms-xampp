<?php

namespace App\Services\Rms;

use App\Models\Robot;
use App\Models\RobotTelemetrySample;

final class TelemetryHistory
{
    public const LIMIT = 120;

    /** @return list<array<string, mixed>> */
    public function forRobot(Robot $robot): array
    {
        return $robot->telemetrySamples()
            ->select(['id', 'robot_id', 'sampled_at', 'sequence', 'position_ok', 'torque_ok', 'torque_unit', 'torque_axes'])
            ->orderByDesc('sampled_at')
            ->orderByDesc('id')
            ->limit(self::LIMIT)
            ->get()
            ->map(fn (RobotTelemetrySample $sample): array => $this->present($sample))
            ->all();
    }

    /** Historical read results remain valid even when the current connection is stale. */
    public function present(RobotTelemetrySample $sample): array
    {
        return [
            'id' => (string) $sample->getKey(),
            'sampled_at' => $sample->sampled_at?->format('Y-m-d\TH:i:s.vP'),
            'time_wib' => $sample->sampled_at?->timezone('Asia/Jakarta')->format('d M H:i:s.v') ?? '—',
            'sequence' => $sample->sequence,
            'position_ok' => (bool) $sample->position_ok,
            'torque_ok' => (bool) $sample->torque_ok,
            'torque_s_label' => $this->torqueLabel($sample, 'S'),
            'torque_l_label' => $this->torqueLabel($sample, 'L'),
        ];
    }

    private function torqueLabel(RobotTelemetrySample $sample, string $axis): string
    {
        $value = $sample->torque_axes[$axis] ?? null;
        if (! $sample->torque_ok || $sample->torque_unit !== 'sdk_raw' || ! is_numeric($value) || ! is_finite((float) $value)) {
            return '—';
        }

        return rtrim(rtrim(number_format((float) $value, 4, ',', '.'), '0'), ',');
    }
}
