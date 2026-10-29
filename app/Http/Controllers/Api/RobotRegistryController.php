<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Models\Robot;
use App\Models\RobotController as ControllerModel;
use App\Services\Rms\RobotReachabilityProbe;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Master data only. These writes must be routed behind authenticated admin authorization. */
class RobotRegistryController extends BaseRmsController
{
    public function controllers(): JsonResponse
    {
        return response()->json(['data' => ControllerModel::query()->withCount('robots')->orderBy('controller_code')->get()]);
    }

    public function storeController(Request $request, RobotReachabilityProbe $probe): JsonResponse
    {
        $data = $request->validate([
            'controller_code' => ['required', 'string', 'max:80', 'unique:robot_controllers,controller_code'],
            'model' => ['sometimes', 'string', 'max:80'],
            'ip_address' => ['required', 'ip', 'ipv4', 'unique:robot_controllers,ip_address'],
            'firmware_version' => ['nullable', 'string', 'max:80'],
            'location' => ['nullable', 'string', 'max:150'],
            'notes' => ['nullable', 'string'],
        ]);
        $probeResult = $probe->probe($data['ip_address']);
        if (! $probeResult['ok']) {
            return response()->json([
                'message' => 'Controller tidak disimpan karena IP tidak reachable dari server Laravel.',
                'probe' => $probeResult,
            ], 422);
        }
        $data += [
            'network_verified_at' => now(),
            'network_verification_status' => $probeResult['status'],
            'network_verification_method' => $probeResult['method'],
            'network_verification_message' => $probeResult['message'],
            'network_verification_latency_ms' => $probeResult['latency_ms'],
        ];

        return response()->json(['data' => ControllerModel::create($data)], 201);
    }

    public function updateController(Request $request, ControllerModel $controller, RobotReachabilityProbe $probe): JsonResponse
    {
        $data = $request->validate([
            'controller_code' => ['sometimes', 'string', 'max:80', Rule::unique('robot_controllers', 'controller_code')->ignore($controller->id)],
            'model' => ['sometimes', 'string', 'max:80'],
            'ip_address' => ['sometimes', 'ip', 'ipv4', Rule::unique('robot_controllers', 'ip_address')->ignore($controller->id)],
            'firmware_version' => ['nullable', 'string', 'max:80'],
            'location' => ['nullable', 'string', 'max:150'],
            'notes' => ['nullable', 'string'],
        ]);
        if (isset($data['ip_address']) && $data['ip_address'] !== $controller->ip_address) {
            $probeResult = $probe->probe($data['ip_address']);
            if (! $probeResult['ok']) {
                return response()->json([
                    'message' => 'Controller tidak diperbarui karena IP baru tidak reachable dari server Laravel.',
                    'probe' => $probeResult,
                ], 422);
            }
            $data += [
                'network_verified_at' => now(),
                'network_verification_status' => $probeResult['status'],
                'network_verification_method' => $probeResult['method'],
                'network_verification_message' => $probeResult['message'],
                'network_verification_latency_ms' => $probeResult['latency_ms'],
            ];
        }
        $controller->update($data);

        return response()->json(['data' => $controller->refresh()]);
    }

    public function storeRobot(Request $request): JsonResponse
    {
        $data = $request->validate([
            'controller_id' => ['required', 'integer', 'exists:robot_controllers,id'],
            'robot_code' => ['required', 'string', 'max:80', 'unique:robots,robot_code'],
            'name' => ['required', 'string', 'max:150'],
            'model' => ['sometimes', 'string', 'max:80'],
            'serial_number' => ['required', 'string', 'max:120', 'unique:robots,serial_number'],
            'robot_type' => ['required', 'string', 'max:120'],
            'nameplate_date' => ['required', 'date_format:Y-m'],
            'control_group' => ['required', 'string', 'max:8'],
            'axis_count' => ['sometimes', 'integer', 'between:1,16'],
            'line_name' => ['nullable', 'string', 'max:100'],
            'station_name' => ['nullable', 'string', 'max:100'],
            'is_active' => ['sometimes', 'boolean'],
            'metadata' => ['nullable', 'array'],
        ]);
        $duplicatedGroup = Robot::query()
            ->where('controller_id', $data['controller_id'])
            ->where('control_group', $data['control_group'])->exists();
        if ($duplicatedGroup) {
            return response()->json(['message' => 'This control group is already registered on the controller.'], 422);
        }

        return response()->json(['data' => Robot::create($data)], 201);
    }

    public function updateRobot(Request $request, Robot $robot): JsonResponse
    {
        $data = $request->validate([
            'controller_id' => ['sometimes', 'integer', 'exists:robot_controllers,id'],
            'robot_code' => ['sometimes', 'string', 'max:80', Rule::unique('robots', 'robot_code')->ignore($robot->id)],
            'name' => ['sometimes', 'string', 'max:150'],
            'model' => ['sometimes', 'string', 'max:80'],
            'serial_number' => ['sometimes', 'required', 'string', 'max:120', Rule::unique('robots', 'serial_number')->ignore($robot->id)],
            'robot_type' => ['sometimes', 'required', 'string', 'max:120'],
            'nameplate_date' => ['sometimes', 'nullable', 'date_format:Y-m'],
            'control_group' => ['sometimes', 'string', 'max:8'],
            'axis_count' => ['sometimes', 'integer', 'between:1,16'],
            'line_name' => ['nullable', 'string', 'max:100'],
            'station_name' => ['nullable', 'string', 'max:100'],
            'is_active' => ['sometimes', 'boolean'],
            'metadata' => ['nullable', 'array'],
        ]);
        $controllerId = $data['controller_id'] ?? $robot->controller_id;
        $controlGroup = $data['control_group'] ?? $robot->control_group;
        if (Robot::query()->where('controller_id', $controllerId)
            ->where('control_group', $controlGroup)->where('id', '!=', $robot->id)->exists()) {
            return response()->json(['message' => 'This control group is already registered on the controller.'], 422);
        }
        $robot->update($data);

        return response()->json(['data' => $robot->refresh()]);
    }
}
