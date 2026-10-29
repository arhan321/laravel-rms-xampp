<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Models\Robot;
use App\Models\RobotTelemetrySample;
use App\Services\Rms\ControllerStateIngestor;
use App\Services\Rms\JobTextParser;
use App\Services\Rms\ServoCurrentStorage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Historical telemetry and authenticated collector ingestion (read-only to robot). */
class TelemetryController extends BaseRmsController
{
    public function index(Request $request, Robot $robot): JsonResponse
    {
        $filters = $this->queryPeriod($request);
        $query = $this->applyPeriod($robot->telemetrySamples()->getQuery(), $filters, 'sampled_at')
            ->orderByDesc('sampled_at');

        return response()->json($query->paginate($filters['per_page'] ?? 50));
    }

    public function latest(Robot $robot): JsonResponse
    {
        return response()->json(['data' => $robot->telemetrySamples()->orderByDesc('received_at')->orderByDesc('id')->first()]);
    }

    public function store(Request $request, JobTextParser $jobParser, ControllerStateIngestor $stateIngestor, ServoCurrentStorage $currentStorage): JsonResponse
    {
        $rules = [
            'robot_id' => ['required', 'integer', 'exists:robots,id'],
            'collector_session_id' => ['nullable', 'uuid'],
            'sequence' => ['nullable', 'integer', 'min:0'],
            'collector_uptime_seconds' => ['nullable', 'integer', 'min:0'],
            'job_name' => ['nullable', 'string', 'max:120'],
            'sampled_at' => ['required', 'date'],
            'position_ok' => ['required', 'boolean'],
            'position_status_code' => ['nullable', 'integer'],
            'position_status_message' => ['nullable', 'string'],
            'position_sampled_at' => ['nullable', 'date'],
            'position_pulses' => ['nullable', 'array:S,L,U,R,B,T'],
            'torque_ok' => ['required', 'boolean'],
            'torque_status_code' => ['nullable', 'integer'],
            'torque_status_message' => ['nullable', 'string'],
            'torque_sampled_at' => ['nullable', 'date'],
            'torque_unit' => ['sometimes', 'in:sdk_raw'],
            'torque_axes' => ['nullable', 'array:S,L,U,R,B,T'],
            'raw_payload' => ['nullable', 'array'],
        ];
        foreach (['S', 'L', 'U', 'R', 'B', 'T'] as $axis) {
            $rules['position_pulses.'.$axis] = ['sometimes', 'nullable', 'numeric'];
            $rules['torque_axes.'.$axis] = ['sometimes', 'nullable', 'numeric'];
        }
        $data = $request->validate($rules);
        if ($error = $this->timestampTimezoneError($data, ['sampled_at', 'position_sampled_at', 'torque_sampled_at'])) {
            return $error;
        }
        $robot = Robot::query()->findOrFail($data['robot_id']);
        if (($data['raw_payload']['simulated'] ?? false) === true &&
            ! str_starts_with($robot->robot_code, 'DEMO_')) {
            return response()->json(['message' => 'Mock data cannot be written to a physical DOJO robot record'], 422);
        }
        if ($data['position_ok'] && count($data['position_pulses'] ?? []) !== 6) {
            return response()->json(['message' => 'Six SLURBT position pulses are required'], 422);
        }
        if ($data['torque_ok'] && count($data['torque_axes'] ?? []) !== 6) {
            return response()->json(['message' => 'Six SLURBT torques are required'], 422);
        }
        if (! $data['position_ok']) {
            $data['position_pulses'] = null;
        }
        if (! $data['torque_ok']) {
            $data['torque_axes'] = null;
        }
        $data['torque_unit'] = 'sdk_raw';
        $jobEvidence = $jobParser->parse($data['raw_payload'] ?? null, $data['job_name'] ?? null);
        $data['job_name'] = $jobEvidence['name'];
        // The collector cannot supply estimates or received_at. Freeze both on the
        // server. A failed database write rolls back torque AND current together;
        // the API must not acknowledge data which has not been committed.
        $sample = DB::transaction(function () use ($data, $robot, $currentStorage, $stateIngestor): RobotTelemetrySample {
            $data['received_at'] = now()->utc();
            $attributes = array_merge($data, $currentStorage->attributes($robot, $data));
            if (! empty($data['collector_session_id']) && array_key_exists('sequence', $data) && $data['sequence'] !== null) {
                $key = ['robot_id' => $data['robot_id'], 'collector_session_id' => $data['collector_session_id'], 'sequence' => $data['sequence']];
                // Retransmission returns the original immutable snapshot.
                $sample = RobotTelemetrySample::firstOrCreate($key, $attributes);
            } else {
                $sample = RobotTelemetrySample::create($attributes);
            }
            $stateIngestor->ingest($sample);

            return $sample;
        }, 3);

        return response()->json(['data' => $sample, 'created' => $sample->wasRecentlyCreated],
            $sample->wasRecentlyCreated ? 201 : 200);
    }
}
