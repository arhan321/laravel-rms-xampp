<?php

declare(strict_types=1);

namespace App\Services\Rms;

use App\Models\MaintenanceRecommendation;
use App\Models\Robot;
use App\Models\RobotHealthAssessment;
use App\Models\RobotThresholdEvent;
use App\Models\RobotThresholdRule;
use Illuminate\Support\Facades\DB;

/** Derived assessment, NOT an OEM safety function and NOT a robot command. */
final class RobotHealthEngine
{
    public function assess(Robot $robot): array
    {
        $now = now();
        $sample = $robot->telemetrySamples()->latest('sampled_at')->first();
        $fresh = $sample && $sample->sampled_at
            && $sample->sampled_at->betweenIncluded($now->copy()->subSeconds(config('rms_health.fresh_seconds', 30)), $now->copy()->addSeconds(5))
            && $sample->torque_ok && $sample->torque_unit === 'sdk_raw' && is_array($sample->torque_axes);
        $snapshot = $robot->alarmSnapshots()->latest('sampled_at')->first();
        $alarmKnown = $snapshot && $snapshot->read_ok && $snapshot->active_alarm_count !== null
            && $snapshot->sampled_at && $snapshot->sampled_at->betweenIncluded($now->copy()->subSeconds(config('rms_health.alarm_fresh_seconds', 60)), $now->copy()->addSeconds(5));
        $rules = RobotThresholdRule::query()->where('robot_id', $robot->id)
            ->where('metric', 'torque_magnitude')->get()->keyBy('axis');
        $events = RobotThresholdEvent::query()->where('robot_id',$robot->id)->where('status','open')->get()->keyBy('axis');
        $alarms = $robot->alarmEvents()->where('status','active')->get();
        $axisHealth=[]; $badAxes=[]; $complete=true; $risk='healthy';
        foreach(['S','L','U','R','B','T'] as $axis) {
            $rule = $rules->get($axis);
            $val = $fresh ? ($sample->torque_axes[$axis] ?? null) : null;
            $usable = $rule && $rule->isApproved() && $rule->unit === 'sdk_raw'
                && is_numeric($val) && is_finite((float)$val);
            $event = $events->get($axis);
            $state = !$usable ? 'unknown' : ($event ? $event->severity : 'healthy');
            if (!$usable) $complete=false;
            if (in_array($state,['warning','critical'],true)) {
                $badAxes[]=$axis;
                if ($state==='critical') $risk='critical';
                elseif($risk!=='critical')$risk='warning';
            }
            $axisHealth[$axis]=['state'=>$state,'ng'=>$state==='warning'||$state==='critical',
                'value'=>$usable ? (float)$val : null,'unit'=>'sdk_raw',
                'approved_threshold'=> (bool)($rule && $rule->isApproved()),
                'event_id'=>$event?->id];
        }
        $alarmSeverity='none';
        foreach($alarms as $alarm) {
            $sev=strtolower((string)$alarm->severity);
            if (in_array($sev,['critical','fatal','major','error'],true)) {$alarmSeverity='critical';break;}
            $alarmSeverity='warning';
        }
        if ($alarmKnown && (int)$snapshot->active_alarm_count>0 && $alarmSeverity==='none')$alarmSeverity='unclassified';
        if ($alarmSeverity==='critical')$risk='critical';
        elseif($alarmSeverity!=='none' && $risk!=='critical')$risk='warning';

        $runtime = $robot->operatingTimeSamples()
            ->where('time_type','ServoPowerOnTimeRobot')->where('read_ok',true)
            ->whereNotNull('value_seconds')->latest('sampled_at')->first();
        $hours = $runtime ? (float)$runtime->value_seconds/3600.0 : null;
        $pm=[]; $due=[];
        foreach($robot->maintenancePlans()->where('is_active',true)->get() as $plan) {
            $left=null;
            if ($plan->basis==='operating_hours' && $hours !== null && $plan->next_due_operating_hours !== null) {
                $left=round((float)$plan->next_due_operating_hours-$hours,2);
                $isDue=$left<=0;
                $dueDate=null;
            } elseif($plan->basis==='calendar' && $plan->next_due_at !== null) {
                $isDue=$plan->next_due_at->lessThanOrEqualTo($now);
                $dueDate=$plan->next_due_at->toIso8601String();
            } else {
                $isDue=false; $dueDate=null;
            }
            if($isDue){$due[]=$plan->title;if($risk==='healthy')$risk='warning';}
            $pm[]=['plan_id'=>$plan->id,'title'=>$plan->title,'basis'=>$plan->basis,
                'lifetime_to_service_hours'=>$left,'due_at'=>$dueDate,
                'due'=>$isDue,'data_available'=>$left!==null||$dueDate!==null];
        }
        // No fresh full data => NEVER claim a robot is healthy (or predict stopped/running).
        $hasSufficientEvidence=$fresh && $complete && $alarmKnown;
        // Known, evidenced warnings remain actionable even if other inputs are missing.
        // But NEVER call a robot healthy without complete fresh evidence.
        $status=($risk==='healthy' && !$hasSufficientEvidence) ? 'unknown' : $risk;
        $reason = $risk!=='healthy' && !$hasSufficientEvidence
            ? 'Ada sinyal gangguan atau PM due, tetapi sebagian data health belum lengkap. Perlu inspeksi teknisi.'
            : (!$fresh ? 'Telemetry torque tidak segar/valid: health UNKNOWN.'
            : (!$complete ? 'Sebagian axis tidak mempunyai data atau threshold yang disetujui.'
            : (!$alarmKnown ? 'Status alarm segar/valid belum tersedia; tidak dapat menyatakan healthy.'
            : ($status==='healthy' ? 'Seluruh enam axis sesuai aturan tersetujui dan snapshot alarm valid menunjukkan nol alarm aktif.'
            : 'Terdapat indikator abnormal atau PM jatuh tempo; verifikasi langsung oleh teknisi.'))));
        // Verified OEM/technician life evidence can be displayed but is NOT inferred
        // from current raw torque. Avoid assigning an arbitrary failure date.
        $verifiedReducers = \App\Models\RobotReducerMeasurement::query()
            ->where('robot_id',$robot->id)->where('is_verified',true)
            ->orderByDesc('measured_at')->limit(150)->get()->unique('axis');
        $reducerLife=[];
        foreach(['S','L','U','R','B','T'] as $axis){
            $m=$verifiedReducers->get($axis);
            $reducerLife[$axis]=$m && $m->remaining_life_hours!==null ? [
                'hours'=>(float)$m->remaining_life_hours,
                'measured_at'=>$m->measured_at?->toIso8601String(),
                'source'=>$m->source_type,'reference'=>$m->source_reference,
            ] : null;
        }
        $indicators=[
            'reducer_verified_life'=>$reducerLife,
            'axis'=>$axisHealth,'ng_axes'=>$badAxes,'active_alarm_severity'=>$alarmSeverity,
            'alarm_count'=>$alarmKnown ? (int)$snapshot->active_alarm_count : null,
            'alarm_data_current'=>(bool)$alarmKnown,'telemetry_current'=>(bool)$fresh,
            'evidence_complete'=>(bool)$hasSufficientEvidence,
            'servo_operating_hours'=>$hours,'maintenance'=>$pm,'due_maintenance'=>$due,
            'last_telemetry_at'=>$sample?->sampled_at?->toIso8601String(),
            'last_alarm_at'=>$snapshot?->sampled_at?->toIso8601String(),
            'source'=>'Controller monitoring data + approved RMS rules; not an OEM health estimate',
        ];
        $fingerprint=hash('sha256',json_encode([$status,$reason,$indicators],JSON_THROW_ON_ERROR));
        $indicators['fingerprint']=$fingerprint;
        return ['robot_id'=>$robot->id,'status'=>$status,'summary'=>$reason,'indicators'=>$indicators];
    }

