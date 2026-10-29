<?php

declare(strict_types=1);
namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Robot;
use App\Models\RobotAnomalyBaseline;
use App\Models\RobotAnomalyEvent;
use App\Models\RobotPrediction;
use App\Models\RobotPredictionModel;
use App\Services\Rms\RobotHealthEngine;
use Illuminate\Contracts\View\View;

final class HealthIntelligenceController extends Controller
{
    public function index():View
    {
        $robots=Robot::query()->with('controller')->orderBy('line_name')->orderBy('robot_code')->get();
        $items=[];$counts=['healthy'=>0,'warning'=>0,'critical'=>0,'unknown'=>0];
        foreach($robots as $robot){
            // Live read: avoids displaying a stale persisted assessment as present-day HEALTHY.
            $state=app(RobotHealthEngine::class)->assess($robot);
            $counts[$state['status']]++;
            $anomalyCount=RobotAnomalyEvent::query()->where('robot_id',$robot->id)
                ->whereIn('status',['open','acknowledged'])->count();
            $prediction=RobotPrediction::query()->where('robot_id',$robot->id)
                ->whereHas('model',fn($q)=>$q->where('is_current',true))
                ->latest('generated_at')->first();
            $items[]=['robot'=>$robot,'state'=>$state,'anomaly_count'=>$anomalyCount,'prediction'=>$prediction];
        }
        return view('rms.health.index',[
            'title'=>'Health Intelligence','rows'=>$items,'counts'=>$counts,
            'total'=>count($items),'openAnomalies'=>RobotAnomalyEvent::query()
                ->whereIn('status',['open','acknowledged'])->count(),
            'verifiedModels'=>RobotPredictionModel::query()->where('is_current',true)->count(),
        ]);
    }
    public function show(Robot $robot,RobotHealthEngine $engine):View
    {
        $robot->load('controller');
        $health=$engine->assess($robot);
        $baselines=RobotAnomalyBaseline::query()->where('robot_id',$robot->id)
            ->orderBy('axis')->get();
        $anomalies=RobotAnomalyEvent::query()->where('robot_id',$robot->id)
            ->latest('started_at')->limit(25)->get();
        $models=RobotPredictionModel::query()->where('robot_id',$robot->id)
            ->where('is_current',true)->get()->keyBy('axis');
        $predictions=RobotPrediction::query()->where('robot_id',$robot->id)
            ->whereIn('model_id',$models->pluck('id'))
            ->latest('generated_at')->get()->unique('axis')->keyBy('axis');
        $rows=$robot->telemetrySamples()->where('torque_ok',true)
            ->where('torque_unit','sdk_raw')->orderByDesc('sampled_at')->limit(250)
            ->get()->reverse()->values();
        $series=$rows->map(static function($s){
            $v=[];foreach(['S','L','U','R','B','T'] as $axis){
                $t=$s->torque_axes[$axis]??null;
                $v[$axis]=is_numeric($t)&&is_finite((float)$t)?abs((float)$t):null;
            }
            return ['at'=>$s->sampled_at->timezone('Asia/Jakarta')->format('d/m H:i:s'),
                'job'=>$s->job_name??null,'axes'=>$v];
        });
        return view('rms.health.show',[
            'title'=>'Health · '.$robot->robot_code,'robot'=>$robot,'health'=>$health,
            'baselines'=>$baselines,'anomalies'=>$anomalies,'models'=>$models,
            'predictions'=>$predictions,'series'=>$series,
        ]);
    }
}
