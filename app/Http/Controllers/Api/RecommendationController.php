<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Models\MaintenanceRecommendation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Read recommendation history; only a verified future rules engine may generate new recommendations. */
class RecommendationController extends BaseRmsController
{
    public function index(Request $request): JsonResponse
    {
        $filters = $this->queryPeriod($request);
        $other = $request->validate([
            'robot_id' => ['sometimes', 'integer', 'exists:robots,id'],
            'status' => ['sometimes', 'in:open,acknowledged,resolved,dismissed'],
            'priority' => ['sometimes', 'string', 'max:32'],
        ]);
        $query = MaintenanceRecommendation::query()->with('robot:id,robot_code,name');
        if (!empty($other['robot_id'])) $query->where('robot_id', $other['robot_id']);
        if (!empty($other['status'])) $query->where('status', $other['status']);
        if (!empty($other['priority'])) $query->where('priority', $other['priority']);
        $this->applyPeriod($query, $filters, 'generated_at');
        return response()->json($query->orderByDesc('generated_at')->paginate($filters['per_page'] ?? 25));
    }

    public function show(MaintenanceRecommendation $recommendation): JsonResponse
    {
        return response()->json(['data' => $recommendation->load(['robot', 'healthAssessment', 'maintenancePlan'])]);
    }

    public function acknowledge(Request $request, MaintenanceRecommendation $recommendation): JsonResponse
    {
        $actor = $request->user();
        if (!$actor) return response()->json(['message' => 'Authentication required'], 401);
        return DB::transaction(function () use ($recommendation, $actor): JsonResponse {
            $rec = MaintenanceRecommendation::query()->lockForUpdate()->findOrFail($recommendation->id);
            if ($rec->status !== 'open') return response()->json(['message' => 'Only open recommendations can be acknowledged'], 409);
            $rec->update([
                'status' => 'acknowledged',
                'acknowledged_at' => now(),
                'acknowledged_by' => (string)($actor->name ?? $actor->email ?? $actor->getAuthIdentifier()),
            ]);
            return response()->json(['data' => $rec->refresh()]);
        });
    }

    public function resolve(Request $request, MaintenanceRecommendation $recommendation): JsonResponse
    {
        $actor = $request->user();
        if (!$actor) return response()->json(['message' => 'Authentication required'], 401);
        $data = $request->validate(['closure_note' => ['required', 'string', 'min:5']]);
        return DB::transaction(function () use ($recommendation, $data): JsonResponse {
            $rec = MaintenanceRecommendation::query()->lockForUpdate()->findOrFail($recommendation->id);
            if (!in_array($rec->status, ['open', 'acknowledged'], true)) {
                return response()->json(['message' => 'Recommendation has already been closed'], 409);
            }
            $rec->update([
                'status' => 'resolved',
                'closed_at' => now(),
                'closure_note' => $data['closure_note'],
            ]);
            // Resolve a recommendation, not the robot health: health must be reassessed.
            return response()->json(['data' => $rec->refresh()]);
        });
    }
}
