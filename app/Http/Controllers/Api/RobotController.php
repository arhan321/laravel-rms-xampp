<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Models\Robot;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RobotController extends BaseRmsController
{
    /** Route model binding: /robots/{robot:robot_code} or /robots/{robot}. */
    public function index(Request $request): JsonResponse
    {
        $input = $request->validate([
            'active' => ['sometimes', 'boolean'],
            'line' => ['sometimes', 'string', 'max:100'],
        ]);
        $query = Robot::query()->with('controller')->orderBy('robot_code');
        if (array_key_exists('active', $input)) {
            $query->where('is_active', $input['active']);
        }
        if (!empty($input['line'])) {
            $query->where('line_name', $input['line']);
        }
        return response()->json([
            'data' => $query->get()->map(fn (Robot $robot): array => $this->snapshot($robot)),
        ]);
    }

    public function show(Robot $robot): JsonResponse
    {
        return response()->json(['data' => $this->snapshot($robot)]);
    }
}
