<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Models\MaintenancePlan;
use App\Services\Rms\MaintenanceCategories;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Plans are managed by maintenance staff; interval values are NOT SDK recommendations. */
class MaintenancePlanController extends BaseRmsController
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'robot_id' => ['sometimes', 'integer', 'exists:robots,id'],
            'is_active' => ['sometimes', 'boolean'],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
        ]);
        $query = MaintenancePlan::query()->with('robot:id,robot_code,name');
        if (!empty($filters['robot_id'])) $query->where('robot_id', $filters['robot_id']);
        if (array_key_exists('is_active', $filters)) $query->where('is_active', $filters['is_active']);
        return response()->json($query->orderBy('next_due_at')->orderBy('id')
            ->paginate($filters['per_page'] ?? 25));
    }

    public function show(MaintenancePlan $maintenancePlan): JsonResponse
    {
        return response()->json(['data' => $maintenancePlan->load(['robot', 'logs', 'recommendations'])]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validatePlan($request, true);
        return response()->json(['data' => MaintenancePlan::create($data)], 201);
    }

    public function update(Request $request, MaintenancePlan $maintenancePlan): JsonResponse
    {
        $data = $this->validatePlan($request, false);
        $basis = $data['basis'] ?? $maintenancePlan->basis;
        $hours = array_key_exists('interval_hours', $data) ? $data['interval_hours'] : $maintenancePlan->interval_hours;
        $days = array_key_exists('interval_days', $data) ? $data['interval_days'] : $maintenancePlan->interval_days;
        if (($basis === 'operating_hours' && (!$hours || (float)$hours <= 0))
            || ($basis === 'calendar' && (!$days || (int)$days <= 0))) {
            return response()->json(['message' => 'Provide a positive interval for the selected basis.'], 422);
        }
        $maintenancePlan->update($data);
        return response()->json(['data' => $maintenancePlan->refresh()]);
    }

    /** Archive rather than delete: preserve maintenance history. */
    public function deactivate(MaintenancePlan $maintenancePlan): JsonResponse
    {
        $maintenancePlan->update(['is_active' => false]);
        return response()->json(['data' => $maintenancePlan->refresh()]);
    }

    private function validatePlan(Request $request, bool $creating): array
    {
        $data = $request->validate([
            'robot_id' => [$creating ? 'required' : 'sometimes', 'integer', 'exists:robots,id'],
            'title' => [$creating ? 'required' : 'sometimes', 'string', 'max:180'],
            'description' => ['nullable', 'string'],
            'component_category' => ['nullable', \Illuminate\Validation\Rule::in(array_keys(MaintenanceCategories::LABELS))],
            'basis' => [$creating ? 'required' : 'sometimes', 'in:operating_hours,calendar'],
            'interval_hours' => ['nullable', 'numeric', 'gt:0'],
            'interval_days' => ['nullable', 'integer', 'min:1'],
            'next_due_operating_hours' => ['nullable', 'numeric', 'min:0'],
            'next_due_at' => ['nullable', 'date'],
            'last_completed_at' => ['nullable', 'date'],
            'is_active' => ['sometimes', 'boolean'],
            'created_by' => ['nullable', 'string', 'max:120'],
        ]);
        if ($creating && ((($data['basis'] ?? null) === 'operating_hours' && empty($data['interval_hours']))
                || (($data['basis'] ?? null) === 'calendar' && empty($data['interval_days'])))) {
            abort(422, 'Provide interval_hours or interval_days for the selected maintenance basis.');
        }
        return $data;
    }
}
