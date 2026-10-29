<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Robot;
use App\Models\RobotAlarmEvent;
use App\Models\RobotReducerMeasurement;
use App\Models\RobotThresholdEvent;
use App\Models\RobotThresholdRule;
use App\Services\Rms\AlarmTextParser;
use App\Services\Rms\CockpitEvidence;
use App\Services\Rms\ControllerStateTextReader;
use App\Services\Rms\JobTextParser;
use App\Services\Rms\OperatingTimeDisplay;
use App\Services\Rms\ServoCurrentMonitor;
use App\Support\RmsDatabase;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Read-only, same-origin Robot Cockpit snapshot for browser updates.
 * No YMConnect calls, no controller commands, and no fabricated values.
 */
final class CockpitLiveController extends Controller
{
    public function __invoke(Request $request, Robot $robot, OperatingTimeDisplay $timeDisplay, ControllerStateTextReader $stateReader, AlarmTextParser $alarmParser, JobTextParser $jobParser, CockpitEvidence $evidence, ServoCurrentMonitor $currentMonitor): JsonResponse
    {
        $validated = $request->validate(['period' => ['sometimes', 'in:1h,8h,24h']]);
        $period = $validated['period'] ?? '8h';
        $seconds = ['1h' => 3600, '8h' => 28800, '24h' => 86400][$period];
        $bucket = (int) ceil($seconds / 300);
        $now = now();
        $axes = ['S', 'L', 'U', 'R', 'B', 'T'];

        $latest = $robot->telemetrySamples()->orderByDesc('received_at')->orderByDesc('id')->first();
        $freshnessAt = $latest?->received_at ?? $latest?->sampled_at;
        $fresh = $latest !== null && $freshnessAt !== null
            && $freshnessAt->betweenIncluded($now->copy()->subSeconds(30), $now->copy()->addSeconds(5))
            && ($latest->position_ok || $latest->torque_ok);

        // Match the initial cockpit page's SQL Server/PostgreSQL sample selection.
        // Retain the same time window, latest-300 limit and failed-read gaps.
        $rows = RmsDatabase::cockpitSamples(
            (int) $robot->id,
            $bucket,
            $now->copy()->subSeconds($seconds),
            $now->copy()->addSeconds(5),
        );

        $series = $rows->map(static function ($row) use ($axes): array {
            $raw = is_string($row->torque_axes) ? json_decode($row->torque_axes, true) : $row->torque_axes;
            $values = [];
            foreach ($axes as $axis) {
                $value = is_array($raw) ? ($raw[$axis] ?? null) : null;
                $values[$axis] = $row->torque_ok && $row->torque_unit === 'sdk_raw'
                    && is_numeric($value) && is_finite((float) $value) ? (float) $value : null;
            }

            return [
                'time' => CarbonImmutable::parse($row->sampled_at)->timezone('Asia/Jakarta')->format('d/m H:i:s'),
                'axes' => $values,
            ];
        })->all();

        $rules = RobotThresholdRule::query()->where('robot_id', $robot->id)->get()->keyBy('axis');
        $configuredRules = [];
        foreach ($axes as $axis) {
            $rule = $rules->get($axis);
            $approved = $rule && $rule->isApproved() && $rule->unit === 'sdk_raw';
            $configuredRules[$axis] = [
                'approved' => (bool) $approved,
                'warning' => $approved ? (float) $rule->warning_magnitude : null,
                'critical' => $approved ? (float) $rule->critical_magnitude : null,
                'hysteresis_percent' => $approved ? (float) $rule->clear_hysteresis_percent : null,
            ];
        }

        $axisStatus = [];
        foreach ($axes as $axis) {
            $rule = $rules->get($axis);
            $raw = $latest?->torque_axes[$axis] ?? null;
            $hasTorque = $fresh && $latest->torque_ok && $latest->torque_unit === 'sdk_raw'
                && is_numeric($raw) && is_finite((float) $raw);
            $approved = $rule && $rule->isApproved() && $rule->unit === 'sdk_raw';
            $status = ! $hasTorque ? 'unavailable' : ($approved ? 'normal' : 'not_configured');
            if ($hasTorque && $approved) {
                $magnitude = abs((float) $raw);
                if ($magnitude >= (float) $rule->critical_magnitude) {
                    $status = 'critical';
                } elseif ($magnitude >= (float) $rule->warning_magnitude) {
                    $status = 'warning';
                }
            }
            $pulse = $fresh && $latest->position_ok && is_array($latest->position_pulses)
                ? ($latest->position_pulses[$axis] ?? null) : null;
            $axisStatus[$axis] = [
                'value' => $hasTorque ? (float) $raw : null,
                'pulse' => is_numeric($pulse) && is_finite((float) $pulse) ? (float) $pulse : null,
                'status' => $status,
                'warning' => $approved ? (float) $rule->warning_magnitude : null,
                'critical' => $approved ? (float) $rule->critical_magnitude : null,
                'source' => $approved ? $rule->source_reference : null,
            ];
        }

        $operating = $robot->operatingTimeSamples()
            ->where('time_type', 'ServoPowerOnTimeRobot')->orderByDesc('sampled_at')->orderByDesc('id')->first();
        $operatingDisplay = $timeDisplay->forSample($operating);
        $operatingHours = $operating && $operating->read_ok && $operating->value_seconds !== null
            ? round($operating->value_seconds / 3600, 2) : null;
        $alarmSnapshot = $robot->alarmSnapshots()->orderByDesc('sampled_at')->first();
        $alarmParsed = $alarmSnapshot && $alarmSnapshot->read_ok
            ? $alarmParser->parse($alarmSnapshot->sdk_text)
            : ['recognized' => false, 'reported_count' => null, 'complete' => false, 'alarms' => []];
        $alarmValues = is_array($alarmSnapshot?->parsed_alarms)
            ? $alarmSnapshot->parsed_alarms
            : ($alarmParsed['recognized'] ? $alarmParsed['alarms'] : null);
        $jobEvidence = $jobParser->parse($latest?->raw_payload, $latest?->job_name);
        $jobEvidence['fresh'] = (bool) $fresh;
        if (! $fresh) {
            $jobEvidence['name'] = null;
            $jobEvidence['source'] = null;
        }
        $controllerState = $fresh ? $stateReader->latest($robot) : null;
        $jobDetail = $evidence->job($robot, (bool) $fresh);
        $thresholdCount = RobotThresholdEvent::query()->where('robot_id', $robot->id)->where('status', 'open')->count();
        $structuredAlarmCount = RobotAlarmEvent::query()->where('robot_id', $robot->id)->where('status', 'active')->count();
        $thresholdEvents = RobotThresholdEvent::query()->where('robot_id', $robot->id)
            ->orderByDesc('started_at')->limit(30)->get()->map(static fn ($e): array => [
                'time' => $e->started_at?->timezone('Asia/Jakarta')->format('d M H:i:s'),
                'axis' => $e->axis,
                'severity' => $e->severity,
                'status' => $e->status,
                'peak_magnitude' => $e->peak_magnitude === null ? null : (float) $e->peak_magnitude,
            ])->all();

        $reducerRows = RobotReducerMeasurement::query()->where('robot_id', $robot->id)
            ->where('is_verified', true)->orderByDesc('measured_at')->limit(300)->get()->groupBy('axis');
        $reducerTrend = [];
        $reducerLatest = [];
        foreach ($axes as $axis) {
            $values = $reducerRows->get($axis, collect());
            $latestReducer = $values->first();
            $measurements = $values->filter(fn ($m) => $m->condition_value !== null && filled($m->condition_unit))
                ->reverse()->values();
            $unit = $measurements->last()?->condition_unit;
            $reducerTrend[$axis] = [
                'unit' => $unit,
                'points' => $measurements->filter(fn ($m) => $m->condition_unit === $unit)
                    ->map(fn ($m) => [
                        'time' => $m->measured_at?->timezone('Asia/Jakarta')->format('d/m/Y'),
                        'value' => (float) $m->condition_value,
                    ])->values()->all(),
            ];
            $reducerLatest[$axis] = $latestReducer ? [
                'measured_at' => $latestReducer->measured_at?->timezone('Asia/Jakarta')->format('d M Y'),
                'condition_value' => $latestReducer->condition_value,
                'condition_unit' => $latestReducer->condition_unit,
                'remaining_life_hours' => $latestReducer->remaining_life_hours,
                'lower_threshold' => $latestReducer->lower_threshold,
                'upper_threshold' => $latestReducer->upper_threshold,
                'source_reference' => $latestReducer->source_reference,
                'source_type' => $latestReducer->source_type,
            ] : null;
        }

        $response = response()->json([
            'servoCurrent' => $currentMonitor->build($robot, $latest, $rows),
            'robot_id' => $robot->id,
            'period' => $period,
            'server_time' => $now->toIso8601String(),
            'sample_id' => $latest?->id,
            'last_sampled_wib' => $latest?->sampled_at?->timezone('Asia/Jakarta')->format('d M Y H:i:s'),
            'fresh' => (bool) $fresh,
            'operating_hours' => $operatingHours,
            'operating_candidate_hours' => $operatingDisplay['candidate_hours'],
            'operating_display' => $operatingDisplay,
            'operating_source' => $operatingHours === null ? 'sdk_text_unverified' : 'verified_elapsed_time',
            'job_name' => $jobEvidence['name'],
            'job_evidence' => $jobEvidence,
            'controller_state' => $controllerState,
            'job_detail' => $jobDetail,
            'controller_identity' => $evidence->identity($robot),
            'operating_counters' => $evidence->counters($robot),
            'alarm_raw' => [
                'reported_count' => $alarmSnapshot?->read_ok
                    ? ($alarmSnapshot->active_alarm_count ?? ($alarmParsed['recognized'] ? $alarmParsed['reported_count'] : null))
                    : null,
                'identified_count' => is_array($alarmValues) ? count($alarmValues) : null,
                'parse_state' => ! $alarmSnapshot || ! $alarmSnapshot->read_ok ? 'read_failed'
                    : (! $alarmParsed['recognized'] ? 'unrecognized'
                        : ($alarmParsed['complete'] ? 'complete' : 'partial')),
                'parse_complete' => $alarmParsed['recognized'] && $alarmParsed['complete'],
                'parse_persisted' => $alarmSnapshot?->parsed_alarms !== null,
                'alarms' => $alarmValues,
                'sampled_at' => $alarmSnapshot?->sampled_at?->toIso8601String(),
            ],
            'open_threshold_count' => $thresholdCount,
            'active_structured_alarm_count' => $structuredAlarmCount,
            'axisStatus' => $axisStatus,
            'configuredRules' => $configuredRules,
            'series' => $series,
            'thresholdEvents' => $thresholdEvents,
            'reducerTrend' => $reducerTrend,
            'reducerLatest' => $reducerLatest,
            'quality' => [
                'approved_rules' => $rules->filter(fn ($r) => $r->isApproved())->count(),
                'verified_reducers' => count(array_filter($reducerLatest, static fn ($r) => $r !== null)),
            ],
        ]);

        return $response->header('Cache-Control', 'no-store, private')
            ->header('X-Content-Type-Options', 'nosniff');
    }
}
