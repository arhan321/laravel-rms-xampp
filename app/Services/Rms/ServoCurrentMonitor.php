<?php

declare(strict_types=1);

namespace App\Services\Rms;

use App\Models\Robot;
use App\Models\RobotTelemetrySample;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/** Reads frozen database estimates; GET requests never calculate or write history. */
final class ServoCurrentMonitor
{
    public function __construct(private ServoCurrentEstimator $estimator, private ServoCurrentStorage $storage) {}

    public function identity(Robot $robot): array
    {
        return $this->storage->identity($robot);
    }

    public function profiles(Robot $robot): array
    {
        return $this->storage->profiles($robot);
    }

    public function recent(Robot $robot, ?RobotTelemetrySample $latest = null): array
    {
        $latest ??= $robot->telemetrySamples()->orderByDesc('received_at')->orderByDesc('id')->first();
        $rows = $robot->telemetrySamples()
            ->where('sampled_at', '>=', now()->subDay())
            ->where('sampled_at', '<=', now()->addSeconds(5))
            ->orderByDesc('sampled_at')->orderByDesc('id')->limit(300)->get()->reverse();

        return $this->build($robot, $latest, $rows);
    }

    /** Existing cockpit rows are already bounded and chronologically ordered. */
    public function build(Robot $robot, ?RobotTelemetrySample $latest, iterable $rows = []): array
    {
        $now = CarbonImmutable::now();
        $clock = (float) $now->format('U.u');
        $ttl = max(1, min(300, (int) config('rms.telemetry_stale_seconds', 30)));
        $identity = $this->identity($robot);
        $profiles = $this->profiles($robot);
        $current = [];
        $latestProfiles = [];
        $latestSample = $latest?->getAttributes() ?? [];
        foreach (ServoCurrentEstimator::AXES as $axis) {
            $versions = is_array($profiles[$axis] ?? null) ? array_values($profiles[$axis]) : [];
            $profile = $versions !== [] ? $versions[array_key_last($versions)] : null;
            $profile = is_array($profile) ? $profile : null;
            $latestProfiles[$axis] = $profile;
            $current[$axis] = $this->reading($axis, $identity, $profile, $latestSample, $clock, true, $ttl);
        }
        $series = [];
        foreach ($rows as $row) {
            $sample = $row instanceof Model ? $row->getAttributes() : (array) $row;
            $at = ServoCurrentEstimator::timestamp($sample['sampled_at'] ?? null);
            if ($at === null) {
                continue;
            }
            $values = [];
            $profileIds = [];
            foreach (ServoCurrentEstimator::AXES as $axis) {
                $result = $this->reading($axis, $identity, null, $sample, $clock, false, $ttl);
                $values[$axis] = $result['amps'];
                $profileIds[$axis] = $result['profile_id'];
            }
            $series[] = [
                'time' => CarbonImmutable::parse($sample['sampled_at'], 'UTC')->timezone('Asia/Jakarta')->format('d/m H:i:s'),
                'at_ms' => (int) round($at * 1000), 'axes' => $values, 'profile_ids' => $profileIds,
            ];
        }

        return [
            'robot_id' => (int) $robot->id, 'kind' => 'estimated', 'unit' => 'Arms',
            'formula_version' => 'torque_kt_v1', 'accuracy_verified' => false,
            'storage' => 'database', 'saved_at' => data_get($latest?->servo_current_estimate, 'captured_at'),
            'max_gap_seconds' => $ttl,
            'server_time' => $now->toIso8601String(), 'sample_id' => $latest?->id,
            'sampled_wib' => $latest?->sampled_at?->timezone('Asia/Jakarta')->format('d M Y H:i:s'),
            'axes' => $current, 'profiles' => $latestProfiles, 'series' => $series,
        ];
    }

    private function reading(string $axis, array $identity, ?array $currentProfile, array $sample, float $now, bool $live, int $ttl): array
    {
        $empty = ['axis' => $axis, 'amps' => null, 'torque_percent' => null,
            'kind' => 'estimated', 'unit' => 'Arms', 'accuracy_verified' => false,
            'profile_id' => null, 'expires_in_seconds' => 0, 'stored' => false];
        $fail = static fn (string $status): array => array_replace($empty,
            ['status' => $status, 'reason' => ServoCurrentEstimator::REASONS[$status]]);
        if ($sample === []) {
            // Only creates an unavailable-state description, not an estimate.
            return array_replace($this->estimator->estimate($axis, $identity, $currentProfile, [], $now), ['stored' => false]);
        }
        $snapshot = $sample['servo_current_estimate'] ?? null;
        if ($snapshot === null) {
            return $fail('not_stored');
        }
        $snapshot = is_string($snapshot) ? json_decode($snapshot, true) : $snapshot;
        $saved = is_array($snapshot) ? ($snapshot['axes'][$axis] ?? null) : null;
        if (! is_array($saved) || ($snapshot['schema_version'] ?? null) !== 1
            || ($snapshot['kind'] ?? null) !== 'estimated' || ($snapshot['unit'] ?? null) !== 'Arms'
            || ($snapshot['formula_version'] ?? null) !== 'torque_kt_v1'
            || ! is_string($saved['status'] ?? null)
            || ! array_key_exists($saved['status'], ServoCurrentEstimator::REASONS)) {
            return $fail('invalid_saved_data');
        }
        $result = array_replace($empty, $saved, ['axis' => $axis, 'kind' => 'estimated', 'unit' => 'Arms',
            'accuracy_verified' => false, 'stored' => true, 'expires_in_seconds' => 0]);
        if (($result['status'] === 'estimated' && (! is_numeric($result['amps']) || ! is_finite((float) $result['amps']) || (float) $result['amps'] < 0))
            || ($result['status'] !== 'estimated' && $result['amps'] !== null)) {
            return $fail('invalid_saved_data');
        }
        if (! $live) {
            return $result;
        }
        foreach ($identity as $key => $expected) {
            if (! is_array($snapshot['identity'] ?? null) || ($snapshot['identity'][$key] ?? null) !== $expected) {
                return $fail('identity_changed');
            }
        }
        if ($currentProfile === null) {
            return $fail('not_configured');
        }
        if (($currentProfile['enabled'] ?? false) !== true) {
            return $fail('disabled');
        }
        if (($currentProfile['id'] ?? null) !== ($result['profile_id'] ?? null)) {
            return $fail('pending_sample');
        }
        $at = ServoCurrentEstimator::timestamp($sample['sampled_at'] ?? null);
        $torqueAt = ServoCurrentEstimator::timestamp($sample['torque_sampled_at'] ?? $sample['sampled_at'] ?? null);
        $receivedAt = ServoCurrentEstimator::timestamp($sample['received_at'] ?? null);
        if ($at === null || $torqueAt === null || $receivedAt === null || max($at, $torqueAt, $receivedAt) > $now + 5) {
            return $fail('invalid_read');
        }
        $remaining = min($at, $torqueAt, $receivedAt) + $ttl - $now;
        if ($remaining <= 0) {
            return $fail('stale');
        }
        $result['expires_in_seconds'] = $result['status'] === 'estimated' ? $remaining : 0;

        return $result;
    }
}
