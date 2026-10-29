<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Robot;
use App\Models\RobotAlarmEvent;
use App\Models\RobotAlarmSnapshot;
use App\Models\RobotConnectionEvent;
use App\Models\RobotHealthAssessment;
use App\Models\RobotOperatingTimeSample;
use App\Models\RobotTelemetrySample;
use App\Services\Rms\OperatingTimeDisplay;
use App\Services\Rms\RobotMonitorDisplay;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Shared view helpers for an RMS running on SQL Server or PostgreSQL.
 * Only reads already-ingested data. No YMConnect calls and no synthetic telemetry.
 */
abstract class BaseRmsWebController extends Controller
{
    protected function robotRegistry(): EloquentCollection
    {
        return Robot::query()
            ->with('controller')
            ->orderBy('robot_code')
            ->get();
    }

    /**
     * Fetches the newest matching sample per robot using portable window SQL.
     * Ties are resolved by id. $model/$column/$equals keys are internal literals.
     *
     * @param  class-string<Model>  $model
     */
    protected function latestPerRobot(string $model, Collection $robotIds, string $column, array $equals = []): Collection
    {
        if ($robotIds->isEmpty()) {
            return collect();
        }

        $instance = new $model;
        $table = $instance->getTable();
        $grammar = $instance->getConnection()->getQueryGrammar();

        // Filter BEFORE ranking so each time_type keeps its own latest sample.
        // Only internal model/column names are used in this SQL expression.
        $ranked = $model::query()
            ->select($table.'.id')
            ->selectRaw(sprintf(
                'ROW_NUMBER() OVER (PARTITION BY %s ORDER BY %s DESC, %s DESC) AS %s',
                $grammar->wrap($table.'.robot_id'),
                $grammar->wrap($table.'.'.$column),
                $grammar->wrap($table.'.id'),
                $grammar->wrap('rms_latest_row'),
            ))
            ->whereIn($table.'.robot_id', $robotIds->all());

        foreach ($equals as $key => $value) {
            $ranked->where($table.'.'.$key, $value);
        }

        // Keep ranking inside a subquery: window results cannot be filtered in
        // the same SELECT. This remains one database statement, not one per robot.
        $latestIds = $instance->getConnection()->query()
            ->fromSub($ranked, 'rms_ranked_samples')
            ->select('id')
            ->where('rms_latest_row', 1);

        // Fetch full Eloquent models so datetime, boolean and JSON casts survive;
        // the temporary ranking column never becomes part of the model data.
        return $model::query()
            ->whereIn($table.'.id', $latestIds)
            ->orderBy($table.'.robot_id')
            ->orderByDesc($table.'.'.$column)
            ->orderByDesc($table.'.id')
            ->get()
            ->keyBy('robot_id');
    }

    /**
     * Produces view-ready robot cards with explicit data availability.
     * Collector connectivity is NOT physical controller RUN/IDLE state.
     */
    protected function robotCards(EloquentCollection $robots): Collection
    {
        $ids = $robots->pluck('id');
        // Ingestion order is authoritative for live status. A controller clock
        // can drift, but received_at is written by Laravel at ingest time.
        $telemetry = $this->latestPerRobot(RobotTelemetrySample::class, $ids, 'received_at');
        $operating = $this->latestPerRobot(RobotOperatingTimeSample::class, $ids, 'sampled_at', [
            'time_type' => 'ServoPowerOnTimeRobot',
        ]);
        $controllerOperating = $this->latestPerRobot(RobotOperatingTimeSample::class, $ids, 'sampled_at', [
            'time_type' => 'ControllerOnTime',
        ]);
        $alarms = $this->latestPerRobot(RobotAlarmSnapshot::class, $ids, 'sampled_at');
        $connections = $this->latestPerRobot(RobotConnectionEvent::class, $ids, 'occurred_at');
        $health = $this->latestPerRobot(RobotHealthAssessment::class, $ids, 'evaluated_at');
        $activeEvents = $ids->isEmpty() ? collect() : RobotAlarmEvent::query()
            ->whereIn('robot_id', $ids->all())
            ->where('status', 'active')
            ->selectRaw('robot_id, COUNT(*) as total')
            ->groupBy('robot_id')
            ->pluck('total', 'robot_id');

        $staleSeconds = max(5, (int) config('rms.telemetry_stale_seconds', 30));
        $now = now();
        $timeDisplay = app(OperatingTimeDisplay::class);
        $monitorDisplay = app(RobotMonitorDisplay::class);

        return $robots->map(static function (Robot $robot) use (
            $telemetry, $operating, $controllerOperating, $alarms, $connections, $health, $activeEvents, $now, $staleSeconds, $timeDisplay, $monitorDisplay
        ): array {
            $sample = $telemetry->get($robot->id);
            $time = $operating->get($robot->id);
            $alarm = $alarms->get($robot->id);
            $connection = $connections->get($robot->id);
            $assessment = $health->get($robot->id);

            $freshnessAt = $sample?->received_at ?? $sample?->sampled_at;
            $recent = $freshnessAt !== null
                && $freshnessAt->betweenIncluded(
                    $now->copy()->subSeconds($staleSeconds),
                    $now->copy()->addSeconds(10)
                );

            // Fresh packets can establish data availability, not robot motion.
            $freshGoodRead = $recent && ($sample->position_ok || $sample->torque_ok)
                && $sample->sampled_at?->betweenIncluded($now->copy()->subSeconds(60), $now->copy()->addSeconds(5))
                && data_get($sample->raw_payload, 'simulated') !== true;
            $explicitOffline = $connection !== null
                && in_array($connection->state, ['offline', 'reconnecting', 'stopped'], true)
                && ($freshnessAt === null || $connection->occurred_at >= $freshnessAt);

            $connectivity = $explicitOffline ? 'offline' : (
                $freshGoodRead ? 'connected' : ($sample !== null ? 'stale' : 'unknown')
            );

            $structuredActive = (int) ($activeEvents->get($robot->id) ?? 0);
            $alarmCount = $structuredActive > 0 ? $structuredActive : (
                $alarm !== null && $alarm->read_ok && $alarm->active_alarm_count !== null
                    ? (int) $alarm->active_alarm_count
                    : null
            );

            // value_seconds is populated only after its actual SDK unit was verified.
            $operatingHours = $time !== null && $time->read_ok && $time->value_seconds !== null
                ? round($time->value_seconds / 3600, 2)
                : null;
            $operatingDisplay = $timeDisplay->forSample($time);

            return [
                'robot' => $robot,
                'controller' => $robot->controller,
                'telemetry' => $sample,
                'operating_time' => $time,
                'alarm_snapshot' => $alarm,
                'connection_event' => $connection,
                'health_assessment' => $assessment,
                'connectivity' => $connectivity,
                'telemetry_fresh' => (bool) ($freshGoodRead && $connectivity === 'connected'),
                'last_sampled_at' => $sample?->sampled_at,
                'last_received_at' => $sample?->received_at,
                'active_alarm_count' => $alarmCount,
                'operating_hours' => $operatingHours,
                'operating_display' => $operatingDisplay,
                'control_power_display' => $timeDisplay->forSample($controllerOperating->get($robot->id), 'ControllerOnTime'),
                ...$monitorDisplay->forRobot($robot, $sample, $assessment, $connectivity === 'connected'),
                'motion_state' => null,
                'executing_job' => null,
                'utilization_percent' => null,
            ];
        });
    }
}
