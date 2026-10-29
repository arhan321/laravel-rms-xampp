<?php

declare(strict_types=1);

namespace App\Services\Rms;

use App\Models\RobotThresholdEvent;
use App\Models\RobotThresholdRule;
use App\Models\RobotTelemetrySample;
use Illuminate\Support\Facades\DB;

/**
 * Read-only YMConnect torque evaluation, triggered from CLI after DB ingestion.
 * Strictly no writes to the controller; only writes derived RMS threshold events.
 * Raw SDK values must have a technician-approved source/unit before enabling a rule.
 */
final class ThresholdEvaluator
{
    public function evaluateRule(RobotThresholdRule $candidate): string
    {
        if (!$candidate->isApproved() || $candidate->unit !== 'sdk_raw' || $candidate->metric !== 'torque_magnitude') {
            return 'unapproved';
        }

        return DB::transaction(function () use ($candidate): string {
            // Serialize evaluators of the same robot/axis and guard duplicate open events.
            $rule = RobotThresholdRule::query()->lockForUpdate()->findOrFail($candidate->id);
            if (!$rule->isApproved()) return 'unapproved';

            $samples = RobotTelemetrySample::query()
                ->where('robot_id', $rule->robot_id)
                ->orderByDesc('sampled_at')->orderByDesc('id')->limit(3)->get();
            if ($samples->count() !== 3) return 'insufficient_data';
            $newest = $samples->first();
            if ($rule->last_evaluated_sample_id === $newest->id) return 'unchanged';

            $now = now();
            $values = [];
            foreach ($samples as $s) {
                if (!$s->torque_ok || $s->torque_unit !== $rule->unit || !is_array($s->torque_axes)) {
                    return 'invalid_read';
                }
                // Reads older than 30 s and any future clock drift > 5 s are not current.
                if ($s->sampled_at === null || $s->sampled_at->lt($now->copy()->subSeconds(30)) ||
                    $s->sampled_at->gt($now->copy()->addSeconds(5))) return 'stale';
                $v = $s->torque_axes[$rule->axis] ?? null;
                if (!is_numeric($v) || !is_finite((float)$v)) return 'missing_axis';
                $values[] = abs((float) $v);
            }
            $ordered = $samples->sortBy('sampled_at')->values();
            for ($i = 1; $i < $ordered->count(); $i++) {
                if (abs($ordered[$i]->sampled_at->diffInRealSeconds($ordered[$i - 1]->sampled_at)) > 15) return 'sample_gap';
            }

            $warning = (float) $rule->warning_magnitude;
            $critical = (float) $rule->critical_magnitude;
            $clear = $warning * (1.0 - (float) $rule->clear_hysteresis_percent / 100.0);
            $allCritical = collect($values)->every(fn (float $v) => $v >= $critical);
            $allWarning = collect($values)->every(fn (float $v) => $v >= $warning);
            $allClear = collect($values)->every(fn (float $v) => $v < $clear);
            $event = RobotThresholdEvent::query()->where('threshold_rule_id', $rule->id)
                ->where('status', 'open')->lockForUpdate()->first();

            $result = 'within_range_or_pending';
            if ($allWarning) {
                $severity = $allCritical ? 'critical' : 'warning';
                if ($event) {
                    // Never downgrade an active critical event to warning.
                    $event->severity = $event->severity === 'critical' ? 'critical' : $severity;
                    $event->peak_magnitude = max((float) $event->peak_magnitude, ...$values);
                    $event->last_seen_at = $newest->sampled_at;
                    $event->save();
                    $result = 'event_updated';
                } else {
                    RobotThresholdEvent::query()->create([
                        'robot_id'=>$rule->robot_id,'threshold_rule_id'=>$rule->id,
                        'trigger_sample_id'=>$newest->id,'axis'=>$rule->axis,
                        'severity'=>$severity,'status'=>'open',
                        'peak_magnitude'=>max($values),'started_at'=>$ordered->first()->sampled_at,
                        'last_seen_at'=>$newest->sampled_at,
                        'note'=>'3 consecutive verified readouts above approved magnitude limit; review by maintenance required.',
                    ]);
                    $result = 'event_opened';
                }
            } elseif ($event && $allClear) {
                $event->update(['status'=>'closed', 'closed_at'=>$newest->sampled_at,
                    'note'=>'3 consecutive valid samples below the hysteresis clear boundary. Event closed automatically; equipment repair not implied.']);
                $result = 'event_closed';
            }
            $rule->last_evaluated_sample_id = $newest->id;
            $rule->save();
            return $result;
        }, 3);
    }
}
