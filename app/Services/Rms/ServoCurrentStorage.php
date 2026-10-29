<?php

declare(strict_types=1);

namespace App\Services\Rms;

use App\Models\Robot;

/** Freezes one estimate alongside its source telemetry, independent of any browser. */
final class ServoCurrentStorage
{
    public function __construct(private ServoCurrentEstimator $estimator) {}

    public function identity(Robot $robot): array
    {
        return [
            'robot_id' => (int) $robot->id, 'controller_id' => (int) $robot->controller_id,
            'group' => strtoupper((string) $robot->control_group),
            'model' => (string) $robot->model, 'serial_number' => (string) $robot->serial_number,
            'axis_count' => (int) $robot->axis_count,
        ];
    }

    public function profiles(Robot $robot): array
    {
        $profiles = data_get($robot->metadata, 'servo_current.profiles', []);

        return is_array($profiles) ? $profiles : [];
    }

    /** Return attributes to insert in the SAME transaction/row as the raw torque. */
    public function attributes(Robot $robot, array $sample, bool $backfilled = false): array
    {
        $now = (float) now()->format('U.u');
        $identity = $this->identity($robot);
        $profiles = $this->profiles($robot);
        $sampleAt = ServoCurrentEstimator::timestamp($sample['sampled_at'] ?? null);
        $torqueAt = ServoCurrentEstimator::timestamp($sample['torque_sampled_at'] ?? $sample['sampled_at'] ?? null);
        $axes = [];
        $usedProfiles = [];
        $attributes = [];
        foreach (ServoCurrentEstimator::AXES as $axis) {
            $versions = is_array($profiles[$axis] ?? null) ? $profiles[$axis] : [];
            $profile = $sampleAt !== null && $torqueAt !== null
                ? $this->estimator->profileAt($versions, min($sampleAt, $torqueAt)) : null;
            // History is calculated at the SOURCE time. Late spool delivery is valid
            // history, but it must still be hidden as stale on the live display.
            $result = $this->estimator->estimate($axis, $identity, $profile, $sample, $now, false);
            if (($sampleAt !== null && $sampleAt > $now + 5) || ($torqueAt !== null && $torqueAt > $now + 5)) {
                $result = array_replace($result, ['amps' => null, 'torque_percent' => null,
                    'status' => 'invalid_read', 'reason' => ServoCurrentEstimator::REASONS['invalid_read']]);
            }
            unset($result['expires_in_seconds']);
            $axes[$axis] = $result;
            $usedProfiles[$axis] = $profile;
            $attributes['servo_current_'.strtolower($axis).'_arms'] = $result['amps'];
        }
        $attributes['servo_current_estimate'] = [
            'schema_version' => 1, 'kind' => 'estimated', 'unit' => 'Arms',
            'formula_version' => 'torque_kt_v1', 'accuracy_verified' => false,
            'captured_at' => now()->utc()->format('Y-m-d\TH:i:s.u\Z'),
            'origin' => $backfilled ? 'backfill' : 'ingest',
            'identity' => $identity, 'axes' => $axes, 'profiles' => $usedProfiles,
        ];

        return $attributes;
    }
}
