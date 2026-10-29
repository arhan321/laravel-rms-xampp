<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Models\Robot;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Health assessments are READ ONLY until verified rule-engine logic exists. */
class HealthController extends BaseRmsController
{
    public function index(Request $request, Robot $robot): JsonResponse
    {
        $filters = $this->queryPeriod($request);
        $query = $robot->healthAssessments();
        $this->applyPeriod($query->getQuery(), $filters, 'evaluated_at');
        return response()->json($query->orderByDesc('evaluated_at')->paginate($filters['per_page'] ?? 25));
    }

    public function latest(Robot $robot): JsonResponse
    {
        return response()->json(['data' => $robot->healthAssessments()->orderByDesc('evaluated_at')->first()]);
    }
}
