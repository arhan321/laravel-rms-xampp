<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Robot;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Database\Eloquent\Builder;
use App\Services\Rms\AlarmTextParser;
use App\Services\Rms\JobTextParser;
use App\Services\Rms\OperatingTimeTextParser;

/** Shared read helpers; no controller commands or assumed robot-health values. */
abstract class BaseRmsController extends Controller
{
    protected function queryPeriod(Request $request): array
    {
        return $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
    }

    protected function applyPeriod(Builder $query, array $filters, string $column): Builder
    {
        if (!empty($filters['from'])) {
            $query->where($column, '>=', $filters['from']);
        }
        if (!empty($filters['to'])) {
            $query->where($column, '<=', $filters['to']);
        }
        return $query;
    }

    /**
     * Require timezone-qualified timestamps. The collector currently sends UTC
     * values ending in Z; naive values would depend on the server timezone.
     */
    protected function timestampTimezoneError(array $data, array $fields): ?JsonResponse
    {
        $errors = [];
        foreach ($fields as $field) {
            $value = data_get($data, $field);
            if ($value === null || $value === '') {
                continue;
            }
            if (!is_string($value) || !preg_match('/(?:Z|[+-]\d{2}:\d{2})$/i', trim($value))) {
                $errors[$field][] = 'Timestamp harus menyertakan zona waktu, misalnya 2026-10-17T12:00:00.000Z.';
            }
        }

        return $errors === [] ? null : response()->json([
            'message' => 'Timestamp collector harus memiliki zona waktu eksplisit.',
            'errors' => $errors,
        ], 422);
    }

    protected function snapshot(Robot $robot): array
    {
        $robot->loadMissing('controller');

        $telemetry = $robot->telemetrySamples()->orderByDesc('received_at')->orderByDesc('id')->first();
        $operating = $robot->operatingTimeSamples()
            ->where('time_type', 'ServoPowerOnTimeRobot')
            ->orderByDesc('sampled_at')->first();
        $alarm = $robot->alarmSnapshots()->orderByDesc('sampled_at')->first();
        $connection = $robot->connectionEvents()->orderByDesc('occurred_at')->first();
        $health = $robot->healthAssessments()->orderByDesc('evaluated_at')->first();

        $jobEvidence = (new JobTextParser())->parse($telemetry?->raw_payload, $telemetry?->job_name);
        $alarmParse = $alarm && $alarm->read_ok
            ? (new AlarmTextParser())->parse($alarm->sdk_text)
            : ['recognized' => false, 'reported_count' => null, 'complete' => false, 'alarms' => []];
        $alarmEvidence = $alarm?->parsed_alarms
            ?? ($alarmParse['recognized'] ? $alarmParse['alarms'] : null);
        $candidateTime = $operating && $operating->read_ok
            ? (new OperatingTimeTextParser())->parse($operating->value_raw)
            : null;

        // A recent successful SDK sample is proof of data reception, NOT of robot motion.
        $candidates = [
            $robot->controller?->last_seen_at,
            ($telemetry && ($telemetry->position_ok || $telemetry->torque_ok)) ? ($telemetry->received_at ?? $telemetry->sampled_at) : null,
            ($connection?->state === 'connected') ? $connection->occurred_at : null,
        ];
        $lastPositive = null;
        foreach ($candidates as $candidate) {
            if ($candidate !== null && ($lastPositive === null || $candidate->greaterThan($lastPositive))) {
                $lastPositive = $candidate;
            }
        }

        $linkStatus = 'unknown';
        if ($connection && in_array($connection->state, ['offline', 'reconnecting', 'stopped'], true)
            && ($lastPositive === null || $connection->occurred_at->greaterThanOrEqualTo($lastPositive))) {
            $linkStatus = $connection->state === 'stopped' ? 'collector_stopped' : $connection->state;
        } elseif ($lastPositive !== null) {
            $linkStatus = $lastPositive->greaterThanOrEqualTo(now()->subSeconds(30)) ? 'connected' : 'stale';
        }

        return [
            'id' => $robot->id,
            'robot_code' => $robot->robot_code,
            'name' => $robot->name,
            'model' => $robot->model,
            'control_group' => $robot->control_group,
            'line_name' => $robot->line_name,
            'station_name' => $robot->station_name,
            'is_active' => $robot->is_active,
            'controller' => $robot->controller ? [
                'id' => $robot->controller->id,
                'controller_code' => $robot->controller->controller_code,
                'model' => $robot->controller->model,
                'ip_address' => $robot->controller->ip_address,
            ] : null,
            'link_status' => $linkStatus,
            'last_data_at' => $lastPositive?->toIso8601String(),
            'motion_state' => null, // Raw state text is diagnostic only, not a verified run/servo signal.
            'job_name' => $jobEvidence['name'],
            'job_evidence' => $jobEvidence,
            'io_status' => null, // I/O monitoring is not ingested yet.
            'utilization_percent' => null, // No historical run/idle/stop intervals yet.
            'health_status' => $health?->health_status ?? 'unknown',
            'health_evaluated_at' => $health?->evaluated_at?->toIso8601String(),
            'telemetry' => $telemetry ? [
                'sampled_at' => $telemetry->sampled_at?->toIso8601String(),
                'received_at' => $telemetry->received_at?->toIso8601String(),
                'job_name' => $jobEvidence['name'],
                'job_evidence' => $jobEvidence,
                'position_ok' => $telemetry->position_ok,
                'position_pulses' => $telemetry->position_ok ? $telemetry->position_pulses : null,
                'torque_ok' => $telemetry->torque_ok,
                'torque_axes' => $telemetry->torque_ok ? $telemetry->torque_axes : null,
                'torque_unit' => $telemetry->torque_unit, // sdk_raw, not Nm or percent.
            ] : null,
            'operating_time' => $operating ? [
                'sampled_at' => $operating->sampled_at?->toIso8601String(),
                'read_ok' => $operating->read_ok,
                'time_type' => $operating->time_type,
                'value_raw' => $operating->read_ok ? $operating->value_raw : null,
                'value_seconds' => $operating->read_ok ? $operating->value_seconds : null,
                'candidate_seconds' => $candidateTime['seconds'] ?? null,
                'candidate_hours' => $candidateTime['hours'] ?? null,
                'pm_eligible' => $operating->read_ok && $operating->value_seconds !== null
                    && (bool) config('rms_evidence.operating_text_verified', false),
            ] : null,
            'alarms' => $alarm ? [
                'sampled_at' => $alarm->sampled_at?->toIso8601String(),
                'read_ok' => $alarm->read_ok,
                'active_alarm_count' => $alarm->read_ok
                    ? ($alarm->active_alarm_count ?? ($alarmParse['recognized'] ? $alarmParse['reported_count'] : null))
                    : null,
                'sdk_text' => $alarm->read_ok ? $alarm->sdk_text : null,
                'parsed_alarms' => $alarm->read_ok ? $alarmEvidence : null,
                'parse_state' => !$alarm->read_ok ? 'read_failed'
                    : (!$alarmParse['recognized'] ? 'unrecognized'
                        : ($alarmParse['complete'] ? 'complete' : 'partial')),
                'parse_persisted' => $alarm->parsed_alarms !== null,
            ] : null,
            'latest_connection_event' => $connection ? [
                'state' => $connection->state,
                'occurred_at' => $connection->occurred_at?->toIso8601String(),
                'message' => $connection->message,
            ] : null,
        ];
    }
}
