<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Robot;
use App\Models\RobotReducerMeasurement;
use App\Models\RobotThresholdEvent;
use App\Models\RobotThresholdRule;
use App\Services\Rms\CockpitEvidence;
use App\Services\Rms\ControllerStateTextReader;
use App\Services\Rms\OperatingTimeDisplay;
use App\Services\Rms\ServoCurrentMonitor;
use App\Support\RmsDatabase;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/** Blade-only, read-only cockpit page. Does not impersonate OEM health algorithms. */
class CockpitController extends Controller
{
    public function show(Request $request, Robot $robot, OperatingTimeDisplay $timeDisplay,
        CockpitEvidence $evidence, ControllerStateTextReader $stateReader, ServoCurrentMonitor $currentMonitor): View
    {
        $validated = $request->validate(['period' => ['sometimes', 'in:1h,8h,24h']]);
        $period = $validated['period'] ?? '8h';
        $seconds = ['1h' => 3600, '8h' => 28800, '24h' => 86400][$period];
        $bucket = (int) ceil($seconds / 300); // <= 300 representative samples / selected period.

        $robot->load('controller');
        $latest = $robot->telemetrySamples()->orderByDesc('received_at')->orderByDesc('id')->first();
        $freshnessAt = $latest?->received_at ?? $latest?->sampled_at;
        $fresh = $latest && $freshnessAt &&
            $freshnessAt->betweenIncluded(now()->subSeconds(30), now()->addSeconds(5)) &&
            ($latest->torque_ok || $latest->position_ok);

        // Use the shared SQL Server/PostgreSQL query: one actual read per bucket.
        // The helper limits the result to 300 points, keeps timestamp/id tie-breaking,
        // and leaves failed torque reads visible as gaps instead of filling them in.
        $samples = RmsDatabase::cockpitSamples(
            (int) $robot->id,
            $bucket,
            now()->subSeconds($seconds),
            now()->addSeconds(5),
        );
        $series = $samples->map(static function ($sample): array {
            $data = is_string($sample->torque_axes) ? json_decode($sample->torque_axes, true) : $sample->torque_axes;
            $axes = [];
            foreach (['S', 'L', 'U', 'R', 'B', 'T'] as $axis) {
                $val = is_array($data) ? ($data[$axis] ?? null) : null;
                $axes[$axis] = $sample->torque_ok && $sample->torque_unit === 'sdk_raw' && is_numeric($val)
                    && is_finite((float) $val) ? (float) $val : null;
            }

            return ['time' => CarbonImmutable::parse($sample->sampled_at)->timezone('Asia/Jakarta')->format('d/m H:i:s'), 'axes' => $axes];
        })->all();

        $rules = RobotThresholdRule::query()->where('robot_id', $robot->id)->orderBy('axis')->get()->keyBy('axis');
        $openEvents = RobotThresholdEvent::query()->where('robot_id', $robot->id)
            ->where('status', 'open')->orderByDesc('started_at')->limit(20)->get();
        $eventHistory = RobotThresholdEvent::query()->where('robot_id', $robot->id)
            ->orderByDesc('started_at')->limit(30)->get();

        $axisStatus = [];
        foreach (['S', 'L', 'U', 'R', 'B', 'T'] as $axis) {
            $rule = $rules->get($axis);
            $raw = $latest?->torque_axes[$axis] ?? null;
            $hasReading = $fresh && $latest->torque_ok && $latest->torque_unit === 'sdk_raw'
                && is_numeric($raw) && is_finite((float) $raw);
            $active = $rule && $rule->isApproved() && $rule->unit === 'sdk_raw';
            $state = ! $hasReading ? 'unavailable' : (! $active ? 'not_configured' : 'normal');
            if ($hasReading && $active) {
                // UI shows instant reading plus open event status; CLI uses 3 reads with hysteresis.
                $magnitude = abs((float) $raw);
                if ($magnitude >= (float) $rule->critical_magnitude) {
                    $state = 'critical';
                } elseif ($magnitude >= (float) $rule->warning_magnitude) {
                    $state = 'warning';
                }
            }
            $axisStatus[$axis] = [
                'value' => $hasReading ? (float) $raw : null,
                'pulse' => $fresh && $latest->position_ok && is_array($latest->position_pulses)
                    ? ($latest->position_pulses[$axis] ?? null) : null,
                'status' => $state,
                'warning' => $active ? (float) $rule->warning_magnitude : null,
                'critical' => $active ? (float) $rule->critical_magnitude : null,
                'source' => $active ? $rule->source_reference : null,
            ];
        }

        $reducerRows = RobotReducerMeasurement::query()->where('robot_id', $robot->id)
            ->where('is_verified', true)->orderByDesc('measured_at')->limit(300)->get()
            ->groupBy('axis');
        $reducerByAxis = collect(['S', 'L', 'U', 'R', 'B', 'T'])->mapWithKeys(
            fn ($axis) => [$axis => $reducerRows->get($axis)?->first()]
        );
        $reducerTrend = collect(['S', 'L', 'U', 'R', 'B', 'T'])->mapWithKeys(function ($axis) use ($reducerRows) {
            $measurements = ($reducerRows->get($axis) ?? collect())->filter(
                fn ($m) => $m->condition_value !== null && filled($m->condition_unit)
            )->reverse()->values();
            // Different units are never drawn in one graph.
            $unit = $measurements->last()?->condition_unit;

            return [$axis => [
                'unit' => $unit,
                'points' => $measurements->filter(fn ($m) => $m->condition_unit === $unit)
                    ->map(fn ($m) => ['time' => $m->measured_at?->timezone('Asia/Jakarta')->format('d/m/Y'),
                        'value' => (float) $m->condition_value])->values()->all(),
            ]];
        });

        $operating = $robot->operatingTimeSamples()->where('time_type', 'ServoPowerOnTimeRobot')
            ->orderByDesc('sampled_at')->orderByDesc('id')->first();
        $operatingDisplay = $timeDisplay->forSample($operating);
        $controllerState = $fresh ? $stateReader->latest($robot) : null;
        $jobDetail = $evidence->job($robot, (bool) $fresh);
        $controllerIdentity = $evidence->identity($robot);
        $operatingCounters = $evidence->counters($robot);

        $servoCurrent = $currentMonitor->build($robot, $latest, $samples);
        $cockpitPayload = [
            'servoCurrent' => $servoCurrent,
            'operating_display' => $operatingDisplay,
            'series' => $series,
            'axes' => ['S', 'L', 'U', 'R', 'B', 'T'],
            'axisStatus' => $axisStatus,
            'reducerTrend' => $reducerTrend->all(),
            'reducerLatest' => $reducerByAxis->map(fn ($x) => $x ? [
                'measured_at' => $x->measured_at?->timezone('Asia/Jakarta')->format('d M Y'),
                'condition_value' => $x->condition_value,
                'condition_unit' => $x->condition_unit,
                'remaining_life_hours' => $x->remaining_life_hours,
                'lower_threshold' => $x->lower_threshold,
                'upper_threshold' => $x->upper_threshold,
                'source_reference' => $x->source_reference,
                'source_type' => $x->source_type,
            ] : null)->all(),
        ];

        return view('rms.robots.cockpit', [
            'cockpitPayload' => $cockpitPayload,
            'servoCurrent' => $servoCurrent,
            'title' => 'Robot Cockpit — '.$robot->name, 'robot' => $robot, 'period' => $period,
            'fresh' => (bool) $fresh, 'latest' => $latest, 'axisStatus' => $axisStatus,
            'series' => $series, 'rules' => $rules, 'openEvents' => $openEvents,
            'eventHistory' => $eventHistory, 'reducerByAxis' => $reducerByAxis,
            'reducerTrend' => $reducerTrend,
            'operating' => $operating,
            'operatingDisplay' => $operatingDisplay,
            'controllerState' => $controllerState,
            'jobDetail' => $jobDetail,
            'controllerIdentity' => $controllerIdentity,
            'operatingCounters' => $operatingCounters,
            'alarms' => $robot->alarmEvents()->where('status', 'active')->latest('started_at')->limit(10)->get(),
        ]);
    }
}
