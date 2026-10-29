<?php

declare(strict_types=1);

namespace App\Support;

use DateTimeInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

/** Database expressions for the two supported RMS backends. */
final class RmsDatabase
{
    public static function source(): string
    {
        return match (DB::connection()->getDriverName()) {
            'sqlsrv' => 'sqlserver',
            'pgsql' => 'postgresql',
            default => DB::connection()->getDriverName(),
        };
    }

    public static function jakartaDay(): string
    {
        return match (DB::connection()->getDriverName()) {
            'sqlsrv' => "CAST(SWITCHOFFSET(sampled_at, '+07:00') AS date)",
            'pgsql' => "DATE(sampled_at AT TIME ZONE 'Asia/Jakarta')",
            default => throw new LogicException('RMS analytics requires SQL Server or PostgreSQL.'),
        };
    }

    /** $expression must be a hard-coded server-side timestamp expression. */
    public static function utcTimestamp(string $expression): string
    {
        return match (DB::connection()->getDriverName()) {
            'sqlsrv' => "CAST(SWITCHOFFSET($expression, '+00:00') AS datetime2(3))",
            'pgsql' => "($expression AT TIME ZONE 'UTC')",
            default => throw new LogicException('RMS logs require SQL Server or PostgreSQL.'),
        };
    }

    public static function cockpitQuery(int $robotId, int $seconds, DateTimeInterface $from, DateTimeInterface $until): Builder
    {
        if ($seconds < 1) {
            throw new InvalidArgumentException('The bucket width must be positive.');
        }

        $bucket = match (DB::connection()->getDriverName()) {
            'sqlsrv' => "DATE_BUCKET(SECOND, $seconds, CAST(SWITCHOFFSET(sampled_at, '+00:00') AS datetime2(3)), CAST('2000-01-01T00:00:00' AS datetime2(3)))",
            'pgsql' => "date_bin(INTERVAL '$seconds seconds', sampled_at, TIMESTAMPTZ '2000-01-01 00:00:00+00')",
            default => throw new LogicException('RMS cockpit requires SQL Server 2022+ or PostgreSQL 14+.'),
        };

        $ranked = DB::table('robot_telemetry_samples')
            ->select(['id', 'sampled_at', 'received_at', 'torque_sampled_at', 'torque_axes', 'torque_ok', 'torque_unit', 'raw_payload', 'servo_current_estimate'])
            ->selectRaw("ROW_NUMBER() OVER (PARTITION BY $bucket ORDER BY sampled_at DESC, id DESC) AS rms_bucket_row")
            ->where('robot_id', $robotId)
            ->where('sampled_at', '>=', $from)
            ->where('sampled_at', '<=', $until);

        // Filter inside the ranking query; then select one actual read per bucket.
        // Invalid reads remain visible as gaps, with deterministic ties by id.
        return DB::query()->fromSub($ranked, 'rms_cockpit_samples')
            ->select(['sampled_at', 'received_at', 'torque_sampled_at', 'torque_axes', 'torque_ok', 'torque_unit', 'raw_payload', 'servo_current_estimate'])
            ->where('rms_bucket_row', 1)
            ->orderByDesc('sampled_at')->orderByDesc('id')->limit(300);
    }

    public static function cockpitSamples(int $robotId, int $seconds, DateTimeInterface $from, DateTimeInterface $until): Collection
    {
        return self::cockpitQuery($robotId, $seconds, $from, $until)->get()->reverse()->values();
    }
}
