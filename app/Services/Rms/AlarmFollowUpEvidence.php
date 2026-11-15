<?php

declare(strict_types=1);

namespace App\Services\Rms;

use App\Models\RobotAlarmFollowUp;
use App\Models\RobotAlarmHistoryEntry;
use App\Models\RobotOperatingTimeSample;
use App\Models\RobotTelemetrySample;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class AlarmFollowUpEvidence
{
    public const AXES = ['S', 'L', 'U', 'R', 'B', 'T'];

    public function __construct(
        private readonly AlarmCountermeasureStore $guides,
        private readonly AlarmParetoSummary $pareto,
        private readonly OperatingTimeDisplay $hours,
    ) {}

    public function capture(array $context): array
    {
        $robot = $context['robot'];
        $robotId = $context['robot_id'];
        $anchor = CarbonImmutable::parse($context['observed_at'])->utc();
        $history = RobotAlarmHistoryEntry::query()->where('robot_id', $robotId);
        $faults = $this->pareto->fromHistoryQuery($history);
        $latest = (clone $history)->orderByDesc('first_observed_at')->orderByDesc('id')->limit(8)->get()
            ->map(fn (RobotAlarmHistoryEntry $entry) => [
                'code' => $entry->alarm_code, 'subcode' => $entry->subcode, 'name' => $entry->alarm_name,
                'category' => $entry->category, 'raw' => $entry->controller_time_raw,
                'seen' => self::wib($entry->first_observed_at->toIso8601String()),
            ])->all();

        $powerHours = [];
        foreach (['ServoPowerOnTimeRobot' => 'servo_hours', 'ControllerOnTime' => 'controller_hours'] as $type => $key) {
            $sample = RobotOperatingTimeSample::query()->where('robot_id', $robotId)
                ->where('time_type', $type)->where('read_ok', true)->where('sampled_at', '<=', self::dbTime($anchor))
                ->orderByDesc('sampled_at')->orderByDesc('id')->first();
            $reading = $this->isSimulated($sample?->raw_payload) ? null : $sample;
            $powerHours[$key] = $this->hours->forSample($reading, $type);
        }

        $references = [];
        foreach (['yaskawa_manual', 'field_experience'] as $source) {
            $references[$source] = $this->guides->matching($context)->where('is_active', true)
                ->where('source_type', $source)->orderBy('all_subcodes')->orderByDesc('id')->limit(2)->get()
                ->map(fn ($guide) => $guide->only([
                    'id', 'version', 'title', 'alarm_content', 'cause', 'measure', 'source_reference',
                    'source_revision', 'reported_by', 'work_area', 'result', 'result_notes',
                ]))->all();
        }

        $previous = RobotAlarmFollowUp::query()->where('robot_id', $robotId)
            ->where('alarm_code', $context['alarm_code'])->where('subcode', $context['subcode'])
            ->where('status', 'approved')->where('alarm_observed_at', '<', self::dbTime($anchor))
            ->orderByDesc('alarm_observed_at')->orderByDesc('id')->first();

        return [
            'captured_at' => now()->utc()->toIso8601String(),
            'robot' => [
                'code' => $robot->robot_code, 'name' => $robot->name, 'model' => $robot->model,
                'serial' => $robot->serial_number, 'group' => $robot->control_group,
                'line' => $robot->line_name, 'station' => $robot->station_name,
                'controller_code' => $robot->controller?->controller_code,
                'controller_model' => $context['controller_model'], 'firmware' => $robot->controller?->firmware_version,
                ...$powerHours,
            ],
            'alarm' => array_intersect_key($context, array_flip(['alarm_code', 'subcode', 'alarm_name', 'controller_time_raw', 'category', 'observed_at', 'kind', 'record'])),
            'pareto' => $faults,
            'history' => $latest,
            'references' => $references,
            'previous' => $previous ? ['number' => $previous->number(), 'work' => $previous->work] : null,
            'trend' => $this->trend($robotId, $anchor),
        ];
    }

    private function trend(int $robotId, CarbonImmutable $anchor): array
    {
        $from = $anchor->subMinutes(15);
        $until = $anchor->addMinutes(15);
        $samples = collect();
        // Thirty one-minute windows select actual reads without interpolating or loading raw history.
        for ($bucket = 0; $bucket < 30; $bucket++) {
            $begin = $from->addMinutes($bucket);
            $row = RobotTelemetrySample::query()->where('robot_id', $robotId)
                ->where('sampled_at', '>=', self::dbTime($begin))
                ->where('sampled_at', '<', self::dbTime($begin->addMinute()))
                ->orderByDesc('sampled_at')->orderByDesc('id')->first([
                    'id', 'sampled_at', 'torque_ok', 'torque_unit', 'torque_axes',
                    'position_ok', 'position_pulses', 'raw_payload',
                ]);
            $samples->push($row !== null && ! $this->isSimulated($row->raw_payload) ? $row : null);
        }
        $unit = $samples->filter(fn ($row) => $row?->torque_ok && filled($row->torque_unit))
            ->sortBy(fn ($row) => abs($row->sampled_at->getTimestamp() - $anchor->getTimestamp()))->first()?->torque_unit;

        return [
            'from' => self::wib($from->toIso8601String()), 'until' => self::wib($until->toIso8601String()),
            'torque_unit' => $unit === 'sdk_raw' ? 'SDK raw' : $unit,
            'torque' => $this->series($samples, $from, 'torque_axes', fn ($row) => $row->torque_ok && $unit !== null && $row->torque_unit === $unit),
            'position' => $this->series($samples, $from, 'position_pulses', fn ($row) => $row->position_ok),
        ];
    }

    private function series(Collection $samples, CarbonImmutable $from, string $field, callable $eligible): array
    {
        return collect(self::AXES)->mapWithKeys(fn (string $axis) => [
            $axis => $samples->map(function ($row) use ($from, $axis, $field, $eligible): ?array {
                if (! $row || ! $eligible($row)) {
                    return null;
                }
                $value = $row->{$field}[$axis] ?? null;

                return is_numeric($value) && is_finite((float) $value)
                    ? ['minute' => $from->diffInMinutes($row->sampled_at), 'value' => (float) $value] : null;
            })->all(),
        ])->all();
    }

    private function isSimulated(?array $payload): bool
    {
        return in_array($payload['simulated'] ?? null, [true, 1, '1', 'true'], true);
    }

    private static function dbTime(CarbonImmutable $time): string
    {
        return DB::connection()->getDriverName() === 'sqlite'
            ? $time->timezone(config('app.timezone'))->format('Y-m-d H:i:s')
            : $time->format('Y-m-d H:i:s.vP');
    }

    public static function wib(?string $value): string
    {
        return $value ? CarbonImmutable::parse($value)->timezone('Asia/Jakarta')->format('d/m/Y H:i:s').' WIB' : 'Belum tersedia';
    }
}
