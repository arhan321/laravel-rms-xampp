<?php

declare(strict_types=1);

namespace App\Services\Rms;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Pareto based on de-duplicated Controller Alarm History entries.
 *
 * robot_alarm_history_entries has a unique (robot_id, fingerprint) key.
 * A repeated YMConnect polling snapshot does NOT create another row.
 * The count is a count of unique stored history records, not a certified
 * downtime/failure incident count or the number of polling snapshots.
 *
 * The period uses first_observed_at (first RMS observation). The controller's
 * original time is stored as untrusted text and cannot be used for date ranges.
 */
final class AlarmParetoSummary
{
    private const PERIOD_DAYS = [
        'all' => null,
        '7d' => 7,
        '30d' => 30,
        '90d' => 90,
    ];

    /**
     * Build Top-10 Pareto using the exact history filters from Alarm Center.
     * Status / severity filters belong to alarm EVENTS and intentionally do
     * not apply to the controller-history Pareto query.
     *
     * @return array<string, mixed>
     */
    public function fromHistoryQuery(Builder $historyQuery, string $period = 'all'): array
    {
        if (! array_key_exists($period, self::PERIOD_DAYS)) {
            $period = 'all';
        }

        // Clone: never alter the original history query used by pagination.
        $query = clone $historyQuery;
        $query->setEagerLoads([]);
        $query->reorder();
        $query->whereNotNull('alarm_code')->where('alarm_code', '<>', '');

        $sinceWib = null;
        $days = self::PERIOD_DAYS[$period];
        if (is_int($days)) {
            $sinceWib = CarbonImmutable::now('Asia/Jakarta')
                ->startOfDay()
                ->subDays($days - 1);
            $query->where('first_observed_at', '>=', $sinceWib->utc());
        }

        $total = (int) (clone $query)->count();
        $driver = $query->getModel()->getConnection()->getDriverName();
        $countExpression = $driver === 'sqlsrv' ? 'COUNT_BIG(*)' : 'COUNT(*)';

        // Aggregation is performed by SQL Server, not by loading raw rows into PHP.
        $groups = (clone $query)
            ->select(['alarm_code', 'subcode'])
            ->selectRaw('MAX(alarm_name) AS alarm_name')
            ->selectRaw($countExpression.' AS hit_count')
            ->groupBy('alarm_code', 'subcode')
            ->orderByDesc('hit_count')
            ->orderBy('alarm_code')
            ->orderBy('subcode')
            ->limit(10)
            ->get();

        $rows = [];
        $topTotal = 0;
        $cumulative = 0;
        foreach ($groups as $rank => $group) {
            $count = (int) $group->hit_count;
            $topTotal += $count;
            $cumulative += $count;
            $code = trim((string) $group->alarm_code);
            $subcode = $group->subcode === null ? null : trim((string) $group->subcode);
            $rows[] = [
                'rank' => $rank + 1,
                'code' => $code,
                'subcode' => $subcode,
                'label' => $subcode !== null && $subcode !== '' ? $code.' / '.$subcode : $code,
                'name' => (string) $group->alarm_name,
                'count' => $count,
                'percent' => $total > 0 ? round($count * 100 / $total, 1) : 0.0,
                'cumulative' => $total > 0 ? round($cumulative * 100 / $total, 1) : 0.0,
                'other' => false,
            ];
        }

        $remaining = max(0, $total - $topTotal);
        if ($remaining > 0) {
            $rows[] = [
                'rank' => null,
                'code' => 'Lainnya',
                'subcode' => null,
                'label' => 'Lainnya',
                'name' => 'Gabungan kode alarm di luar Top 10',
                'count' => $remaining,
                'percent' => $total > 0 ? round($remaining * 100 / $total, 1) : 0.0,
                'cumulative' => 100.0,
                'other' => true,
            ];
        }

        $periodLabel = match ($period) {
            '7d' => '7 hari terakhir',
            '30d' => '30 hari terakhir',
            '90d' => '90 hari terakhir',
            default => 'Seluruh histori',
        };

        return [
            'period' => $period,
            'period_label' => $periodLabel,
            'since_wib' => $sinceWib?->format('d M Y'),
            'total' => $total,
            'top_total' => $topTotal,
            'top_share_percent' => $total > 0 ? round($topTotal * 100 / $total, 1) : 0.0,
            'other_total' => $remaining,
            'top_alarm' => $rows[0] ?? null,
            'rows' => $rows,
        ];
    }
}
