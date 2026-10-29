<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Models\MaintenanceRecommendation;
use App\Models\Robot;
use App\Models\RobotAlarmEvent;
use App\Models\RobotThresholdRule;
use App\Services\Rms\TelemetryHistory;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Same-origin read-only snapshot. Never calls YMConnect or robot commands. */
final class RmsLiveController extends BaseRmsWebController
{
    public function snapshot(Request $request, TelemetryHistory $history): JsonResponse
    {
        abort_unless(config('rms_live.enabled', false), 404);
        $values = $request->validate([
            'robot_id' => ['required_if:include_history,1', 'integer', 'min:1', 'exists:robots,id'],
            'include_history' => ['sometimes', 'boolean'],
        ]);
        $registry = isset($values['robot_id'])
            ? new EloquentCollection([Robot::query()->with('controller')->findOrFail($values['robot_id'])])
            : $this->robotRegistry();
        $cards = $this->robotCards($registry);
        $now = now();
        $staleSeconds = config('rms_live.stale_seconds', 30);
        $ruleRows = RobotThresholdRule::query()
            ->whereIn('robot_id', $registry->pluck('id')->all())
            ->get()
            ->groupBy('robot_id');
        $robots = $cards->map(function (array $card) use ($now, $staleSeconds, $ruleRows): array {
            $robot = $card['robot'];
            $sample = $card['telemetry'];
            $last = $card['health_assessment'];
            $freshnessAt = $sample?->received_at ?? $sample?->sampled_at;
            $isFresh = $freshnessAt?->betweenIncluded(
                $now->copy()->subSeconds($staleSeconds), $now->copy()->addSeconds(5)
            ) && ($sample->torque_ok || $sample->position_ok);
            $connected = $card['connectivity'] === 'connected' && $isFresh && $card['telemetry_fresh'];
            $rules = $ruleRows->get($robot->id, collect())->keyBy('axis');
            $health = $card['health_display']['status'];
            $axes = [];
            foreach (['S', 'L', 'U', 'R', 'B', 'T'] as $axis) {
                $raw = $connected && $sample?->torque_ok && $sample->torque_unit === 'sdk_raw'
                    ? ($sample->torque_axes[$axis] ?? null) : null;
                $value = is_numeric($raw) && is_finite((float) $raw) ? (float) $raw : null;
                $pulse = $connected && $sample?->position_ok ? ($sample->position_pulses[$axis] ?? null) : null;
                $rule = $rules->get($axis);
                $validRule = $rule && $rule->isApproved() && $rule->unit === 'sdk_raw';
                $state = $value === null ? 'unavailable' : ($validRule ? 'normal' : 'not_configured');
                if ($value !== null && $validRule) {
                    $state = abs($value) >= (float) $rule->critical_magnitude ? 'critical' :
                        (abs($value) >= (float) $rule->warning_magnitude ? 'warning' : 'normal');
                }
                $axes[$axis] = [
                    'torque' => $value,
                    'pulse' => is_numeric($pulse) ? (float) $pulse : null,
                    'status' => $state,
                    'warning' => $validRule ? (float) $rule->warning_magnitude : null,
                    'critical' => $validRule ? (float) $rule->critical_magnitude : null,
                ];
            }
            $alarmCount = $connected ? $card['active_alarm_count'] : null;

            return [
                'id' => $robot->id,
                'code' => $robot->robot_code,
                'is_demo' => str_starts_with($robot->robot_code, 'DEMO_') || str_contains($robot->name, '[DEMO]') || data_get($sample?->raw_payload, 'simulated') === true,
                'connectivity' => $card['connectivity'],
                'last_sampled_at' => $sample?->sampled_at?->toIso8601String(),
                'last_sampled_wib' => $sample?->sampled_at?->timezone('Asia/Jakarta')->format('d M Y H:i:s'),
                'last_received_at' => $sample?->received_at?->toIso8601String(),
                'last_received_wib' => $sample?->received_at?->timezone('Asia/Jakarta')->format('d M Y H:i:s'),
                'sample_id' => $sample?->id,
                'torque_ok' => (bool) ($connected && $sample?->torque_ok),
                'position_ok' => (bool) ($connected && $sample?->position_ok),
                'torque_unit' => $sample?->torque_unit,
                'job_name' => $sample?->job_name ?: data_get($sample?->raw_payload, 'job.name'),
                'axes' => $axes,
                'health' => $health,
                'health_label' => $card['health_display']['label'],
                'health_reason' => $card['health_display']['reason'],
                'health_valid_until' => $card['health_display']['valid_until'],
                'servo' => $card['servo'],
                'home_position' => $card['home_position'],
                'identity' => ['serial_number' => $robot->serial_number, 'robot_type' => $robot->robot_type, 'nameplate_date' => $robot->nameplate_date],
                'control_power_display' => $card['control_power_display'],
                'last_health' => $last?->health_status,
                'last_health_at' => $last?->evaluated_at?->toIso8601String(),
                'alarm_count' => $alarmCount,
                'operating_hours' => $card['operating_hours'],
                'operating_display' => $card['operating_display'],
            ];
        })->values();

        $robotIds = $robots->pluck('id')->all();
        $response = response()->json([
            'server_time' => $now->toIso8601String(),
            'stale_seconds' => $staleSeconds,
            'summary' => [
                'total' => $robots->count(),
                'connected' => $robots->where('connectivity', 'connected')->count(),
                'offline' => $robots->where('connectivity', 'offline')->count(),
                'stale_or_unknown' => $robots->whereIn('connectivity', ['stale', 'unknown'])->count(),
                'identified_active_alarms' => RobotAlarmEvent::query()->whereIn('robot_id', $robotIds)->where('status', 'active')->count(),
                'open_recommendations' => MaintenanceRecommendation::query()->whereIn('robot_id', $robotIds)->where('status', 'open')->count(),
            ],
            'robots' => $robots,
            'telemetry_history' => ($values['include_history'] ?? false) ? [
                'robot_id' => $registry->first()->id,
                'limit' => TelemetryHistory::LIMIT,
                'rows' => $history->forRobot($registry->first()),
            ] : null,
        ]);

        return $response->header('Cache-Control', 'no-store, private')
            ->header('X-Content-Type-Options', 'nosniff');
    }
}
