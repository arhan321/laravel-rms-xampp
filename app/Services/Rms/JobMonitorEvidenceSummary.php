<?php

declare(strict_types=1);

namespace App\Services\Rms;

use DateTimeZone;
use App\Models\Robot;
use DateTimeImmutable;

/**
 * Read-only adapter for the OPTIONAL C# raw_payload.job_monitor envelope.
 * No robot commands, no writes, and no OEM Job Diagnosis claims.
 */
final class JobMonitorEvidenceSummary
{
    public function forRobot(Robot $robot, array $torqueReport = []): array
    {
        $ttl = max(5, min(300, (int) config('rms.telemetry_stale_seconds', 30)));
        $now = now()->getTimestamp();
        $recent = $robot->telemetrySamples()
            ->orderByDesc('sampled_at')->orderByDesc('id')
            ->limit(24)
            ->get(['id', 'sampled_at', 'received_at', 'job_name', 'raw_payload']);
        $latest = $recent->first();
        $snapshot = self::interpret([], null, null, null, $now, $ttl);
        foreach ($recent as $sample) {
            $candidate = self::interpret(
                is_array($sample->raw_payload) ? $sample->raw_payload : [],
                $sample->job_name,
                $sample->sampled_at?->toIso8601String(),
                $sample->received_at?->toIso8601String(),
                $now,
                $ttl
            );
            if ($sample->id === $latest->id) {
                $snapshot = $candidate;
                if (! in_array($candidate['status'], ['not_polled', 'no_data', 'legacy_tag_only'], true)) {
                    break;
                }
            } elseif (in_array($candidate['status'], ['fresh', 'read_failed', 'invalid_job'], true)) {
                $snapshot = $candidate;
                break;
            }
        }
        $snapshot['telemetry_sampled_wib'] = self::wib($latest?->sampled_at?->toIso8601String());

        // A last-known job is historical evidence, NEVER proof of the currently executing job.
        $lastJob = null;
        if ($latest !== null) {
            $candidates = $robot->telemetrySamples()
                ->whereNotNull('job_name')
                ->orderByDesc('sampled_at')->orderByDesc('id')
                ->limit(20)
                ->get(['job_name', 'sampled_at', 'raw_payload']);
            foreach ($candidates as $candidate) {
                $raw = is_array($candidate->raw_payload) ? $candidate->raw_payload : [];
                if (self::simulated($raw)) {
                    continue;
                }
                $name = self::safeName($candidate->job_name);
                if ($name === null) {
                    continue;
                }
                $lastJob = [
                    'name' => $name,
                    'sampled_wib' => self::wib($candidate->sampled_at?->toIso8601String()),
                ];
                break;
            }
        }
        $snapshot['last_known_job'] = $lastJob;

        // YMConnect Operating Time readings are cumulative ROBOT counters, not JOB MONITOR values.
        $playback = $this->robotOperatingCounter($robot, 'PlayBackTimeRobot');
        $moving = $this->robotOperatingCounter($robot, 'MovingTimeRobot');
        $hasTorqueGroups = count($torqueReport['groups'] ?? []) > 0;
        $jobLabel = $snapshot['status'] === 'fresh'
            ? ($snapshot['job_name'] ?? '—')
            : 'Tidak terkonfirmasi live';

        $snapshot['parameters'] = [
            ['label' => 'Job Name', 'value' => $jobLabel, 'status' => $snapshot['status'] === 'fresh' ? 'observed' : 'unavailable', 'note' => 'Nama job dari sampel SDK terbaru; bukan batas siklus job'],
            ['label' => 'Number of Job Executions', 'value' => '—', 'status' => 'unavailable', 'note' => 'Belum ada counter siklus job OEM yang tervalidasi'],
            ['label' => 'Playback Time', 'value' => $playback ?? '—', 'status' => $playback === null ? 'unavailable' : 'robot_scope', 'note' => 'Counter kumulatif robot, BUKAN playback time per job'],
            ['label' => 'Moving Time', 'value' => $moving ?? '—', 'status' => $moving === null ? 'unavailable' : 'robot_scope', 'note' => 'Counter kumulatif robot, BUKAN moving time per job'],
            ['label' => 'I/O Stop Time', 'value' => '—', 'status' => 'unavailable', 'note' => 'Belum ada nilai durasi I/O stop JOB DIAGNOSIS'],
            ['label' => 'Energy Saving Time', 'value' => '—', 'status' => 'unavailable', 'note' => 'Belum ada nilai durasi energy saving JOB DIAGNOSIS'],
            ['label' => 'Speed Reducer Lifetime', 'value' => '—', 'status' => 'unavailable', 'note' => 'Belum tersedia nilai lifetime OEM; model prediksi RMS tidak sama'],
            ['label' => 'Load Ratio per Axis', 'value' => '—', 'status' => 'unavailable', 'note' => 'Torque SDK raw tidak disamakan dengan load ratio'],
            ['label' => 'Maximum Speed per Axis', 'value' => '—', 'status' => 'unavailable', 'note' => 'Speed override bukan maximum speed tiap axis'],
            ['label' => 'Average Speed per Axis', 'value' => '—', 'status' => 'unavailable', 'note' => 'Belum ada speed per axis terverifikasi'],
            ['label' => 'Maximum Torque per Axis', 'value' => $hasTorqueGroups ? 'Lihat tabel AVG/MAX di bawah' : '—', 'status' => $hasTorqueGroups ? 'rms_calc' : 'unavailable', 'note' => 'MAX dari sampel SDK bertanda job, BUKAN statistik OEM'],
            ['label' => 'Average Torque per Axis', 'value' => $hasTorqueGroups ? 'Lihat tabel AVG/MAX di bawah' : '—', 'status' => $hasTorqueGroups ? 'rms_calc' : 'unavailable', 'note' => 'AVG dari sampel SDK bertanda job, BUKAN statistik OEM'],
        ];

        return $snapshot;
    }

