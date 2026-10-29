<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Models\Robot;
use App\Models\RobotTelemetrySample;
use App\Services\Rms\ObservedOperationRate;
use App\Support\RmsDatabase;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use LogicException;

class AnalyticsController extends BaseRmsWebController
{
    public function index(Request $request, ObservedOperationRate $operationRate): View
    {
        $validated = $request->validate([
            'period' => ['sometimes', 'in:24h,7d'],
        ]);
        $period = $validated['period'] ?? '24h';
        $until = CarbonImmutable::now();
        $since = $period === '7d' ? $until->subDays(7) : $until->subDay();

        // SQL Server uses CASE/SUM for conditional counts; PostgreSQL supports FILTER.
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
        $localDay = RmsDatabase::jakartaDay();

        // Only data quality. These samples do NOT contain RUN/IDLE state intervals.
        $perRobot = RobotTelemetrySample::query()
            ->where('sampled_at', '>=', $since)
            ->selectRaw('robot_id, COUNT(*) AS total_samples')
            ->selectRaw("$positionCount AS valid_position")
            ->selectRaw("$torqueCount AS valid_torque")
            ->groupBy('robot_id')
            ->get()
            ->keyBy('robot_id');

        // Cap to 7 days; group by Jakarta calendar dates for consistent display.
        $daily = DB::table('robot_telemetry_samples')
            ->where('sampled_at', '>=', $since)
            ->selectRaw("$localDay AS local_day")
            ->selectRaw('COUNT(*) AS total_samples')
            ->selectRaw("$positionCount AS valid_position")
            ->selectRaw("$torqueCount AS valid_torque")
            ->groupByRaw($localDay)
            ->orderBy('local_day')
            ->get();

        $robots = Robot::query()->orderBy('robot_code')->get();
        $operation = $robots->mapWithKeys(fn (Robot $robot): array => [
            $robot->id => $operationRate->forRobot((int) $robot->id, $since, $until),
        ]);

        return view('rms.analytics.index', [
            'title' => 'Data Quality & Analytics',
            'robots' => $robots,
            'operation' => $operation,
            'perRobot' => $perRobot,
            'daily' => $daily,
            'period' => $period,
            'since' => $since,
            'metrics' => [
                'total_samples' => $perRobot->sum('total_samples'),
                'valid_position_samples' => $perRobot->sum('valid_position'),
                'valid_torque_samples' => $perRobot->sum('valid_torque'),
                'utilization_percent' => null,
                'downtime_minutes' => null,
                'running_minutes' => null,
            ],
            'unavailableReason' => 'Jadwal shift, planned downtime, dan semantik produksi belum dikonfigurasi.',
        ]);
    }
}

