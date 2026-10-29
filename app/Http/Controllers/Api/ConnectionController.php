<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Models\Robot;
use App\Models\RobotConnectionEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ConnectionController extends BaseRmsController
{
    public function index(Request $request, Robot $robot): JsonResponse
    {
        $filters=$this->queryPeriod($request);
        $query=$robot->connectionEvents();
        $this->applyPeriod($query->getQuery(),$filters,'occurred_at');
        return response()->json($query->orderByDesc('occurred_at')->paginate($filters['per_page']??25));
    }

    public function store(Request $request): JsonResponse
    {
        $data=$request->validate([
            'robot_id'=>['required','integer','exists:robots,id'],
            'state'=>['required','in:connected,offline,reconnecting,stopped'],
            'message'=>['nullable','string'],
            'ip_address'=>['nullable','ip'],
            'occurred_at'=>['required','date'],
            'raw_payload'=>['nullable','array'],
        ]);
        if ($error = $this->timestampTimezoneError($data, ['occurred_at'])) {
            return $error;
        }
        $registered=Robot::query()->findOrFail($data['robot_id']);
        if (($data['raw_payload']['simulated']??false) === true &&
            !str_starts_with($registered->robot_code,'DEMO_')) {
            return response()->json(['message'=>'Simulated connection cannot use a physical robot ID'],422);
        }
        $event=DB::transaction(function() use ($data) {
            $robot=Robot::query()->with('controller')->findOrFail($data['robot_id']);
            $controller=$robot->controller;
            if ($controller) {
                // Lock first. last_seen_at is last successful contact, NOT a state
                // event watermark. Those concepts must never be conflated.
                $controller=\App\Models\RobotController::query()->lockForUpdate()->findOrFail($controller->id);
            }
            $event=RobotConnectionEvent::create($data);
            if($controller){
                $robotIds=Robot::query()->where('controller_id',$controller->id)->pluck('id');
                // JSON extraction differs between SQL Server and PostgreSQL.
                // Keep missing/null flags equivalent to false and exclude both
                // the JSON boolean true and the string "true" from real events.
                $simulatedValue = match (DB::connection()->getDriverName()) {
                    'sqlsrv' => "JSON_VALUE(raw_payload, '$.simulated')",
                    'pgsql' => "raw_payload->>'simulated'",
                    default => throw new \LogicException(
                        'RMS connection events require SQL Server or PostgreSQL.'
                    ),
                };
                $realEvents=RobotConnectionEvent::query()->whereIn('robot_id',$robotIds)
                    ->whereRaw("COALESCE($simulatedValue, 'false') <> 'true'");
                $newest=(clone $realEvents)->orderByDesc('occurred_at')->orderByDesc('id')->first();
                $lastSuccessful=(clone $realEvents)->where('state','connected')
                    ->orderByDesc('occurred_at')->first();
                // Replayed older events cannot flip status back to connected.
                $controller->update([
                    'connection_status'=>$newest?->state??'unknown',
                    'last_seen_at'=>$lastSuccessful?->occurred_at,
                ]);
            }
            return $event;
        },3);
        return response()->json(['data'=>$event],201);
    }
}
