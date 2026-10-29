<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Models\MaintenanceLog;
use App\Models\MaintenancePlan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MaintenanceLogController extends BaseRmsController
{
    public function index(Request $request): JsonResponse
    {
        $filters = $this->queryPeriod($request);
        $other = $request->validate([
            'robot_id' => ['sometimes', 'integer', 'exists:robots,id'],
            'result' => ['sometimes', 'in:recorded,completed,follow_up_required'],
        ]);
        $query = MaintenanceLog::query()->with('robot:id,robot_code,name');
        if (!empty($other['robot_id'])) $query->where('robot_id', $other['robot_id']);
        if (!empty($other['result'])) $query->where('result', $other['result']);
        $this->applyPeriod($query, $filters, 'created_at');
        return response()->json($query->orderByDesc('created_at')->paginate($filters['per_page'] ?? 25));
    }

    public function show(MaintenanceLog $maintenanceLog): JsonResponse
    {
        return response()->json(['data' => $maintenanceLog->load(['robot', 'maintenancePlan'])]);
    }

    /** Creating a completed log does not automatically declare a robot healthy. */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'robot_id' => ['required', 'integer', 'exists:robots,id'],
            'maintenance_plan_id' => ['nullable', 'integer', 'exists:maintenance_plans,id'],
            'action_type' => ['sometimes', 'in:preventive,corrective,inspection,other'],
            'result' => ['sometimes', 'in:recorded,completed,follow_up_required'],
            'performed_by' => ['required', 'string', 'max:120'],
            'started_at' => ['nullable', 'date'],
            'completed_at' => ['nullable', 'date', 'after_or_equal:started_at'],
            'findings' => ['nullable', 'string'],
            'action_taken' => ['nullable', 'string'],
            'supporting_data' => ['nullable', 'array'],
        ]);
        if (!empty($data['maintenance_plan_id'])) {
            $plan = MaintenancePlan::findOrFail($data['maintenance_plan_id']);
            if ($plan->robot_id !== (int)$data['robot_id']) {
                return response()->json(['message' => 'Maintenance plan belongs to another robot.'], 422);
            }
        }
        if (($data['result'] ?? 'recorded') === 'completed' && empty($data['completed_at'])) {
            return response()->json(['message' => 'completed_at is required for completed work.'], 422);
        }
        $log = DB::transaction(function () use ($data) {
            $log = MaintenanceLog::create($data);
            if (($log->result === 'completed') && $log->maintenance_plan_id && $log->completed_at) {
                $plan = MaintenancePlan::query()->lockForUpdate()->findOrFail($log->maintenance_plan_id);
                if ($plan->last_completed_at === null || $log->completed_at->greaterThan($plan->last_completed_at)) {
                    $plan->update(['last_completed_at' => $log->completed_at]);
                }
            }
            return $log;
        });
        return response()->json(['data' => $log], 201);
    }

    /** Independent human verification; require auth before exposing route. */
    public function verify(Request $request, MaintenanceLog $maintenanceLog): JsonResponse
    {
        $actor = $request->user();
        if (!$actor) return response()->json(['message' => 'Authentication required'], 401);
        if ($maintenanceLog->verified_at !== null) {
            return response()->json(['message' => 'This maintenance log was already verified'], 409);
        }
        $maintenanceLog->update([
            'verified_by' => (string)($actor->name ?? $actor->email ?? $actor->getAuthIdentifier()),
            'verified_at' => now(),
        ]);
        return response()->json(['data' => $maintenanceLog->refresh()]);
    }
}
