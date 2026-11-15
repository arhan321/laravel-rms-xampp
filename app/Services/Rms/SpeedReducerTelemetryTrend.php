<?php

declare(strict_types=1);

namespace App\Services\Rms;

use App\Models\Robot;
use App\Support\RmsDatabase;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use LogicException;

final class SpeedReducerTelemetryTrend
{
    public const GROUP = 'R1';

    public const DAYS = 30;

    public function forRobot(Robot $robot): Collection
    {
        if (str_starts_with(strtoupper((string) $robot->robot_code), 'DEMO_')) {
            return collect();
        }

        $now = CarbonImmutable::now('Asia/Jakarta');
        $from = $now->startOfDay()->subDays(self::DAYS - 1);
        $key = 'rms.reducer.torque.v1.'.$robot->getKey().'.'.$now->toDateString();

        return Cache::remember($key, 90, fn () => $this->dailyRows($robot, $from->utc(), $now->utc()));
    }

    private function dailyRows(Robot $robot, CarbonImmutable $from, CarbonImmutable $until): Collection
    {
        $driver = DB::connection()->getDriverName();
        [$day, $group, $simulated] = match ($driver) {
            'sqlsrv' => [
                RmsDatabase::jakartaDay(),
                "JSON_VALUE(CASE WHEN ISJSON(raw_payload) = 1 THEN raw_payload ELSE N'{}' END, '$.group')",
                "JSON_VALUE(CASE WHEN ISJSON(raw_payload) = 1 THEN raw_payload ELSE N'{}' END, '$.simulated')",
            ],
            'pgsql' => [RmsDatabase::jakartaDay(), "raw_payload->>'group'", "raw_payload->>'simulated'"],
            'sqlite' => [
                "date(sampled_at, '+7 hours')",
                "json_extract(CASE WHEN json_valid(raw_payload) THEN raw_payload ELSE '{}' END, '$.group')",
                "json_extract(CASE WHEN json_valid(raw_payload) THEN raw_payload ELSE '{}' END, '$.simulated')",
            ],
            default => throw new LogicException('Speed Reducer telemetry requires SQL Server, PostgreSQL or SQLite.'),
        };

        $samples = DB::table('robot_telemetry_samples')
            ->selectRaw("$day AS date_wib")
            ->where('robot_id', $robot->getKey())
            ->whereBetween('sampled_at', [$from, $until])
            ->where('torque_ok', true)
            ->where('torque_unit', 'sdk_raw')
            ->whereNotNull('torque_axes')
            // Earlier RMS telemetry had no group field and used R1.
            ->whereRaw("UPPER(TRIM(COALESCE($group, 'R1'))) = ?", [self::GROUP])
            ->whereRaw("LOWER(TRIM(COALESCE(CAST($simulated AS varchar(16)), 'false'))) NOT IN ('true', '1')");

        foreach (TorqueShiftSummary::AXES as $axis) {
            $alias = 'torque_'.strtolower($axis);
            $value = $this->axisValue($driver, $axis);
            $samples->selectRaw("ABS($value) AS $alias");
        }

        $daily = DB::query()->fromSub($samples, 'reducer_torque_samples')
            ->select('date_wib')->groupBy('date_wib')->orderBy('date_wib');
        foreach (TorqueShiftSummary::AXES as $axis) {
            $alias = 'torque_'.strtolower($axis);
            $daily->selectRaw("COUNT($alias) AS {$alias}_n, AVG($alias) AS {$alias}_avg,
                CASE WHEN COUNT($alias) >= 2 THEN MAX($alias) - MIN($alias) END AS {$alias}_range");
        }

        return $daily->get()->flatMap(function (object $day): array {
            $rows = [];
            foreach (TorqueShiftSummary::AXES as $axis) {
                $alias = 'torque_'.strtolower($axis);
                $count = (int) $day->{$alias.'_n'};
                if ($count === 0) {
                    continue;
                }
                $rows[] = [
                    'axis' => $axis,
                    'date_wib' => (string) $day->date_wib,
                    'measurement_sdk_raw' => round((float) $day->{$alias.'_avg'}, 6),
                    'variation_sdk_raw' => $day->{$alias.'_range'} === null
                        ? null : round((float) $day->{$alias.'_range'}, 6),
                    'sample_count' => $count,
                    'source_reference' => 'Telemetry Data Logger C#',
                ];
            }

            return $rows;
        })->values();
    }

    private function axisValue(string $driver, string $axis): string
    {
        if ($driver === 'sqlsrv') {
            $json = "CASE WHEN ISJSON(torque_axes) = 1 THEN torque_axes ELSE N'{}' END";

            return "TRY_CONVERT(float, NULLIF(TRIM(JSON_VALUE($json, '$.$axis')), ''))";
        }

        if ($driver === 'pgsql') {
            $value = "TRIM(torque_axes->>'$axis')";

            return "CASE WHEN $value ~ '^[+-]?([0-9]{1,20}(\\.[0-9]{0,18})?|\\.[0-9]{1,18})([eE][+-]?[0-9]{1,2})?$' THEN CAST($value AS double precision) END";
        }

        $json = "CASE WHEN json_valid(torque_axes) THEN torque_axes ELSE '{}' END";

        return "CASE WHEN json_type($json, '$.$axis') IN ('integer', 'real') THEN json_extract($json, '$.$axis') END";
    }
}
