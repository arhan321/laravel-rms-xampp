<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Models\MaintenancePlan;
use App\Models\MaintenanceRecommendation;
use App\Models\RobotAlarmEvent;
use App\Services\Rms\PlantOverview;
use App\Services\Rms\RobotLineGroups;
use Illuminate\Contracts\View\View;

class DashboardController extends BaseRmsWebController
{
    public function index(RobotLineGroups $lineGroups, PlantOverview $plantOverview): View
    {
        $robots = $this->robotCards($this->robotRegistry());
        $groups = $lineGroups->groups($robots);
        $summary = [
            'total_robots' => $robots->count(),
            'active_robots' => $robots->filter(fn (array $row): bool => $row['robot']->is_active)->count(),
            'connected_collectors' => $robots->where('connectivity', 'connected')->count(),
            'offline_collectors' => $robots->where('connectivity', 'offline')->count(),
            'stale_or_unknown' => $robots->whereIn('connectivity', ['stale', 'unknown'])->count(),
            'identified_active_alarms' => RobotAlarmEvent::query()->where('status', 'active')->count(),
            'active_maintenance_plans' => MaintenancePlan::query()->where('is_active', true)->count(),
            'open_recommendations' => MaintenanceRecommendation::query()->where('status', 'open')->count(),
            'running_robots' => null,
            'idle_robots' => null,
            'utilization_percent' => null,
        ];

        $attention = [
            'alarms' => RobotAlarmEvent::query()
                ->with('robot')
                ->where('status', 'active')
                ->orderByDesc('started_at')
                ->limit(5)
                ->get(),
            'connections' => $robots->whereIn('connectivity', ['offline', 'stale'])->values(),
            'upcoming_maintenance' => MaintenancePlan::query()
                ->with('robot')
                ->where('is_active', true)
                ->whereNotNull('next_due_at')
                ->orderBy('next_due_at')
                ->limit(5)
                ->get(),
        ];

        return view('rms.overview', [
            'title' => 'Line Overview',
            'robots' => $robots,
            'groups' => $groups,
            'plantOverview' => $plantOverview->forCards($robots, $groups),
            'fleetSummary' => $lineGroups->summary($robots),
            'groupWritesEnabled' => (bool) config('rms_robot_group_management.writes_enabled', false),
            'summary' => $summary,
            'attention' => $attention,
            'asOf' => now(),
            'dataSource' => 'postgresql',
            'missingCapabilities' => ['motion_state', 'job', 'io', 'utilization', 'downtime'],
        ]);
    }
}
