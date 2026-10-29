<?php

declare(strict_types=1);

namespace App\Services\Rms;

use App\Models\RobotControllerStateSample;
use Carbon\CarbonImmutable;

/** Fraction of controller-observed time in RUN. Never counts missing data as downtime. */
final class ObservedOperationRate
{
    private const MAX_GAP_MS = 30_000;

    public function forRobot(int $robotId, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $fromMs = $from->getTimestampMs();
        $toMs = $to->getTimestampMs();
        $runMs = 0;
        $nonRunMs = 0;
        $observations = 0;
        $previous = RobotControllerStateSample::query()->where('robot_id', $robotId)
            ->where('sampled_at', '<', $from)->orderByDesc('sampled_at')->orderByDesc('id')->first();

        $rows = RobotControllerStateSample::query()->where('robot_id', $robotId)
            ->whereBetween('sampled_at', [$from, $to])
            ->orderBy('sampled_at')->orderBy('id')->cursor();
        foreach ($rows as $next) {
            if ($previous !== null) {
                $this->accumulate($previous, $next->sampled_at->getTimestampMs(), $fromMs, $toMs, $runMs, $nonRunMs);
            }
            $previous = $next;
            $observations++;
        }
        if ($previous !== null) {
            $this->accumulate($previous, $toMs, $fromMs, $toMs, $runMs, $nonRunMs);
        }

        $windowMs = max(0, $toMs - $fromMs);
        $observedMs = $runMs + $nonRunMs;

        return [
            'window_seconds' => round($windowMs / 1000, 1),
            'observed_seconds' => round($observedMs / 1000, 1),
            'run_seconds' => round($runMs / 1000, 1),
            'non_run_seconds' => round($nonRunMs / 1000, 1),
            'unknown_seconds' => round(max(0, $windowMs - $observedMs) / 1000, 1),
            'observations' => $observations,
            'coverage_percent' => $windowMs > 0 ? round(100 * $observedMs / $windowMs, 1) : null,
            'observed_run_percent' => $observedMs > 0 ? round(100 * $runMs / $observedMs, 1) : null,
            'definition' => 'RUN / (RUN + not RUN) during observed intervals; unknown gaps excluded',
        ];
    }

    private function accumulate(
        RobotControllerStateSample $state,
        int $nextMs,
        int $fromMs,
        int $toMs,
        int &$runMs,
        int &$nonRunMs
    ): void {
        $at = $state->sampled_at->getTimestampMs();
        $start = max($at, $fromMs);
        // Beyond 30 seconds, stale state expires; the remainder is UNKNOWN.
        $end = min($nextMs, $toMs, $at + self::MAX_GAP_MS);
        if ($end <= $start) {
            return;
        }
        if ($state->running && ! $state->hold && ! $state->alarm && ! $state->error) {
            $runMs += $end - $start;
        } else {
            $nonRunMs += $end - $start;
        }
    }
}
