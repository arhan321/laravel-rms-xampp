<?php

declare(strict_types=1);

namespace App\Services\Rms;

use App\Models\Robot;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Query\Builder;

/**
 * Read-only analysis of directly tagged YMConnect job/torque telemetry.
 * A job name is only a controller text candidate; it is NOT an OEM-verified
 * job boundary. Untagged samples are NEVER attributed to the previous job.
 */
final class JobTorqueSummary
{
    public const AXES = ['S', 'L', 'U', 'R', 'B', 'T'];

    public const MAX_JOBS = 200;

    /** @param array{from_utc:string,until_utc:string,job:?string,mode:string} $filters */
    public function forRobot(Robot $robot, array $filters): array
    {
        $all = $this->baseSamples($robot, $filters);
        $totalTorque = (clone $all)->count();
        $tagged = (clone $all)->whereNotNull('job_name')
            ->whereRaw("NULLIF(LTRIM(RTRIM(job_name)), '') IS NOT NULL");
        $taggedTorque = (clone $tagged)->count();
        $lastTagged = (clone $tagged)->orderByDesc('sampled_at')->orderByDesc('id')
            ->first(['job_name', 'sampled_at']);

        if ($filters['job'] !== null) {
            $tagged->where('job_name', $filters['job']);
        }

        $rows = DB::connection()->getDriverName() === 'sqlsrv'
            ? $this->sqlServerAggregate($tagged, $filters['mode'])
            : $this->portableAggregate($tagged, $filters['mode']);

        return [
            'groups' => $rows,
            'total_torque_samples' => $totalTorque,
            'tagged_torque_samples' => $taggedTorque,
            'last_tagged_job' => $lastTagged === null ? null : (string) $lastTagged->job_name,
            'last_tagged_at' => $lastTagged === null ? null : $this->wib($lastTagged->sampled_at),
            'coverage_percent' => $totalTorque > 0 ? round($taggedTorque * 100 / $totalTorque, 1) : null,
            'selected_job' => $filters['job'],
            'mode' => $filters['mode'],
            'unit' => 'sdk_raw',
            'max_jobs' => self::MAX_JOBS,
            'truncated' => count($rows) > self::MAX_JOBS,
        ];
    }

    /** @param array{from_utc:string,until_utc:string} $filters */
    public function jobOptions(Robot $robot, array $filters): array
    {
        return $this->baseSamples($robot, $filters)
            ->whereNotNull('job_name')
            ->whereRaw("NULLIF(LTRIM(RTRIM(job_name)), '') IS NOT NULL")
            ->select('job_name')
            ->distinct()->orderBy('job_name')
            ->limit(500)->pluck('job_name')->all();
    }

    /**
     * Count only real, successful, unit-labeled torque samples in the selected
     * time window. Replay deduplication is handled at telemetry ingestion.
     */
    private function baseSamples(Robot $robot, array $filters): Builder
    {
        $from = CarbonImmutable::parse($filters['from_utc'])->utc();
        $until = CarbonImmutable::parse($filters['until_utc'])->utc();
        $query = DB::table('robot_telemetry_samples')
            ->where('robot_id', (int) $robot->id)
            ->where('sampled_at', '>=', $from)
            ->where('sampled_at', '<', $until)
            ->where('torque_ok', true)
            ->where('torque_unit', 'sdk_raw')
            ->whereNotNull('torque_axes')
            ->where(function (Builder $q) use ($from, $until): void {
                $q->whereNull('torque_sampled_at')
                    ->orWhere(function (Builder $inner) use ($from, $until): void {
                        $inner->where('torque_sampled_at', '>=', $from)
                            ->where('torque_sampled_at', '<', $until);
                    });
            });

        if (DB::connection()->getDriverName() === 'sqlsrv') {
            // NVARCHAR JSON with ISJSON guard: invalid/missing payloads are safe.
            $payload = "CASE WHEN ISJSON(raw_payload) = 1 THEN raw_payload ELSE N'{}' END";
            return $query->whereRaw("LOWER(LTRIM(RTRIM(COALESCE(JSON_VALUE($payload, '$.simulated'), 'false')))) NOT IN ('true','1')");
        }

        // SQL JSON access on PostgreSQL / SQLite; no mock data in production analytics.
        return $query->where(function (Builder $q): void {
            $q->whereNull('raw_payload->simulated')
                ->orWhere('raw_payload->simulated', false);
        });
    }