    private function robotOperatingCounter(Robot $robot, string $type): ?string
    {
        $sample = $robot->operatingTimeSamples()->where('time_type', $type)
            ->orderByDesc('sampled_at')->orderByDesc('id')->first();
        if (! $sample?->read_ok || $sample->value_seconds === null
            || ! is_numeric($sample->value_seconds) || (float) $sample->value_seconds < 0) {
            return null;
        }

        return number_format((float) $sample->value_seconds / 3600.0, 2, ',', '.').' jam (robot)';
    }

    /**
     * Pure decoder that can be tested without a database or YMConnect device.
     * No inferred executions, OEM diagnostics, or synthetic torque conversions.
     *
     * @return array<string,mixed>
     */
    public static function interpret(
        array $payload,
        ?string $storedJob,
        ?string $sampledAt,
        ?string $receivedAt,
        int $nowUnix,
        int $ttlSeconds = 30
    ): array {
        $envelope = $payload['job_monitor'] ?? null;
        $envelope = is_array($envelope) ? $envelope : null;
        $job = is_array($payload['job'] ?? null) ? $payload['job'] : [];
        $jobRead = is_array($payload['job_read'] ?? null) ? $payload['job_read'] : [];
        $hasNewEnvelope = $envelope !== null;
        $readOk = $hasNewEnvelope
            ? (($envelope['read_ok'] ?? null) === true && ($envelope['read_status'] ?? null) === 'read_ok')
            : (($jobRead['read_ok'] ?? null) === true);
        $legacyNotPolled = ($jobRead['status_code'] ?? null) === -1
            && ($jobRead['message'] ?? null) === 'unavailable';
        $readFailed = $hasNewEnvelope
            ? (($envelope['read_status'] ?? null) === 'read_failed')
            : (($jobRead['read_ok'] ?? null) === false && ! $legacyNotPolled);
        $attempted = $readOk || $readFailed || ($envelope['read_status'] ?? null) === 'invalid_job';
        $name = $readOk
            ? self::safeName($envelope['job_name'] ?? $job['name'] ?? $storedJob)
            : null;
        $sampleUnix = self::unix($sampledAt);
        $receivedUnix = self::unix($receivedAt);
        $jobAt = $envelope['observed_at'] ?? null;
        $jobAt = is_string($jobAt) ? $jobAt : $sampledAt;
        $jobUnix = self::unix($jobAt);
        $jobAge = $jobUnix === null ? null : $nowUnix - $jobUnix;
        $sampleAge = $sampleUnix === null ? null : $nowUnix - $sampleUnix;
        $receiveAge = $receivedUnix === null ? null : $nowUnix - $receivedUnix;
        $fresh = $sampleAge !== null && $receiveAge !== null && $jobAge !== null
            && $sampleAge >= -5 && $receiveAge >= -5
            && $jobAge >= -5 && $jobAge <= $ttlSeconds
            && $sampleAge <= 60 && $receiveAge <= $ttlSeconds;

        $simulated = self::simulated($payload);
        $status = match (true) {
            $simulated => 'simulated',
            $sampledAt !== null && ! $fresh => 'stale',
            $readFailed => 'read_failed',
            $readOk && $name !== null && $fresh => 'fresh',
            $readOk || ($envelope['read_status'] ?? null) === 'invalid_job' => 'invalid_job',
            $envelope !== null || $jobRead !== [] => 'not_polled',
            self::safeName($storedJob) !== null => 'legacy_tag_only',
            default => 'no_data',
        };
        $labels = [
            'fresh' => 'FRESH · JOB READ OK',
            'stale' => 'DATA LAMA · STALE',
            'read_failed' => 'JOB READ FAILED',
            'not_polled' => 'JOB BELUM DIBACA',
            'invalid_job' => 'JOB NAME INVALID',
            'legacy_tag_only' => 'LEGACY TAG ONLY',
            'simulated' => 'DEMO / SIMULATED',
            'no_data' => 'NO JOB EVIDENCE',
        ];

        $details = $readOk && $name !== null && ! $simulated;
        $line = $details ? self::nonNegativeInteger($envelope['line'] ?? $job['line'] ?? null) : null;
        $step = $details ? self::nonNegativeInteger($envelope['step'] ?? $job['step'] ?? null) : null;
        $override = $details ? self::finiteNumber($envelope['speed_override_percent'] ?? $job['speed_override_percent'] ?? null) : null;
        $state = is_array($payload['controller_state'] ?? null) ? $payload['controller_state'] : [];
        $running = $fresh && ! $simulated
            ? ($envelope['controller_running'] ?? $state['running'] ?? null) : null;
        $running = is_bool($running) ? $running : null;

        return [
            'status' => $status,
            'label' => $labels[$status],
            'is_simulated' => $simulated,
            'job_name' => $details ? $name : null,
            'line' => $line,
            'step' => $step,
            'speed_override_percent' => $override,
            'controller_running' => $running,
            'read_ok' => $readOk && ! $simulated,
            'envelope_present' => $hasNewEnvelope,
            'source' => $hasNewEnvelope ? 'C# raw_payload.job_monitor' : 'Legacy raw_payload.job / job_read',
            'sampled_wib' => $attempted ? self::wib($jobAt) : null,
            'received_wib' => self::wib($receivedAt),
            'sample_age_seconds' => ! $attempted || $jobAge === null ? null : max(0, $jobAge),
            'received_age_seconds' => $receiveAge === null ? null : max(0, $receiveAge),
            'sdk_status_code' => $attempted ? ($envelope['status_code'] ?? $jobRead['status_code'] ?? null) : null,
            'sdk_status_message' => $readFailed ? ($envelope['status_message'] ?? $jobRead['message'] ?? null) : null,
        ];
    }

