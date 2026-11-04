<?php

declare(strict_types=1);

namespace App\Services\Rms;

use App\Models\Robot;
use App\Models\RobotTelemetrySample;
use Carbon\CarbonImmutable;

/** Observes controller pulse data; position alone cannot establish physical encoder health. */
final class EncoderMonitor
{
    public const AXES = ['S', 'L', 'U', 'R', 'B', 'T'];

    public const LABELS = [
        'ok' => 'DATA TERBACA', 'stale' => 'DATA KEDALUWARSA', 'no_data' => 'BELUM ADA DATA',
        'read_failed' => 'PEMBACAAN GAGAL', 'invalid_time' => 'WAKTU TIDAK VALID',
        'invalid_axes' => 'PULSE TIDAK VALID', 'wrong_group' => 'GROUP TIDAK SESUAI',
        'unknown_unit' => 'SATUAN BELUM JELAS', 'simulated' => 'DATA SIMULASI', 'unsupported' => 'AXIS BELUM DIDUKUNG',
    ];

    public function __construct(private readonly HomePositionMonitor $home) {}

    public function recent(Robot $robot, int $limit = 120): array
    {
        $limit = in_array($limit, [120, 300, 600], true) ? $limit : 120;
        $now = CarbonImmutable::now();
        $ttl = max(5, min(300, (int) config('rms.telemetry_stale_seconds', 30)));
        $latest = $robot->telemetrySamples()->orderByDesc('received_at')->orderByDesc('id')->first();
        $samples = $robot->telemetrySamples()->where('sampled_at', '>=', $now->subDay())
            ->where('sampled_at', '<=', $now->addSeconds(5))
            ->orderByDesc('sampled_at')->orderByDesc('id')->limit($limit)->get()->reverse()->values();
        $rows = [];
        $previous = null;
        $statistics = array_fill_keys(self::AXES, ['min' => null, 'max' => null, 'count' => 0]);
        foreach ($samples as $sample) {
            $row = $this->reading($robot, $sample, $now);
            $row['delta'] = array_fill_keys(self::AXES, null);
            $row['interval_seconds'] = null;
            $row['continuous'] = $this->continuous($previous, $row, $ttl);
            if ($row['continuous']) {
                $row['interval_seconds'] = ($row['at_ms'] - $previous['at_ms']) / 1000;
                foreach (self::AXES as $axis) {
                    $row['delta'][$axis] = $row['axes'][$axis] - $previous['axes'][$axis];
                }
            }
            if ($row['status'] === 'ok') {
                foreach (self::AXES as $axis) {
                    $value = $row['axes'][$axis];
                    $stat = &$statistics[$axis];
                    $stat['min'] = $stat['min'] === null ? $value : min($stat['min'], $value);
                    $stat['max'] = $stat['max'] === null ? $value : max($stat['max'], $value);
                    $stat['count']++;
                    unset($stat);
                }
            }
            $rows[] = $row;
            $previous = $row;
        }

        $current = $this->reading($robot, $latest, $now);
        $expires = $latest && $current['status'] === 'ok'
            ? min($latest->sampled_at->getTimestamp(), $latest->position_sampled_at->getTimestamp(), $latest->received_at->getTimestamp()) + $ttl - $now->getTimestamp() : 0;
        if ($current['status'] === 'ok' && $expires <= 0) {
            $current['status'] = 'stale';
            $current['label'] = self::LABELS['stale'];
        }
        $live = $current['status'] === 'ok';
        $matching = collect($rows)->firstWhere('id', $latest?->id);
        $axes = [];
        foreach (self::AXES as $axis) {
            $axes[$axis] = [
                'pulse' => $live ? $current['axes'][$axis] : null, 'last_pulse' => $current['axes'][$axis],
                'delta' => $live ? ($matching['delta'][$axis] ?? null) : null, ...$statistics[$axis],
            ];
        }
        $alarms = $robot->alarmEvents()->whereRaw('LOWER(alarm_name) LIKE ?', ['%encoder%'])
            ->orderByDesc('started_at')->orderByDesc('id')->limit(20)->get()
            ->map(static fn ($alarm): array => [
                'id' => $alarm->id, 'code' => $alarm->alarm_code, 'subcode' => $alarm->subcode,
                'name' => $alarm->alarm_name, 'status' => $alarm->status,
                'time_wib' => $alarm->started_at?->timezone('Asia/Jakarta')->format('d M Y H:i:s'),
                'url' => route('rms.alarms.countermeasures.show', ['kind' => 'event', 'record' => $alarm->id]),
            ])->all();
        $valid = count(array_filter($rows, static fn (array $row): bool => $row['status'] === 'ok'));

        return [
            'robot_id' => (int) $robot->id, 'group' => $robot->control_group, 'unit' => 'pulse',
            'source' => 'YMConnect ReadPositionData / CoordinateType::Pulse', 'server_time' => $now->toIso8601String(),
            'status' => $current['status'], 'label' => $current['label'], 'sample_id' => $latest?->id,
            'sampled_wib' => $current['time_wib'],
            'received_wib' => $latest?->received_at?->timezone('Asia/Jakarta')->format('d M Y H:i:s'),
            'age_seconds' => $latest?->position_sampled_at ? max(0, $now->getTimestamp() - $latest->position_sampled_at->getTimestamp()) : null,
            'expires_in_seconds' => $live ? max(0, $expires) : 0, 'stale_seconds' => $ttl,
            'read_code' => $current['read_code'], 'read_message' => $current['read_message'],
            'axes' => $axes, 'rows' => $rows, 'statistics' => ['total' => count($rows), 'valid' => $valid, 'invalid' => count($rows) - $valid],
            'limit' => $limit, 'window_note' => 'Maksimal '.$limit.' sampel terbaru dalam 24 jam terakhir; statistik hanya untuk sampel yang ditampilkan.',
            'home' => $this->home->forSample($robot, $latest, $live), 'encoder_alarms' => $alarms, 'physical_health' => 'unverified',
        ];
    }

