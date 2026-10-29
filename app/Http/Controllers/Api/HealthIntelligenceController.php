<?php

declare(strict_types=1);
namespace App\Http\Controllers\Api;
use App\Http\Controllers\Controller;
use App\Models\MaintenanceLog;
use App\Models\Robot;
use App\Models\RobotAnomalyBaseline;
use App\Models\RobotAnomalyEvent;
use App\Models\RobotPrediction;
use App\Models\RobotPredictionModel;
use App\Services\Rms\RobotHealthEngine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class HealthIntelligenceController extends Controller
{
    public function summary(RobotHealthEngine $engine):JsonResponse
    {
        $health=Robot::query()->where('is_active',true)->get();
        $counts=['healthy'=>0,'warning'=>0,'critical'=>0,'unknown'=>0];
        foreach($health as $robot){
            // Live evaluation prevents a formerly healthy persisted row hiding lost telemetry.
            $state=$engine->assess($robot)['status'];
            $counts[array_key_exists($state,$counts)?$state:'unknown']++;
        }
        return response()->json(['data'=>[
            'counts'=>$counts,'robot_count'=>$health->count(),
            'open_anomalies'=>RobotAnomalyEvent::query()->whereIn('status',['open','acknowledged'])->count(),
            'current_models'=>RobotPredictionModel::query()->where('is_current',true)->count(),
            'disclaimer'=>'Experimental non-OEM monitoring; no controller actuation. UNKNOWN does not mean healthy.',
        ]]);
    }
    public function robot(Robot $robot,RobotHealthEngine $engine):JsonResponse
    {
        return response()->json(['data'=>[
            'robot'=>['id'=>$robot->id,'code'=>$robot->robot_code,'name'=>$robot->name],
            'health'=>$engine->assess($robot),
            'last_persisted_assessment'=>$robot->healthAssessments()->latest('evaluated_at')->first(),
            'baselines'=>RobotAnomalyBaseline::query()->where('robot_id',$robot->id)->orderBy('axis')->get(),
            'anomalies'=>RobotAnomalyEvent::query()->where('robot_id',$robot->id)->latest('started_at')->limit(50)->get(),
            'models'=>RobotPredictionModel::query()->where('robot_id',$robot->id)->where('is_current',true)->get(),
            'predictions'=>RobotPrediction::query()->where('robot_id',$robot->id)->with('model')->latest('generated_at')->limit(30)->get(),
        ]]);
    }
    public function anomalies(Request $request):JsonResponse
    {
        $params=$request->validate(['robot_id'=>['sometimes','integer','exists:robots,id'],
            'status'=>['sometimes','in:open,acknowledged,reviewed,cleared'],
            'per_page'=>['sometimes','integer','min:1','max:100']]);
        return response()->json(RobotAnomalyEvent::query()->with('robot:id,robot_code,name')
            ->when(isset($params['robot_id']),fn($q)=>$q->where('robot_id',$params['robot_id']))
            ->when(isset($params['status']),fn($q)=>$q->where('status',$params['status']))
            ->latest('started_at')->paginate($params['per_page']??25));
    }
    public function predictions(Request $request):JsonResponse
    {
        $params=$request->validate(['robot_id'=>['sometimes','integer','exists:robots,id'],
            'per_page'=>['sometimes','integer','min:1','max:100']]);
        return response()->json(RobotPrediction::query()->with('model','robot:id,robot_code,name')
            ->when(isset($params['robot_id']),fn($q)=>$q->where('robot_id',$params['robot_id']))
            ->latest('generated_at')->paginate($params['per_page']??25));
    }
    public function acknowledge(Request $request,RobotAnomalyEvent $event):JsonResponse
    {
        return DB::transaction(static function() use ($request,$event){
            $e=RobotAnomalyEvent::query()->lockForUpdate()->findOrFail($event->id);
            if($e->status!=='open')return response()->json(['message'=>'Only open event may be acknowledged'],409);
            $e->update(['status'=>'acknowledged','acknowledged_at'=>now(),
                'acknowledged_by'=>(string)$request->user()->getAuthIdentifier()]);
            return response()->json(['data'=>$e->refresh()]);
        },3);
    }
    public function review(Request $request,RobotAnomalyEvent $event):JsonResponse
    {
        $data=$request->validate(['feedback'=>['required','in:confirmed,false_positive,inconclusive'],
            'technician_note'=>['required','string','min:10','max:4000'],
            'maintenance_log_id'=>['required','integer','exists:maintenance_logs,id']]);
        $log=MaintenanceLog::query()->findOrFail($data['maintenance_log_id']);
        if($log->robot_id!==$event->robot_id||!$log->verified_at)
            return response()->json(['message'=>'Verified maintenance log must belong to this robot.'],422);
        return DB::transaction(static function() use ($request,$event,$data){
            $e=RobotAnomalyEvent::query()->lockForUpdate()->findOrFail($event->id);
            if(!in_array($e->status,['open','acknowledged'],true))
                return response()->json(['message'=>'Already reviewed'],409);
            $e->update(['status'=>'reviewed','feedback'=>$data['feedback'],
                'technician_note'=>$data['technician_note'],
                'maintenance_log_id'=>$data['maintenance_log_id'],
                'reviewed_at'=>now(),'reviewed_by'=>(string)$request->user()->getAuthIdentifier()]);
            return response()->json(['data'=>$e->refresh()]);
        },3);
    }
    public function predictionFeedback(Request $request,RobotPrediction $prediction):JsonResponse
    {
        $data=$request->validate(['technician_verdict'=>['required','in:confirmed_degradation,false_positive,inconclusive'],
            'maintenance_log_id'=>['required','integer','exists:maintenance_logs,id'],
            'feedback_note'=>['required','string','min:10','max:4000']]);
        $log=MaintenanceLog::query()->findOrFail($data['maintenance_log_id']);
        if($log->robot_id!==$prediction->robot_id||!$log->verified_at)
            return response()->json(['message'=>'Verified maintenance log must belong to this robot.'],422);
        return DB::transaction(static function()use($request,$prediction,$data){
            $p=RobotPrediction::query()->lockForUpdate()->findOrFail($prediction->id);
            if($p->feedback_at!==null)return response()->json(['message'=>'Already reviewed'],409);
            $p->update($data+['feedback_at'=>now(),'feedback_by'=>(string)$request->user()->getAuthIdentifier()]);
            return response()->json(['data'=>$p->refresh(),
                'next_step'=>'Run rms:predictive-train after recording a verified condition measurement.']);
        },3);
    }
}
