<?php

declare(strict_types=1);

namespace App\Services\Rms;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Query\Builder;

final class TorqueShiftSummary
{
    public const AXES = ['S', 'L', 'U', 'R', 'B', 'T'];

    /**
     * @param  array{robot_ids: array<int, int>, from_utc: string, until_utc: string, torque_mode?: string}  $filters
     * @return array{mode: string, groups: array<int, array<string, mixed>>, has_data: bool}
     */
    public function forFilters(array $filters, Collection $robots): array
    {
        $mode = ($filters['torque_mode'] ?? 'absolute') === 'signed' ? 'signed' : 'absolute';
        $query = $this->samples($filters);
        $statistics = DB::connection()->getDriverName() === 'sqlsrv'
            ? $this->sqlServerStatistics($query, $mode)
            : $this->portableStatistics($query, $mode);

        $groups = [];
        $hasData = false;
        foreach ($robots as $robot) {
            if ($filters['robot_ids'] !== [] && ! in_array((int) $robot->id, $filters['robot_ids'], true)) {
                continue;
            }
            $axes = [];
            foreach (self::AXES as $axis) {
                $reading = $statistics[(int) $robot->id][$axis] ?? $this->emptyReading();
                $hasData = $hasData || $reading['count'] > 0;
                $axes[$axis] = $reading;
            }
            $groups[] = [
                'robot_id' => (int) $robot->id,
                'robot_code' => $robot->robot_code,
                'robot_name' => $robot->name,
                'axes' => $axes,
                'has_data' => collect($axes)->contains(fn (array $axis): bool => $axis['count'] > 0),
            ];
        }

        return ['mode' => $mode, 'groups' => $groups, 'has_data' => $hasData];
    }

    private function samples(array $filters): Builder
    {
        $from = CarbonImmutable::parse($filters['from_utc'])->utc();
        $until = CarbonImmutable::parse($filters['until_utc'])->utc();
        $query = DB::table('robot_telemetry_samples')
            ->where('sampled_at', '>=', $from)
            ->where('sampled_at', '<', $until)
            ->where('torque_ok', true)
            ->where('torque_unit', 'sdk_raw')
            ->whereNotNull('torque_axes')
            ->where(function (Builder $query) use ($from, $until): void {
                $query->whereNull('torque_sampled_at')->orWhere(function (Builder $query) use ($from, $until): void {
                    $query->where('torque_sampled_at', '>=', $from)
                        ->where('torque_sampled_at', '<', $until);
                });
            });
        if ($filters['robot_ids'] !== []) {
            $query->whereIn('robot_id', $filters['robot_ids']);
        }

        return $query;
    }

    /** Aggregate in SQL Server, so a large shift never transfers all telemetry to PHP. */
    private function sqlServerStatistics(Builder $query, string $mode): array
    {
        $axesJson = "CASE WHEN ISJSON(torque_axes) = 1 THEN torque_axes ELSE N'{}' END";
        $payloadJson = "CASE WHEN ISJSON(raw_payload) = 1 THEN raw_payload ELSE N'{}' END";
        $query->select('robot_id')
            ->whereRaw("LOWER(LTRIM(RTRIM(COALESCE(JSON_VALUE($payloadJson, '$.simulated'), 'false')))) NOT IN ('true', '1')");
        foreach (self::AXES as $axis) {
            $column = strtolower($axis);
            $query->selectRaw("TRY_CONVERT(float, NULLIF(LTRIM(RTRIM(JSON_VALUE($axesJson, '$.$axis'))), '')) AS torque_$column");
        }
        $aggregate = DB::query()->fromSub($query, 'torque_readings')->select('robot_id')->groupBy('robot_id');
        foreach (self::AXES as $axis) {
            $column = 'torque_'.strtolower($axis);
            $value = $mode === 'absolute' ? "ABS($column)" : $column;
            $aggregate->selectRaw("COUNT_BIG($column) AS {$column}_count, AVG($value) AS {$column}_average, MAX($value) AS {$column}_maximum");
        }
        $statistics = [];
        foreach ($aggregate->get() as $row) {
            foreach (self::AXES as $axis) {
                $prefix = 'torque_'.strtolower($axis);
                $statistics[(int) $row->robot_id][$axis] = [
                    'count' => (int) $row->{$prefix.'_count'},
                    'average' => $row->{$prefix.'_average'} === null ? null : (float) $row->{$prefix.'_average'},
                    'maximum' => $row->{$prefix.'_maximum'} === null ? null : (float) $row->{$prefix.'_maximum'},
                ];
            }
        }

        return $statistics;
    }

    /** Bounded-memory fallback for PostgreSQL and local SQLite tests. */
    private function portableStatistics(Builder $query, string $mode): array
    {
        $statistics = [];
        $rows = $query->select(['id', 'robot_id', 'torque_axes', 'raw_payload->simulated as simulated'])->lazyById(1000);
        foreach ($rows as $row) {
            $simulated = is_string($row->simulated) ? strtolower(trim($row->simulated)) : $row->simulated;
            if (in_array($simulated, [true, 1, '1', 'true'], true)) {
                continue;
            }
            $axes = is_string($row->torque_axes) ? json_decode($row->torque_axes, true) : $row->torque_axes;
            if (! is_array($axes)) {
                continue;
            }
            foreach (self::AXES as $axis) {
                $raw = $axes[$axis] ?? null;
                if (! is_numeric($raw) || ! is_finite((float) $raw)) {
                    continue;
                }
                $value = $mode === 'absolute' ? abs((float) $raw) : (float) $raw;
                $reading = $statistics[(int) $row->robot_id][$axis] ?? $this->emptyReading();
                $count = $reading['count'] + 1;
                $reading['average'] = $reading['count'] === 0 ? $value
                    : $reading['average'] * ($reading['count'] / $count) + $value / $count;
                $reading['maximum'] = $reading['maximum'] === null ? $value : max($reading['maximum'], $value);
                $reading['count'] = $count;
                $statistics[(int) $row->robot_id][$axis] = $reading;
            }
        }

        return $statistics;
    }

    /** @return array{count: int, average: ?float, maximum: ?float} */
    private function emptyReading(): array
    {
        return ['count' => 0, 'average' => null, 'maximum' => null];
    }
}