    private function sqlServerAggregate(Builder $query, string $mode): array
    {
        $axesJson = "CASE WHEN ISJSON(torque_axes) = 1 THEN torque_axes ELSE N'{}' END";
        $src = (clone $query)->select('job_name', 'sampled_at');
        foreach (self::AXES as $axis) {
            $alias = 'torque_'.strtolower($axis);
            $src->selectRaw("TRY_CONVERT(float, NULLIF(LTRIM(RTRIM(JSON_VALUE($axesJson, '$.$axis'))), '')) AS [$alias]");
        }
        $q = DB::query()->fromSub($src, 'job_torque_readings')
            ->select('job_name')
            ->selectRaw('COUNT_BIG(*) AS sample_count, MIN(sampled_at) AS first_at, MAX(sampled_at) AS last_at')
            ->groupBy('job_name');
        foreach (self::AXES as $axis) {
            $alias = 'torque_'.strtolower($axis);
            $expr = $mode === 'absolute' ? "ABS([$alias])" : "[$alias]";
            $q->selectRaw("COUNT_BIG([$alias]) AS [{$alias}_count], AVG($expr) AS [{$alias}_avg], MAX($expr) AS [{$alias}_max]");
        }

        $rows = $q->orderByDesc('sample_count')->orderBy('job_name')->limit(self::MAX_JOBS + 1)->get();
        return $rows->map(fn (object $row): array => $this->rowFromSql($row))->all();
    }

    private function rowFromSql(object $row): array
    {
        $axes = [];
        foreach (self::AXES as $axis) {
            $prefix = 'torque_'.strtolower($axis);
            $axes[$axis] = [
                'count' => (int) $row->{$prefix.'_count'},
                'average' => $row->{$prefix.'_avg'} === null ? null : round((float) $row->{$prefix.'_avg'}, 4),
                'maximum' => $row->{$prefix.'_max'} === null ? null : round((float) $row->{$prefix.'_max'}, 4),
            ];
        }
        return [
            'job' => (string) $row->job_name,
            'sample_count' => (int) $row->sample_count,
            'first_at' => $this->wib($row->first_at),
            'last_at' => $this->wib($row->last_at),
            'axes' => $axes,
        ];
    }

    /** Bounded-memory portable fallback for local SQLite or PostgreSQL tests. */
    private function portableAggregate(Builder $query, string $mode): array
    {
        $groups = [];
        // lazyById requires the primary key and is stable for large histories.
        foreach ((clone $query)->select(['id', 'job_name', 'sampled_at', 'torque_axes'])
            ->lazyById(500, 'id') as $row) {
            $job = trim((string) $row->job_name);
            if ($job === '') {
                continue;
            }
            $rawAxes = is_string($row->torque_axes) ? json_decode($row->torque_axes, true) : $row->torque_axes;
            $rawAxes = is_array($rawAxes) ? $rawAxes : [];
            if (! isset($groups[$job])) {
                $groups[$job] = [
                    'job' => $job, 'sample_count' => 0,
                    'first_at' => (string) $row->sampled_at, 'last_at' => (string) $row->sampled_at,
                    'axes' => array_fill_keys(self::AXES, ['count' => 0, 'average' => null, 'maximum' => null]),
                ];
            }
            $g = &$groups[$job];
            $g['sample_count']++;
            $stamp = (string) $row->sampled_at;
            if ($stamp < $g['first_at']) $g['first_at'] = $stamp;
            if ($stamp > $g['last_at']) $g['last_at'] = $stamp;
            foreach (self::AXES as $axis) {
                $value = $rawAxes[$axis] ?? null;
                if (! is_numeric($value) || ! is_finite((float) $value)) continue;
                $n = $mode === 'absolute' ? abs((float) $value) : (float) $value;
                $stat = &$g['axes'][$axis];
                $count = ++$stat['count'];
                $stat['average'] = $count === 1 ? $n : $stat['average'] + ($n - $stat['average']) / $count;
                $stat['maximum'] = $stat['maximum'] === null ? $n : max($stat['maximum'], $n);
                unset($stat);
            }
            unset($g);
        }
        $rows = array_values($groups);
        usort($rows, static fn (array $a, array $b): int => ($b['sample_count'] <=> $a['sample_count']) ?: strcmp($a['job'], $b['job']));
        $rows = array_slice($rows, 0, self::MAX_JOBS + 1);
        foreach ($rows as &$row) {
            $row['first_at'] = $this->wib($row['first_at']);
            $row['last_at'] = $this->wib($row['last_at']);
            foreach ($row['axes'] as &$axis) {
                foreach (['average', 'maximum'] as $key) {
                    if ($axis[$key] !== null) $axis[$key] = round($axis[$key], 4);
                }
            }
            unset($axis);
        }
        unset($row);
        return $rows;
    }

    private function wib(mixed $timestamp): ?string
    {
        if ($timestamp === null) return null;
        try {
            // DB datetimeoffset carries a UTC offset; Laravel app default is UTC.
            return CarbonImmutable::parse((string) $timestamp, 'UTC')
                ->timezone('Asia/Jakarta')->format('d M Y H:i:s').' WIB';
        } catch (\Throwable) {
            return null;
        }
    }
}
