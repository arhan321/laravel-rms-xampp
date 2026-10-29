<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Models\Robot;
use App\Models\RobotAlarmEvent;
use App\Models\RobotAlarmHistoryEntry;
use App\Models\RobotAlarmSnapshot;
use App\Services\Rms\AlarmHistoryImporter;
use App\Services\Rms\AlarmSnapshotProcessor;
use App\Services\Rms\AlarmTextParser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AlarmController extends BaseRmsController
{
    public function history(Request $request): JsonResponse
    {
        $filters = $this->queryPeriod($request);
        $options = $request->validate([
            'robot_id' => ['sometimes', 'integer', 'exists:robots,id'],
            'category' => ['sometimes', 'in:Major,Minor,UserSystem,User,Offline'],
        ]);
        $query = RobotAlarmHistoryEntry::query();
        if (isset($options['robot_id'])) {
            $query->where('robot_id', $options['robot_id']);
        }
        if (isset($options['category'])) {
            $query->where('category', $options['category']);
        }
        $this->applyPeriod($query, $filters, 'first_observed_at');

        return response()->json($query->orderByDesc('first_observed_at')->paginate($filters['per_page'] ?? 25));
    }

    public function events(Request $request): JsonResponse
    {
        $filters=$this->queryPeriod($request);
        $other=$request->validate([
            'robot_id'=>['sometimes','integer','exists:robots,id'],
            'status'=>['sometimes','in:active,cleared,unknown'],
            'severity'=>['sometimes','string','max:32'],
        ]);
        $query=RobotAlarmEvent::query()->with('robot:id,robot_code,name');
        if(!empty($other['robot_id'])) $query->where('robot_id',$other['robot_id']);
        if(!empty($other['status'])) $query->where('status',$other['status']);
        if(!empty($other['severity'])) $query->where('severity',$other['severity']);
        $this->applyPeriod($query,$filters,'started_at');
        return response()->json($query->orderByDesc('started_at')->paginate($filters['per_page']??25));
    }

    public function snapshots(Request $request, Robot $robot): JsonResponse
    {
        $filters=$this->queryPeriod($request);
        $query=$robot->alarmSnapshots();
        $this->applyPeriod($query->getQuery(),$filters,'sampled_at');
        return response()->json($query->orderByDesc('sampled_at')->paginate($filters['per_page']??25));
    }

    public function latestSnapshot(Robot $robot, AlarmTextParser $parser): JsonResponse
    {
        $snapshot = $robot->alarmSnapshots()->orderByDesc('sampled_at')->first();
        $parsed = $snapshot && $snapshot->read_ok
            ? $parser->parse($snapshot->sdk_text)
            : ['recognized'=>false,'reported_count'=>null,'complete'=>false,'alarms'=>[]];

        return response()->json([
            'data' => $snapshot,
            // Read-only preview. Use rms:dojo-backfill --apply to persist old events.
            'parsing_preview' => [
                'state' => !$snapshot || !$snapshot->read_ok ? 'read_failed'
                    : (!$parsed['recognized'] ? 'unrecognized' : ($parsed['complete'] ? 'complete' : 'partial')),
                'reported_count' => $parsed['recognized'] ? $parsed['reported_count'] : null,
                'identified_count' => $parsed['recognized'] ? count($parsed['alarms']) : null,
                'complete' => $parsed['recognized'] ? $parsed['complete'] : false,
                'alarms' => $parsed['recognized'] ? $parsed['alarms'] : [],
                'persisted' => $snapshot?->parsed_alarms !== null,
            ],
        ]);
    }

    public function storeSnapshot(Request $request, AlarmSnapshotProcessor $processor, AlarmHistoryImporter $historyImporter): JsonResponse
    {
        $data=$request->validate([
            'robot_id'=>['required','integer','exists:robots,id'],
            'sampled_at'=>['required','date'],
            'read_ok'=>['required','boolean'],
            'status_code'=>['nullable','integer'],
            'status_message'=>['nullable','string'],
            'sdk_text'=>['nullable','string','max:65535'],
            // Incoming client-derived fields are deliberately not trusted.
            'active_alarm_count'=>['nullable','integer','min:0'],
            'parsed_alarms'=>['nullable','array'],
            'raw_payload'=>['nullable','array'],
            'simulated'=>['sometimes','boolean'],
        ]);
        if ($error = $this->timestampTimezoneError($data, ['sampled_at'])) {
            return $error;
        }
        $robot = Robot::query()->findOrFail($data['robot_id']);
        $rawPayload = is_array($data['raw_payload'] ?? null) ? $data['raw_payload'] : [];
        $simulated = ($data['simulated'] ?? false) === true || ($rawPayload['simulated'] ?? false) === true;
        unset($data['simulated'], $data['raw_payload'], $data['active_alarm_count'], $data['parsed_alarms']);
        if ($simulated && !str_starts_with($robot->robot_code, 'DEMO_')) {
            return response()->json(['message' => 'Simulated alarm data cannot use a physical robot ID'], 422);
        }
        $rawPayload['simulated'] = $simulated;
        $data['raw_payload'] = $rawPayload;
        if(!$data['read_ok']) $data['sdk_text']=null;
        $key=['robot_id'=>$data['robot_id'],'sampled_at'=>$data['sampled_at']];
        $snapshot=RobotAlarmSnapshot::firstOrCreate($key,$data);
        $result=$snapshot->wasRecentlyCreated ? $processor->process($snapshot) : ['state'=>'duplicate'];
        $history=$historyImporter->import($snapshot);
        return response()->json([
            'data'=>$snapshot->fresh(),'created'=>$snapshot->wasRecentlyCreated,'parsing'=>$result,
            'history_import'=>$history,
        ],$snapshot->wasRecentlyCreated?201:200);
    }
}