    private function reading(Robot $robot, ?RobotTelemetrySample $sample, CarbonImmutable $now): array
    {
        $row = [
            'id' => $sample?->id, 'at_ms' => null, 'time_wib' => null, 'time_utc' => null,
            'session' => $sample?->collector_session_id, 'sequence' => $sample?->sequence,
            'read_code' => $sample?->position_status_code, 'read_message' => $sample?->position_status_message,
            'axes' => array_fill_keys(self::AXES, null), 'status' => 'no_data',
        ];
        if ($sample === null) {
            return [...$row, 'label' => self::LABELS['no_data']];
        }
        $at = $sample->position_sampled_at ?? $sample->sampled_at;
        $row['at_ms'] = $at ? (int) round((float) $at->format('U.u') * 1000) : null;
        $row['time_wib'] = $at?->timezone('Asia/Jakarta')->format('d M Y H:i:s.v');
        $row['time_utc'] = $at?->toIso8601String();
        $status = match (true) {
            (int) $robot->axis_count !== 6 => 'unsupported',
            in_array(data_get($sample->raw_payload, 'simulated'), [true, 1, '1', 'true'], true) => 'simulated',
            data_get($sample->raw_payload, 'group') !== $robot->control_group => 'wrong_group',
            ! in_array(data_get($sample->raw_payload, 'position_unit'), ['pulse', 'pulses'], true) => 'unknown_unit',
            ! $sample->position_ok || ($sample->position_status_code !== null && $sample->position_status_code !== 0) => 'read_failed',
            ! $sample->sampled_at || ! $sample->position_sampled_at || ! $sample->received_at => 'invalid_time',
            $sample->sampled_at > $now->addSeconds(5) || $sample->position_sampled_at > $now->addSeconds(5) || $sample->received_at > $now->addSeconds(5) => 'invalid_time',
            default => 'ok',
        };
        if ($status === 'ok') {
            $values = [];
            foreach (self::AXES as $axis) {
                $value = $sample->position_pulses[$axis] ?? null;
                if (! is_numeric($value) || ! is_finite((float) $value) || floor((float) $value) !== (float) $value
                    || (float) $value < -2147483648 || (float) $value > 2147483647) {
                    $status = 'invalid_axes';
                    break;
                }
                $values[$axis] = (int) $value;
            }
            if ($status === 'ok') {
                $row['axes'] = $values;
            }
        }

        return [...$row, 'status' => $status, 'label' => self::LABELS[$status]];
    }

    private function continuous(?array $previous, array $row, int $ttl): bool
    {
        return $previous !== null && $previous['status'] === 'ok' && $row['status'] === 'ok'
            && filled($row['session']) && $row['session'] === $previous['session']
            && $row['sequence'] !== null && $previous['sequence'] !== null && $row['sequence'] === $previous['sequence'] + 1
            && $row['at_ms'] > $previous['at_ms'] && $ttl * 1000 >= $row['at_ms'] - $previous['at_ms'];
    }
}
