<?php

declare(strict_types=1);

namespace App\Services\Rms;

use App\Models\Robot;
use App\Models\RobotAlarmHistoryEntry;
use App\Models\RobotAlarmSnapshot;
use Illuminate\Support\Facades\DB;

/** Copies identifiable YMConnect history entries; a poll is not an alarm occurrence. */
final class AlarmHistoryImporter
{
    public function import(RobotAlarmSnapshot $snapshot): array
    {
        $raw = $snapshot->raw_payload;
        if (! is_array($raw) || ($raw['simulated'] ?? false) === true) {
            return ['imported' => 0, 'recognized' => 0];
        }
        $history = $raw['alarm_history'] ?? null;
        if (! is_array($history) || ! is_array($history['categories'] ?? null)) {
            return ['imported' => 0, 'recognized' => 0];
        }

        return DB::transaction(function () use ($snapshot, $history): array {
            // Serialize collector replay and a one-time historical backfill per robot.
            Robot::query()->whereKey($snapshot->robot_id)->lockForUpdate()->firstOrFail();
            $recognized = 0;
            $imported = 0;
            foreach (array_slice($history['categories'], 0, 5) as $category) {
                if (! is_array($category) || ($category['read_ok'] ?? false) !== true
                    || ! in_array($category['category'] ?? null,
                        ['Major', 'Minor', 'UserSystem', 'User', 'Offline'], true)
                    || ! is_array($category['alarms'] ?? null)) {
                    continue;
                }
                foreach (array_slice($category['alarms'], 0, 20) as $alarm) {
                    if (! is_array($alarm)) {
                        continue;
                    }
                    $code = $this->clean($alarm['alarm_code'] ?? null, 60);
                    $subcode = $this->clean($alarm['subcode'] ?? null, 60);
                    $name = $this->clean($alarm['alarm_name'] ?? null, 255);
                    $controllerTime = $this->clean($alarm['controller_time_raw'] ?? null, 120);
                    // Without a controller timestamp repeated polls cannot be deduplicated safely.
                    if ($code === null || $name === null || $controllerTime === null) {
                        continue;
                    }
                    $recognized++;
                    $fingerprint = hash('sha256', implode("\x1f", [
                        $category['category'], $code, $subcode ?? '', $name, $controllerTime,
                    ]));
                    $row = RobotAlarmHistoryEntry::query()->firstOrCreate(
                        ['robot_id' => $snapshot->robot_id, 'fingerprint' => $fingerprint],
                        [
                            'first_snapshot_id' => $snapshot->id,
                            'category' => $category['category'], 'alarm_code' => $code,
                            'subcode' => $subcode, 'alarm_name' => $name,
                            'controller_time_raw' => $controllerTime,
                            'first_observed_at' => $snapshot->sampled_at,
                            'last_observed_at' => $snapshot->sampled_at,
                        ]
                    );
                    if ($row->wasRecentlyCreated) {
                        $imported++;
                    } elseif ($row->last_observed_at->lessThan($snapshot->sampled_at)) {
                        $row->update(['last_observed_at' => $snapshot->sampled_at]);
                    }
                }
            }

            return ['imported' => $imported, 'recognized' => $recognized];
        }, 3);
    }

    private function clean(mixed $value, int $limit): ?string
    {
        if (! is_string($value) || strlen($value) > $limit) {
            return null;
        }
        $text = trim($value);

        return $text !== '' && ! preg_match('/[\x00-\x1f\x7f]/', $text) ? $text : null;
    }
}
