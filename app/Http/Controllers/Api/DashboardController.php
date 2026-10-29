<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Models\Robot;
use App\Models\RobotAlarmEvent;
use App\Models\MaintenancePlan;
use App\Models\MaintenanceRecommendation;
use Illuminate\Http\JsonResponse;

class DashboardController extends BaseRmsController
{
    /** Data-backed overview; 'running' and utilization are unavailable for now. */
    public function overview(): JsonResponse
    {
        $robots = Robot::query()->with('controller')->orderBy('robot_code')->get()
            ->map(fn (Robot $robot): array => $this->snapshot($robot));

        return response()->json([
            'data_source' => 'postgresql',
            'sampled_at' => now()->toIso8601String(),
            'summary' => [
                'total_robots' => $robots->count(),
                'active_robots' => $robots->where('is_active', true)->count(),
                'connected_collectors' => $robots->where('link_status', 'connected')->count(),
                'unknown_or_stale_connections' => $robots->whereIn('link_status', ['unknown', 'stale'])->count(),
                'identified_active_alarm_events' => RobotAlarmEvent::query()->where('status', 'active')->count(),
                'open_recommendations' => MaintenanceRecommendation::query()->where('status', 'open')->count(),
                'active_pm_plans' => MaintenancePlan::query()->where('is_active', true)->count(),
                'running_robots' => null,
                'idle_robots' => null,
                'utilization_percent' => null,
            ],
            'robots' => $robots,
            'availability' => [
                'running_idle_state' => false,
                'job_monitoring' => false,
                'io_monitoring' => false,
                'utilization' => false,
                'hardware_life_estimation' => false,
            ],
        ]);
    }
}
