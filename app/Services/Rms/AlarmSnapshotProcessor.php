<?php

declare(strict_types=1);

namespace App\Services\Rms;

use App\Models\Robot;
use App\Models\RobotAlarmEvent;
use App\Models\RobotAlarmSnapshot;
use Illuminate\Support\Facades\DB;

/**
 * Reconciles explicit identifiable SDK alarms. Never sends controller commands.
 * Called only for NEW snapshots, or for historical rows with parsed_alarms NULL.
 */
final class AlarmSnapshotProcessor
{
    public function __construct(private readonly AlarmTextParser $parser) {}

    public function process(RobotAlarmSnapshot $snapshot): array
    {
        if (!$snapshot->read_ok) return ['state'=>'read_failed'];
        if (($snapshot->raw_payload['simulated'] ?? false) === true) return ['state'=>'simulated_ignored'];
        $result = $this->parser->parse($snapshot->sdk_text);
        if (!$result['recognized']) return ['state'=>'raw_unrecognized'];

        return DB::transaction(function () use ($snapshot, $result): array {
            // Per-robot synchronization avoids concurrent event creation on collector retries.
            Robot::query()->whereKey($snapshot->robot_id)->lockForUpdate()->firstOrFail();
            $snapshot->refresh();
            if ($snapshot->parsed_alarms !== null) return ['state'=>'already_processed'];

            $snapshot->update([
                'active_alarm_count'=>$result['reported_count'],
                'parsed_alarms'=>$result['alarms'],
            ]);
            $identified = [];
            foreach ($result['alarms'] as $a) {
                $active = RobotAlarmEvent::query()
                    ->where('robot_id',$snapshot->robot_id)
                    ->where('alarm_code',$a['alarm_code'])
                    ->where('subcode',$a['subcode'])
                    ->where('alarm_name',$a['alarm_name'])
                    ->where('status','active')
                    ->lockForUpdate()->first();
                if ($active) {
                    if ($active->last_seen_at === null || $active->last_seen_at->lessThan($snapshot->sampled_at)) {
                        $active->update(['last_seen_at'=>$snapshot->sampled_at]);
                    }
                    $identified[] = $active->id;
                    continue;
                }
                // Preserve a new occurrence, not a permanent unique code for all recurrences.
                $event = RobotAlarmEvent::create([
                    'robot_id'=>$snapshot->robot_id,
                    'event_key'=>'sdk-'.$snapshot->id.'-'.substr(hash('sha256',json_encode($a)),0,24),
                    'alarm_code'=>$a['alarm_code'], 'subcode'=>$a['subcode'],
                    'alarm_name'=>$a['alarm_name'], 'severity'=>null,
                    'status'=>'active','started_at'=>$snapshot->sampled_at,
                    'last_seen_at'=>$snapshot->sampled_at,'source'=>'ymconnect',
                    'details'=>[
                        'source_snapshot_id'=>$snapshot->id,
                        'controller_time_raw'=>$a['controller_time_raw'],
                        'reported_count'=>$result['reported_count'],
                        'details_complete'=>$result['complete'],
                        'severity_verified'=>false,
                    ],
                ]);
                $identified[] = $event->id;
            }
            // ONLY a fully recognized ZERO snapshot proves no active alarms.
            // Partial reports never justify closing an alarm that is not listed.
            if ($result['complete'] && $result['reported_count'] === 0) {
                RobotAlarmEvent::query()->where('robot_id',$snapshot->robot_id)
                    ->where('status','active')
                    ->where('started_at','<=',$snapshot->sampled_at)
                    ->where(function ($q) use ($snapshot) {
                        $q->whereNull('last_seen_at')->orWhere('last_seen_at','<=',$snapshot->sampled_at);
                    })->update(['status'=>'cleared','cleared_at'=>$snapshot->sampled_at]);
            }
            // Nonzero complete list is not used to close *missing* alarms yet; a
            // later explicit clean snapshot avoids false clears due to raw truncation.
            return ['state'=>$result['complete']?'parsed':'partial',
                'reported_count'=>$result['reported_count'],
                'identified_count'=>count($identified), 'event_ids'=>$identified];
        },3);
    }
}
