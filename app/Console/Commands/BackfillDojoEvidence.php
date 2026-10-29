<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Robot;
use App\Models\RobotAlarmSnapshot;
use App\Models\RobotConnectionEvent;
use App\Models\RobotOperatingTimeSample;
use App\Services\Rms\AlarmSnapshotProcessor;
use App\Services\Rms\AlarmTextParser;
use App\Services\Rms\OperatingTimeTextParser;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Backfill existing DOJO evidence safely. Default is read-only DRY RUN. */
final class BackfillDojoEvidence extends Command
{
    protected $signature='rms:dojo-backfill {--robot=1 : Robot DB ID}
        {--apply : Persist parsed alarms and reconcile connection status}
        {--operating-verified : Populate operating seconds ONLY after pendant verification and config opt-in}';
    protected $description='Review/backfill historical YMConnect DOJO alarms, runtime and connection status';

    public function handle(AlarmTextParser $alarmParser, AlarmSnapshotProcessor $alarmProcessor,
        OperatingTimeTextParser $timeParser): int
    {
        $robot=Robot::query()->with('controller')->find((int)$this->option('robot'));
        if (!$robot){$this->error('Robot not found');return self::FAILURE;}
        $apply=(bool)$this->option('apply');
        $hoursVerified=(bool)$this->option('operating-verified');
        if ($hoursVerified && (!$apply || !config('rms_evidence.operating_text_verified',false))) {
            $this->error('Operating-time writes require --apply and RMS_OPERATING_TEXT_VERIFIED=true AFTER actual pendant comparison.');
            return self::FAILURE;
        }
        $this->line('Mode: '.($apply?'APPLY':'DRY RUN').' · Robot: '.$robot->robot_code);
        $snapshots=RobotAlarmSnapshot::query()->where('robot_id',$robot->id)
            ->orderBy('sampled_at')->orderBy('id')->get();
        $seen=0;$partial=0;$unrecognized=0;
        foreach($snapshots as $snapshot){
            if (!$snapshot->read_ok) continue;
            $parsed=$alarmParser->parse($snapshot->sdk_text);
            if(!$parsed['recognized']){$unrecognized++;continue;}
            $seen++;
            if(!$parsed['complete']){
                $partial++;
                $this->warn('PARTIAL snapshot '.$snapshot->id.': reported '.$parsed['reported_count'].' but identifiable '.count($parsed['alarms']));
            }
            if($apply && $snapshot->parsed_alarms===null){
                $alarmProcessor->process($snapshot);
            }
        }
        $this->line("Alarm snapshots: parsed={$seen}, partial={$partial}, unrecognized={$unrecognized}");

        $read=0;$candidates=0;$updated=0;
        RobotOperatingTimeSample::query()->where('robot_id',$robot->id)->where('read_ok',true)
            ->orderBy('sampled_at')->chunkById(200,function($rows) use ($timeParser,$hoursVerified,&$read,&$candidates,&$updated){
                foreach($rows as $r){
                    $read++;
                    $candidate=$timeParser->parse($r->value_raw);
                    if(!$candidate) continue;
                    $candidates++;
                    if($hoursVerified && $r->time_type==='ServoPowerOnTimeRobot'){
                        $r->update(['value_seconds'=>$candidate['seconds'],
                            'raw_payload'=>array_merge($r->raw_payload??[],[
                                'parsed_candidate_seconds'=>$candidate['seconds'],
                                'parser_source'=>$candidate['source'],
                                'verified_for_pm'=>true,
                            ])]);
                        $updated++;
                    }
                }
            });
        $this->line("Operating reads={$read}, parsable={$candidates}, verified-updated={$updated}");
        $controller=$robot->controller;
        if($controller){
            $ids=Robot::query()->where('controller_id',$controller->id)->pluck('id');
            // Use the same real-event JSON filter as the collector connection API.
            // Missing/null flags remain real; JSON true and the string "true"
            // remain excluded when reconciling physical controller status.
            $simulatedValue = match (DB::connection()->getDriverName()) {
                'sqlsrv' => "JSON_VALUE(raw_payload, '$.simulated')",
                'pgsql' => "raw_payload->>'simulated'",
                default => throw new \LogicException(
                    'RMS connection evidence requires SQL Server or PostgreSQL.'
                ),
            };
            $real=RobotConnectionEvent::query()->whereIn('robot_id',$ids)
                ->whereRaw("COALESCE($simulatedValue, 'false') <> 'true'");
            $last=(clone $real)->orderByDesc('occurred_at')->orderByDesc('id')->first();
            $lastOn=(clone $real)->where('state','connected')->orderByDesc('occurred_at')->first();
            $this->line('Controller status from latest real event: '.($last?->state??'unknown'));
            if ($apply && $last) DB::transaction(function() use($controller,$last,$lastOn){
                $locked=\App\Models\RobotController::query()->lockForUpdate()->findOrFail($controller->id);
                $locked->update(['connection_status'=>$last->state,'last_seen_at'=>$lastOn?->occurred_at]);
            });
        }
        $this->info($apply?'Backfill finished. Inspect evidence before enabling health rules.':'Preview only: database unchanged.');
        return self::SUCCESS;
    }
}
