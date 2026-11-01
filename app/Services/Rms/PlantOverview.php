<?php

declare(strict_types=1);

namespace App\Services\Rms;

use App\Models\MaintenanceRecommendation;
use App\Models\RobotAlarmEvent;
use App\Models\RobotThresholdRule;
use Illuminate\Support\Collection;

/** Read-only projection for the plant board; never predicts remaining service life. */
final class PlantOverview
{
    public function forCards(Collection $cards, Collection $groups, ?Collection $rules = null): array
    {
        $ids = $cards->pluck('robot.id')->all();
        $rules ??= RobotThresholdRule::query()->whereIn('robot_id', $ids)->get()->groupBy('robot_id');
        $recommendations = MaintenanceRecommendation::query()->whereIn('robot_id', $ids)
            ->where('status', 'open')->selectRaw('robot_id, COUNT(*) AS total')->groupBy('robot_id')->pluck('total', 'robot_id');
        $robots = $cards->map(function (array $card) use ($rules, $recommendations): array {
            $robot = $card['robot'];
            $sample = $card['telemetry'];
            $axisRules = $rules->get($robot->id, collect())->keyBy('axis');
            $axes = [];
            foreach (['S', 'L', 'U', 'R', 'B', 'T'] as $axis) {
                $raw = $card['telemetry_fresh'] && $sample?->torque_ok && $sample->torque_unit === 'sdk_raw'
                    ? ($sample->torque_axes[$axis] ?? null) : null;
                $value = is_numeric($raw) && is_finite((float) $raw) ? (float) $raw : null;
                $rule = $axisRules->get($axis);
                $approved = $rule && $rule->isApproved() && $rule->unit === 'sdk_raw';
                $axes[$axis] = $value === null ? 'unavailable' : ($approved ? 'normal' : 'not_configured');
                if ($value !== null && $approved) {
                    $axes[$axis] = abs($value) >= (float) $rule->critical_magnitude ? 'critical'
                        : (abs($value) >= (float) $rule->warning_magnitude ? 'warning' : 'normal');
                }
            }

            return [
                'id' => $robot->id, 'code' => $robot->robot_code, 'name' => $robot->name,
                'group_key' => $robot->group?->group_key ?? 'unassigned',
                'url' => route('rms.robots.show', $robot),
                'connectivity' => $card['connectivity'], 'health' => $card['health_display']['status'],
                'servo' => $card['servo']['label'], 'axes' => $axes,
                'operating_hours' => $card['operating_display']['text'],
                'open_recommendations' => (int) ($recommendations->get($robot->id) ?? 0),
            ];
        })->values();
        $groupRows = $groups->map(function (array $group) use ($robots): array {
            $members = $robots->where('group_key', $group['key'])->values();

            return [...$group, 'maintenance' => $members->where('open_recommendations', '>', 0)->count(), 'robots' => $members->all()];
        })->values();
        $alarms = RobotAlarmEvent::query()->with('robot')->whereIn('robot_id', $ids)
            ->orderByDesc('started_at')->orderByDesc('id')->limit(6)->get()->map(fn (RobotAlarmEvent $alarm): array => [
                'id' => (string) $alarm->id, 'robot_code' => $alarm->robot?->robot_code ?? 'Unknown robot',
                'name' => $alarm->alarm_name ?: ($alarm->alarm_code ?: 'Alarm teridentifikasi'),
                'code' => $alarm->alarm_code, 'status' => $alarm->status,
                'time' => $alarm->started_at?->timezone('Asia/Jakarta')->format('d M H:i:s').' WIB',
                'url' => route('rms.alarms.countermeasures.show', ['kind' => 'event', 'record' => $alarm->id]),
            ])->all();

        return ['groups' => $groupRows->all(), 'latest_alarms' => $alarms,
            'maintenance_robots' => $robots->where('open_recommendations', '>', 0)->count()];
    }
}
