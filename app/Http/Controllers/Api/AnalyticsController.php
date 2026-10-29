<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Models\Robot;
use App\Models\RobotTelemetrySample;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use LogicException;

/** Real data-quality metrics; utilization is intentionally unavailable until state intervals exist. */
class AnalyticsController extends BaseRmsController
{
    public function dataQuality(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
        ]);

        // Use aggregation syntax compatible with the selected database driver.
        $driver = DB::connection()->getDriverName();
        [$positionCount, $torqueCount] = match ($driver) {
            'sqlsrv' => [
                'SUM(CASE WHEN position_ok = 1 THEN 1 ELSE 0 END)',
                'SUM(CASE WHEN torque_ok = 1 THEN 1 ELSE 0 END)',
            ],
            'pgsql' => [
                'COUNT(*) FILTER (WHERE position_ok IS TRUE)',
                'COUNT(*) FILTER (WHERE torque_ok IS TRUE)',
            ],
            default => throw new LogicException('RMS analytics requires SQL Server or PostgreSQL.'),
        };
        $totals = RobotTelemetrySample::query()
            ->select('robot_id')
            ->selectRaw('COUNT(*) AS sample_count')
            ->selectRaw("$positionCount AS position_success_count")
            ->selectRaw("$torqueCount AS torque_success_count")
            ->whereBetween('sampled_at', [$filters['from'], $filters['to']])
            ->groupBy('robot_id')
            ->get()
            ->keyBy('robot_id');

        $perRobot = Robot::query()->orderBy('robot_code')->get(['id', 'robot_code', 'name'])
            ->map(function (Robot $robot) use ($totals): array {
                $row = $totals->get($robot->id);
                return [
                    'robot_id' => $robot->id,
                    'robot_code' => $robot->robot_code,
                    'sample_count' => (int) ($row?->sample_count ?? 0),
                    'position_success_count' => (int) ($row?->position_success_count ?? 0),
                    'torque_success_count' => (int) ($row?->torque_success_count ?? 0),
                ];
            });
        return response()->json([
            'period' => $filters,
            'robots' => $perRobot,
            'utilization_percent' => null,
            'downtime_minutes' => null,
            'limitation' => 'No measured RUN/IDLE/STOP interval history exists in the current migrations.',
        ]);
    }
}