    public function assessAndStore(Robot $robot): RobotHealthAssessment
    {
        return DB::transaction(function () use ($robot): RobotHealthAssessment {
            // Prevent duplicate concurrent appends, but DO NOT force a status to healthy.
            Robot::query()->whereKey($robot->id)->lockForUpdate()->firstOrFail();
            $result=$this->assess($robot);
            $last=$robot->healthAssessments()->latest('evaluated_at')->latest('id')->first();
            if($last && ($last->indicators['fingerprint']??null)===$result['indicators']['fingerprint'])return $last;
            $record=RobotHealthAssessment::create([
                'robot_id'=>$robot->id,'health_status'=>$result['status'],
                'evaluated_at'=>now(),'rule_version'=>'rms-health-v1',
                'assessment_source'=>'rms_rule_engine','summary'=>$result['summary'],
                'indicators'=>$result['indicators'],
            ]);
            // Only actionable, verified events and due PM generate maintenance advice.
            $actions=[];
            foreach($result['indicators']['axis'] as $axis=>$a) if($a['ng']){
                $actions[]=['key'=>'axis_'.$axis,'priority'=>$a['state']==='critical'?'critical':'high',
                    'title'=>'Inspect torque axis '.$axis,'text'=>'Tinjau event threshold terverifikasi, beban job, trend dan lakukan inspeksi sebelum reset/repair.'];
            }
            if($result['indicators']['active_alarm_severity']!=='none'){
                $actions[]=['key'=>'active_alarm','priority'=>'high','title'=>'Review active robot alarms',
                    'text'=>'Baca kode alarm dan dokumentasi resmi; verifikasi kejadian pada controller dan tindak lanjuti sesuai SOP.'];
            }
            foreach($result['indicators']['maintenance'] as $plan) if($plan['due']){
                $actions[]=['key'=>'pm_'.$plan['plan_id'],'priority'=>'medium','title'=>'PM due: '.$plan['title'],
                    'text'=>'Jadwalkan preventive maintenance berdasarkan jam operasi/tanggal jatuh tempo terkonfigurasi.'];
            }
            foreach($actions as $a){
                $existing=MaintenanceRecommendation::query()->where('robot_id',$robot->id)
                    ->where('source','rms_health_rule')->where('title',$a['title'])
                    ->whereIn('status',['open','acknowledged'])->first();
                if(!$existing) MaintenanceRecommendation::create([
                    'robot_id'=>$robot->id,'robot_health_assessment_id'=>$record->id,
                    'source'=>'rms_health_rule','priority'=>$a['priority'],'status'=>'open',
                    'title'=>$a['title'],'recommendation_text'=>$a['text'],
                    'evidence'=>['rule_key'=>$a['key'],'assessment_id'=>$record->id],
                    'generated_at'=>now(),
                ]);
            }
            return $record;
        },3);
    }
}
