<?php

declare(strict_types=1);

namespace App\Services\Rms;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Throwable;

/** Pure torque-to-current model; output is never represented as measured current. */
final class ServoCurrentEstimator
{
    public const AXES = ['S', 'L', 'U', 'R', 'B', 'T'];

    public const REASONS = [
        'estimated' => 'Estimasi dari torque; akurasi belum diverifikasi dengan alat ukur.',
        'not_configured' => 'Parameter motor axis ini belum diisi.',
        'disabled' => 'Estimasi axis ini dinonaktifkan.',
        'unconfirmed' => 'Acuan torque, satuan Arms, dan parameter motor belum dikonfirmasi.',
        'identity_changed' => 'Identitas robot berubah; simpan ulang profil motor yang sesuai.',
        'no_sample' => 'Belum ada sampel torque.',
        'simulated' => 'Data simulasi tidak digunakan untuk estimasi arus robot.',
        'group_mismatch' => 'Control group pada sampel tidak cocok dengan robot.',
        'invalid_read' => 'Pembacaan torque gagal atau waktu sampel tidak valid.',
        'unit_mismatch' => 'Satuan torque sampel berbeda dengan profil motor.',
        'pending_sample' => 'Menunggu sampel torque setelah profil motor disimpan.',
        'stale' => 'Data torque kedaluwarsa; menunggu pembacaan baru.',
        'invalid_value' => 'Nilai torque atau parameter perhitungan tidak valid.',
        'not_stored' => 'Sampel lama belum memiliki rekaman arus tersimpan. Gunakan backfill untuk histori yang memenuhi syarat.',
        'invalid_saved_data' => 'Rekaman estimasi arus tidak valid; periksa integritas data tersimpan.',
    ];

    /** @return array<string, mixed> */
    public function estimate(string $axis, array $identity, ?array $profile, array $sample, float $now, bool $live = true, int $staleSeconds = 30): array
    {
        $result = [
            'axis' => $axis, 'amps' => null, 'torque_percent' => null,
            'unit' => 'Arms', 'kind' => 'estimated', 'accuracy_verified' => false,
            'status' => 'not_configured', 'reason' => self::REASONS['not_configured'],
            'profile_id' => $profile['id'] ?? null, 'expires_in_seconds' => 0,
        ];
        $fail = static fn (string $status): array => array_replace($result, [
            'status' => $status, 'reason' => self::REASONS[$status],
        ]);
        if ($profile === null) {
            return $fail('not_configured');
        }
        if (($profile['enabled'] ?? false) !== true) {
            return $fail('disabled');
        }
        if (($profile['basis_confirmed'] ?? false) !== true || ($profile['current_unit'] ?? '') !== 'Arms'
            || empty($profile['source_reference']) || empty($profile['checked_by'])) {
            return $fail('unconfirmed');
        }
        foreach ($identity as $key => $expected) {
            if (! is_array($profile['identity'] ?? null) || ! array_key_exists($key, $profile['identity']) || $profile['identity'][$key] !== $expected) {
                return $fail('identity_changed');
            }
        }
        if ($sample === []) {
            return $fail('no_sample');
        }
        $payload = $sample['raw_payload'] ?? [];
        $payload = is_string($payload) ? json_decode($payload, true) : $payload;
        $payload = is_array($payload) ? $payload : [];
        if (in_array($payload['simulated'] ?? false, [true, 1, '1', 'true'], true)) {
            return $fail('simulated');
        }
        if (strtoupper((string) ($payload['group'] ?? '')) !== ($identity['group'] ?? null)) {
            return $fail('group_mismatch');
        }
        $sampleAt = self::timestamp($sample['sampled_at'] ?? null);
        $torqueAt = self::timestamp($sample['torque_sampled_at'] ?? $sample['sampled_at'] ?? null);
        $receivedAt = self::timestamp($sample['received_at'] ?? null);
        if (! in_array($sample['torque_ok'] ?? false, [true, 1, '1', 't', 'true'], true)
            || $sampleAt === null || $torqueAt === null) {
            return $fail('invalid_read');
        }
        if (($sample['torque_unit'] ?? null) !== ($profile['input_unit'] ?? null)) {
            return $fail('unit_mismatch');
        }
        $effectiveAt = self::timestamp($profile['effective_from'] ?? null);
        if ($effectiveAt === null || $torqueAt < $effectiveAt || $sampleAt < $effectiveAt) {
            return $fail('pending_sample');
        }
        $remaining = 0.0;
        if ($live) {
            if ($receivedAt === null || max($sampleAt, $torqueAt, $receivedAt) > $now + 5) {
                return $fail('invalid_read');
            }
            $remaining = min($sampleAt, $torqueAt, $receivedAt) + $staleSeconds - $now;
            if ($remaining <= 0) {
                return $fail('stale');
            }
        }
        $axes = $sample['torque_axes'] ?? [];
        $axes = is_string($axes) ? json_decode($axes, true) : $axes;
        $raw = is_array($axes) ? ($axes[$axis] ?? null) : null;
        $nominal = $profile['nominal_torque_nm'] ?? null;
        $constant = $profile['torque_constant_nm_per_arms'] ?? null;
        $scale = $profile['torque_percent_per_unit'] ?? null;
        foreach ([$raw, $nominal, $constant, $scale] as $value) {
            if (! is_numeric($value) || ! is_finite((float) $value)) {
                return $fail('invalid_value');
            }
        }
        if ((float) $nominal <= 0 || (float) $constant <= 0 || (float) $scale <= 0) {
            return $fail('invalid_value');
        }
        $percent = (float) $raw * (float) $scale;
        $amps = abs($percent) / 100 * (float) $nominal / (float) $constant;
        if (! is_finite($percent) || ! is_finite($amps)) {
            return $fail('invalid_value');
        }

        return array_replace($result, [
            'amps' => round($amps, 6), 'torque_percent' => $percent,
            'status' => 'estimated', 'reason' => self::REASONS['estimated'],
            'expires_in_seconds' => max(0, $remaining),
        ]);
    }

    /** A disabled revision blocks previous active revisions at and after its start. */
    public function profileAt(array $versions, float $sampleAt): ?array
    {
        foreach (array_reverse($versions) as $profile) {
            if (! is_array($profile)) {
                continue;
            }
            $from = self::timestamp($profile['effective_from'] ?? null);
            if ($from !== null && $from <= $sampleAt) {
                return $profile;
            }
        }

        return null;
    }

    public static function timestamp(mixed $value): ?float
    {
        try {
            if ($value instanceof DateTimeInterface) {
                return (float) $value->format('U.u');
            }
            if (! is_string($value) || trim($value) === '') {
                return null;
            }

            return (float) (new DateTimeImmutable($value, new DateTimeZone('UTC')))->format('U.u');
        } catch (Throwable) {
            return null;
        }
    }
}
