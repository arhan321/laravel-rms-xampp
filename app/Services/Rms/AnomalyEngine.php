<?php

declare(strict_types=1);
namespace App\Services\Rms;

use App\Models\Robot;
use App\Models\RobotAnomalyBaseline;
use App\Models\RobotAnomalyEvent;
use Illuminate\Support\Facades\DB;

/** Robust MAD baseline, per robot/axis/job. Flags statistical outliers, not damage. */
final class AnomalyEngine
{
    private const AXES=['S','L','U','R','B','T'];
    private static function jobKey(?string $name): string
    {
        return ($name !== null && trim($name)!=='') ? mb_substr(trim($name),0,120) : '__unspecified__';
    }
    public function trainRobot(Robot $robot): array
    {
        // Require actual data; do not synthesize missing job labels.
        $rows=$robot->telemetrySamples()->where('torque_ok',true)->where('torque_unit','sdk_raw')
            ->where('sampled_at','>=',now()->subDays(config('rms_health.baseline_lookback_days',30)))
            ->latest('sampled_at')->limit(6000)->get()->reverse();
        $sets=[];
        foreach($rows as $r){
            if(!is_array($r->torque_axes))continue;
            $job=self::jobKey($r->job_name);
            foreach(self::AXES as $a){
                $v=$r->torque_axes[$a]??null;
                if(is_numeric($v)&&is_finite((float)$v))$sets[$job][$a][]=['v'=>abs((float)$v),'at'=>$r->sampled_at,'id'=>$r->id];
            }
        }
        $updated=[];
        foreach($sets as $job=>$axes)foreach($axes as $axis=>$points){
            if(count($points)<config('rms_health.minimum_baseline_samples',40))continue;
            $values=array_column($points,'v');$stats=RobustStatistics::summarize($values);
            $version=hash('sha256',json_encode([$robot->id,$job,$axis,$points[0]['id'],end($points)['id'],$stats],JSON_THROW_ON_ERROR));
            $base=RobotAnomalyBaseline::query()->updateOrCreate(
                ['robot_id'=>$robot->id,'job_key'=>$job,'axis'=>$axis,'metric'=>'torque_abs'],
                ['unit'=>'sdk_raw','median_value'=>$stats['median'],'mad_value'=>$stats['mad'],
                    'sample_count'=>$stats['n'],'window_start'=>$points[0]['at'],
                    'window_end'=>end($points)['at'],'version'=>$version]);
            $updated[]=['axis'=>$axis,'job'=>$job,'samples'=>$stats['n'],'baseline_id'=>$base->id,
                'usable'=>$stats['mad']>0];
        }
        return $updated;
    }
    public function inspectLatest(Robot $robot): array
    {
        $r=$robot->telemetrySamples()->latest('sampled_at')->first();
        if(!$r||!$r->torque_ok||$r->torque_unit!=='sdk_raw'||!is_array($r->torque_axes)
            ||!$r->sampled_at->betweenIncluded(now()->subSeconds(config('rms_health.fresh_seconds',30)),now()->addSeconds(5)))
            return ['status'=>'unknown','reason'=>'No fresh valid torque sample','events'=>[]];
        $job=self::jobKey($r->job_name); $triggered=[]; $recovered=[];
        foreach(self::AXES as $axis){
            $v=$r->torque_axes[$axis]??null;
            if(!is_numeric($v)||!is_finite((float)$v))continue;
            $base=RobotAnomalyBaseline::query()->where('robot_id',$robot->id)
                ->where('job_key',$job)->where('axis',$axis)->where('metric','torque_abs')->first();
            if(!$base||$base->sample_count<config('rms_health.minimum_baseline_samples',40)
                ||$base->unit!=='sdk_raw'||(float)$base->mad_value<=0)continue;
            // A baseline must predate the observation: don't score against a baseline
            // that already incorporated this exact latest observation.
            if($base->window_end && $base->window_end >= $r->sampled_at)continue;
            $z=RobustStatistics::z(abs((float)$v),(float)$base->median_value,(float)$base->mad_value);
            if($z===null)continue;
            $warning=(float)config('rms_health.anomaly_warning_z',3.5);
            $critical=(float)config('rms_health.anomaly_critical_z',5.0);
            $isOutlier=$z>=$warning;
            $sev=$z>=$critical?'critical':'warning';
            $score=round(min(100,$z*20),2);
            $result=DB::transaction(static function() use ($robot,$r,$base,$axis,$job,$sev,$z,$score,$v,$isOutlier){
                // Serialize updates and make repeats of the same sample idempotent.
                Robot::query()->whereKey($robot->id)->lockForUpdate()->firstOrFail();
                $e=RobotAnomalyEvent::query()->where('robot_id',$robot->id)->where('axis',$axis)
                    ->where('job_key',$job)->whereIn('status',['open','acknowledged'])->lockForUpdate()->first();
                if($e && $e->last_evaluated_sample_id === $r->id)return ['state'=>'unchanged','event'=>$e];
                if($isOutlier){
                    if($e){
                        $e->update(['last_seen_at'=>$r->sampled_at,'trigger_sample_id'=>$r->id,
                            'last_evaluated_sample_id'=>$r->id,'last_evaluated_at'=>$r->sampled_at,
                            'recovery_samples'=>0,'score'=>max((float)$e->score,$score),
                            'robust_z'=>max((float)$e->robust_z,$z),
                            'severity'=>$e->severity==='critical'?'critical':$sev]);
                    }else{
                        $e=RobotAnomalyEvent::create([
                            'robot_id'=>$robot->id,'baseline_id'=>$base->id,'trigger_sample_id'=>$r->id,
                            'last_evaluated_sample_id'=>$r->id,'last_evaluated_at'=>$r->sampled_at,
                            'axis'=>$axis,'job_key'=>$job,'severity'=>$sev,'status'=>'open','score'=>$score,
                            'robust_z'=>$z,'observed_value'=>abs((float)$v),
                            'started_at'=>$r->sampled_at,'last_seen_at'=>$r->sampled_at,
                        ]);
                    }
                    return ['state'=>'triggered','event'=>$e];
                }
                if(!$e)return ['state'=>'normal','event'=>null];
                // Hysteresis: 3 distinct non-outlying samples with z < 2.0.
                // Reading between recovery threshold and warning breaks the sequence.
                $recovery=$z<(float)config('rms_health.anomaly_recovery_z',2.0)
                    ? (int)$e->recovery_samples+1 : 0;
                $changes=['recovery_samples'=>$recovery,'last_evaluated_sample_id'=>$r->id,
                    'last_evaluated_at'=>$r->sampled_at];
                if($recovery>=3){$changes['status']='cleared';$changes['cleared_at']=$r->sampled_at;}
                $e->update($changes);
                return ['state'=>$recovery>=3?'cleared':'recovering','event'=>$e];
            },3);
            if($result['state']==='cleared')$recovered[]=$axis;
            if($result['state']==='triggered'){
                $event=$result['event'];
                $triggered[]=['id'=>$event->id,'axis'=>$axis,'job'=>$job,
                    'severity'=>$event->severity,'score'=>(float)$event->score,
                    'robust_z'=>(float)$event->robust_z];
            }
        }
        return ['status'=>'evaluated','job_key'=>$job,'events'=>$triggered,'recovered_axes'=>$recovered,
            'note'=>'Statistical evidence only; three valid distinct recovery samples close events, without implying repair.'];
    }
}
