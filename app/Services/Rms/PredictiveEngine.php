<?php

declare(strict_types=1);
namespace App\Services\Rms;

use App\Models\Robot;
use App\Models\RobotPrediction;
use App\Models\RobotPredictionModel;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Transparent calendar-time projection; NEVER claims OEM remaining life or imminent failure. */
final class PredictiveEngine
{
    public function trainRobot(Robot $robot): array
    {
        $result=[];
        foreach(['S','L','U','R','B','T'] as $axis){
            $rows=$robot->hasMany(\App\Models\RobotReducerMeasurement::class)
                ->where('axis',$axis)->where('is_verified',true)
                ->whereNotNull('condition_value')->whereNotNull('condition_unit')
                ->whereNotNull('upper_threshold')->orderByDesc('measured_at')->limit(1000)->get()->reverse()->values();
            $minimum=(int)config('rms_health.prediction_min_measurements',12);
            if($rows->count()<$minimum){$result[$axis]='insufficient_verified_inspections';continue;}
            $last=$rows->last();$unit=(string)$last->condition_unit;$threshold=(float)$last->upper_threshold;
            if($unit===''||!is_finite($threshold)||$threshold<=0){$result[$axis]='invalid_threshold';continue;}
            // Training across unit changes or threshold revisions is unsafe.
            $rows=$rows->filter(static fn($m)=>$m->condition_unit===$unit &&
                abs((float)$m->upper_threshold-$threshold)<0.000001 &&
                filled($m->source_reference))->values();
            if($rows->count()<$minimum){$result[$axis]='inconsistent_units_or_threshold';continue;}
            $origin=CarbonImmutable::parse($rows->first()->measured_at);
            $spanDays=$origin->diffInRealSeconds(CarbonImmutable::parse($rows->last()->measured_at))/86400.0;
            if($spanDays<config('rms_health.prediction_min_span_days',30)){
                $result[$axis]='history_span_too_short';continue;
            }
            $points=[]; $distinct=[];
            foreach($rows as $m){
                $t=CarbonImmutable::parse($m->measured_at);
                $points[]=['x'=>$origin->diffInRealSeconds($t)/86400.0,'y'=>(float)$m->condition_value];
                $distinct[$t->format('Y-m-d H:i:s.u')]=true;
            }
            if(count($distinct)<$minimum){$result[$axis]='insufficient_distinct_measurements';continue;}
            $n=count($points);$nTest=max(3,(int)ceil($n*0.2));
            $train=array_slice($points,0,$n-$nTest);$valid=array_slice($points,-$nTest);
            $validationFit=TrendEstimator::fit($train);
            $fit=TrendEstimator::fit($points);
            if(!$fit||!$validationFit){$result[$axis]='insufficient_variance';continue;}
            $mae=TrendEstimator::mae($valid,$validationFit);
            $rmse=TrendEstimator::rmse($valid,$validationFit);
            $qualityPass=$mae!==null && is_finite($mae)
                && $mae / max(abs($threshold),0.000001) <= (float)config('rms_health.max_prediction_relative_mae',0.15);
            // Technician verification outcomes affect evaluation/versions, not numerical slope
            // until verified condition measurements are supplied.
            $feedbackCounts=RobotPrediction::query()->where('robot_id',$robot->id)->where('axis',$axis)
                ->whereNotNull('technician_verdict')->selectRaw('technician_verdict, count(*) as total')
                ->groupBy('technician_verdict')->pluck('total','technician_verdict')->all();
            $fingerprint=hash('sha256',json_encode([
                'measurements'=>$rows->map(fn($m)=>[$m->id,$m->updated_at?->toIso8601String(),
                    (float)$m->condition_value,$m->condition_unit,(float)$m->upper_threshold])->all(),
                'feedback'=>$feedbackCounts,
            ],JSON_THROW_ON_ERROR));
            $model=DB::transaction(function() use ($robot,$axis,$unit,$threshold,$fit,$mae,$rmse,$origin,$rows,$n,$nTest,$fingerprint,$feedbackCounts){
                Robot::query()->whereKey($robot->id)->lockForUpdate()->firstOrFail();
                $current=RobotPredictionModel::query()->where('robot_id',$robot->id)
                    ->where('axis',$axis)->where('is_current',true)->lockForUpdate()->first();
                if($current && $current->source_fingerprint===$fingerprint)return $current;
                $version=(int)RobotPredictionModel::query()->where('robot_id',$robot->id)->where('axis',$axis)->max('version')+1;
                RobotPredictionModel::query()->where('robot_id',$robot->id)->where('axis',$axis)
                    ->where('is_current',true)->update(['is_current'=>false]);
                return RobotPredictionModel::create([
                    'robot_id'=>$robot->id,'axis'=>$axis,'version'=>$version,
                    'algorithm'=>'linear_trend_verified_inspections','status'=>'experimental',
                    'condition_unit'=>$unit,'threshold_value'=>$threshold,
                    'parameters'=>['intercept'=>$fit['intercept'],'slope_per_day'=>$fit['slope'],
                        'origin'=>$origin->toIso8601String(),'feedback_summary'=>$feedbackCounts,
                        'important'=>'Calendar-time threshold projection; not physical failure/RUL'],
                    'train_count'=>$n-$nTest,'validation_count'=>$nTest,
                    'validation_mae'=>$mae,'validation_rmse'=>$rmse,'trained_at'=>now(),
                    'window_start'=>$rows->first()->measured_at,'window_end'=>$rows->last()->measured_at,
                    'source_fingerprint'=>$fingerprint,'is_current'=>true,
                ]);
            },3);
            $latest=$rows->last();
            $slope=(float)$fit['slope'];$currentValue=(float)$latest->condition_value;
            $remaining=null;$projectedAt=null;$reason=null;$status='not_projectable';
            if($currentValue >= $threshold){
                // The observed threshold exceedance is measured evidence, not a prediction.
                $status='threshold_reached';$remaining=0.0;
                $reason='Verified inspection measurement already meets/exceeds configured upper condition threshold. Inspect promptly.';
            }elseif(!$qualityPass){
                $status='unreliable_model';
                $reason='Chronological holdout MAE exceeds the configured quality limit; projection withheld.';
            }elseif($slope<=0){
                $reason='Condition trend is flat/decreasing, cannot extrapolate upper threshold.';
            }else{
                $xThreshold=($threshold-(float)$fit['intercept'])/$slope;
                $xLatest=$origin->diffInRealSeconds(CarbonImmutable::parse($latest->measured_at))/86400.0;
                $days=$xThreshold-$xLatest;
                if($days>0 && $days<=3650){
                    $status='projected';$remaining=round($days,2);
                    $projectedAt=CarbonImmutable::parse($latest->measured_at)->addSeconds((int)round($days*86400));
                    $reason='Experimental projection of inspection-condition threshold; NOT confirmed damage or OEM RUL.';
                }else{
                    $reason='Regression intercept is inconsistent with current measurement or projection exceeds ten years.';
                }
            }
            RobotPrediction::query()->firstOrCreate(
                ['model_id'=>$model->id,'source_measurement_id'=>$latest->id],
                ['robot_id'=>$robot->id,'axis'=>$axis,'status'=>$status,
                    'predicted_threshold_at'=>$projectedAt,'remaining_calendar_days'=>$remaining,
                    'observed_value'=>$currentValue,'reason'=>$reason,'generated_at'=>now()]
            );
            $result[$axis]=['model_version'=>$model->version,'status'=>$status,'remaining_calendar_days'=>$remaining,
                'validation_mae'=>(float)$model->validation_mae,
                'validation_rmse'=>(float)$model->validation_rmse,
                'quality_pass'=>$qualityPass,'evaluation_feedback'=>$feedbackCounts];
        }
        return $result;
    }
}
