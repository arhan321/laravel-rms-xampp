<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Models\Robot;
use App\Models\RobotOperatingTimeSample;
use App\Services\Rms\OperatingTimeTextParser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OperatingTimeController extends BaseRmsController
{
    public function index(Request $request, Robot $robot): JsonResponse
    {
        $filters=$this->queryPeriod($request);
        $types=$request->validate(['time_type'=>['sometimes','string','max:80']]);
        $query=$robot->operatingTimeSamples();
        if(!empty($types['time_type'])) $query->where('time_type',$types['time_type']);
        $this->applyPeriod($query->getQuery(),$filters,'sampled_at');
        return response()->json($query->orderByDesc('sampled_at')->paginate($filters['per_page']??25));
    }

    public function latest(Robot $robot): JsonResponse
    {
        return response()->json(['data'=>$robot->operatingTimeSamples()
            ->where('time_type','ServoPowerOnTimeRobot')->orderByDesc('sampled_at')->first()]);
    }

    public function store(Request $request, OperatingTimeTextParser $parser): JsonResponse
    {
        $data=$request->validate([
            'robot_id'=>['required','integer','exists:robots,id'],
            'time_type'=>['required','string','max:80'],
            'sampled_at'=>['required','date'],
            'read_ok'=>['required','boolean'],
            'status_code'=>['nullable','integer'],
            'status_message'=>['nullable','string'],
            'value_raw'=>['nullable','string','max:8192'],
            'value_seconds'=>['nullable','integer','min:0'],
            'raw_payload'=>['nullable','array'],
            'simulated'=>['sometimes','boolean'],
        ]);
        if ($error = $this->timestampTimezoneError($data, ['sampled_at'])) {
            return $error;
        }
        $robot = Robot::query()->findOrFail($data['robot_id']);
        $rawPayload = is_array($data['raw_payload'] ?? null) ? $data['raw_payload'] : [];
        $simulated = ($data['simulated'] ?? false) === true || ($rawPayload['simulated'] ?? false) === true;
        unset($data['simulated']);
        if ($simulated && !str_starts_with($robot->robot_code, 'DEMO_')) {
            return response()->json(['message' => 'Simulated operating-time data cannot use a physical robot ID'], 422);
        }
        $candidate=($data['read_ok']??false) ? $parser->parse($data['value_raw']??null) : null;
        // Never mark SDK raw text as OEM/pendant-verified by default.
        $trusted=config('rms_evidence.operating_text_verified',false)
            && ($data['time_type']==='ServoPowerOnTimeRobot') && $candidate!==null;
        $data['value_seconds']=$trusted ? $candidate['seconds'] : null;
        if(!$data['read_ok']) $data['value_raw']=null;
        $raw=$rawPayload;
        $raw['simulated'] = $simulated;
        $raw['parsed_candidate_seconds']=$candidate['seconds']??null;
        $raw['parser_source']=$candidate['source']??null;
        $raw['verified_for_pm']=$trusted;
        $data['raw_payload']=$raw;
        $key=['robot_id'=>$data['robot_id'],'time_type'=>$data['time_type'],'sampled_at'=>$data['sampled_at']];
        $sample=RobotOperatingTimeSample::firstOrCreate($key,$data);
        return response()->json(['data'=>$sample,'created'=>$sample->wasRecentlyCreated,
            'validation'=>$trusted?'pendant_verified_config_enabled':'diagnostic_text_only'],
            $sample->wasRecentlyCreated?201:200);
    }
}