    private static function safeName(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $name = trim($value);
        if ($name === '' || strlen($name) > 120 || preg_match('/[\x00-\x1F\x7F]/', $name)) {
            return null;
        }

        return $name;
    }

    private static function simulated(array $payload): bool
    {
        return in_array($payload['simulated'] ?? false, [true, 1, '1', 'true'], true);
    }

    private static function nonNegativeInteger(mixed $value): ?int
    {
        if (! is_int($value) && ! (is_string($value) && ctype_digit($value))) {
            return null;
        }
        $number = (int) $value;

        return $number >= 0 ? $number : null;
    }

    private static function finiteNumber(mixed $value): ?float
    {
        if (! is_numeric($value) || ! is_finite((float) $value)) {
            return null;
        }

        return (float) $value;
    }

    private static function unix(?string $at): ?int
    {
        if ($at === null || trim($at) === '') {
            return null;
        }
        try {
            return (new DateTimeImmutable($at))->getTimestamp();
        } catch (\Throwable) {
            return null;
        }
    }

    private static function wib(?string $at): ?string
    {
        if (self::unix($at) === null) {
            return null;
        }
        try {
            return (new DateTimeImmutable($at))->setTimezone(new DateTimeZone('Asia/Jakarta'))
                ->format('d M Y H:i:s').' WIB';
        } catch (\Throwable) {
            return null;
        }
    }
}
