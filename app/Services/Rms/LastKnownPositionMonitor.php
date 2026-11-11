<?php

declare(strict_types=1);

namespace App\Services\Rms;

use App\Models\Robot;
use App\Models\RobotTelemetrySample;
use Carbon\CarbonInterface;

/**
 * Read-only last successful YRC1000 pulse observation.
 * Not a command, a calibrated pose, or evidence of the current physical location.
 * Separate from HomePositionMonitor; a home reference is NOT required.
 */
final class LastKnownPositionMonitor
{
    public const AXES = ['S', 'L', 'U', 'R', 'B', 'T'];

    public function forRobot(Robot $robot, bool $connected, ?int $currentSampleId = null): array
    {
        $emptyAxes = array_fill_keys(self::AXES, null);
        $result = [
            'status' => 'no_data',
            'label' => 'BELUM ADA DATA',
            'reason' => 'Belum ada pembacaan pulse enam axis yang berhasil dan valid.',
            'current_axes' => $emptyAxes,
            'last_axes' => $emptyAxes,
            'sample_id' => null,
            'sampled_at' => null,
            'sampled_wib' => null,
            'received_at' => null,
            'received_wib' => null,
            'age_seconds' => null,
            'is_fresh' => false,
        ];
        if ((int) $robot->axis_count !== 6) {
            return [...$result, 'status' => 'unsupported', 'label' => 'AXIS TIDAK DIDUKUNG',
                'reason' => 'Tampilan Last Position memerlukan robot dengan enam axis.'];
        }

        // A later failed-read sample must not overwrite the previous valid pose.
        // Limit candidate inspection to prevent scanning whole histories on each request.
        $candidates = $robot->telemetrySamples()
            ->where('position_ok', true)
            ->orderByDesc('received_at')
            ->orderByDesc('id')
            ->limit(200)
            ->get();

        $now = now();
        foreach ($candidates as $sample) {
            if (! $sample instanceof RobotTelemetrySample) {
                continue;
            }
            $axes = $this->validatedAxes($robot, $sample, $now);
            if ($axes === null) {
                continue;
            }

            $observedAt = $sample->position_sampled_at;
            $receivedAt = $sample->received_at;
            $staleSeconds = max(5, (int) config('rms.telemetry_stale_seconds', 30));
            $fresh = $connected && $currentSampleId !== null && (int) $sample->id === $currentSampleId
                && $receivedAt->betweenIncluded($now->copy()->subSeconds($staleSeconds), $now->copy()->addSeconds(5))
                && $sample->sampled_at->betweenIncluded($now->copy()->subSeconds(60), $now->copy()->addSeconds(5))
                && $observedAt->betweenIncluded($now->copy()->subSeconds(60), $now->copy()->addSeconds(5));

            return [
                'status' => $fresh ? 'live' : 'stale',
                'label' => $fresh ? 'LIVE POSITION' : 'LAST KNOWN / STALE',
                'reason' => $fresh
                    ? 'Posisi enam axis berhasil dibaca dan masih dalam batas waktu data segar.'
                    : 'Ini adalah posisi terakhir yang pernah terbaca; bukan bukti posisi fisik robot saat ini.',
                'current_axes' => $fresh ? $axes : $emptyAxes,
                'last_axes' => $axes,
                'sample_id' => (int) $sample->id,
                'sampled_at' => $observedAt->toIso8601String(),
                'sampled_wib' => $observedAt->copy()->timezone('Asia/Jakarta')->format('d M Y H:i:s'),
                'received_at' => $receivedAt->toIso8601String(),
                'received_wib' => $receivedAt->copy()->timezone('Asia/Jakarta')->format('d M Y H:i:s'),
                'age_seconds' => max(0, $now->getTimestamp() - $observedAt->getTimestamp()),
                'is_fresh' => $fresh,
            ];
        }

        return $result;
    }

    /** @return array<string,int>|null */
    private function validatedAxes(Robot $robot, RobotTelemetrySample $sample, CarbonInterface $now): ?array
    {
        if (! $sample->position_ok || ! $sample->sampled_at || ! $sample->position_sampled_at || ! $sample->received_at) {
            return null;
        }
        if ($sample->position_status_code !== null && (int) $sample->position_status_code !== 0) {
            return null;
        }
        if ($sample->sampled_at > $now->copy()->addSeconds(5)
            || $sample->position_sampled_at > $now->copy()->addSeconds(5)
            || $sample->received_at > $now->copy()->addSeconds(5)) {
            return null;
        }
        $raw = $sample->raw_payload;
        if (in_array(data_get($raw, 'simulated'), [true, 1, '1', 'true'], true)
            || data_get($raw, 'group') !== $robot->control_group
            || ! in_array(data_get($raw, 'position_unit'), ['pulse', 'pulses'], true)) {
            return null;
        }
        $values = $sample->position_pulses;
        if (! is_array($values)) {
            return null;
        }
        $axes = [];
        foreach (self::AXES as $axis) {
            $value = $values[$axis] ?? null;
            if (! is_numeric($value) || ! is_finite((float) $value)
                || floor((float) $value) !== (float) $value
                || (float) $value < -2147483648 || (float) $value > 2147483647) {
                return null;
            }
            $axes[$axis] = (int) $value;
        }
        return $axes;
    }
}
