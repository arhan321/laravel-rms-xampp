<?php

declare(strict_types=1);

namespace App\Services\Rms;

use App\Models\Robot;
use App\Models\RobotOperatingTimeSample;
use App\Models\RobotTelemetrySample;

/** Presentation-only extraction. Unknown/failed SDK reads never become valid facts. */
final class CockpitEvidence
{
    private const COUNTERS = [
        'ControllerOnTime' => 'Controller power',
        'ServoPowerOnTimeRobot' => 'Servo power R1',
        'PlayBackTimeRobot' => 'Playback R1',
        'MovingTimeRobot' => 'Moving R1',
    ];

    public function __construct(private readonly OperatingTimeTextParser $timeParser) {}

    public function job(Robot $robot, bool $fresh): array
    {
        if (! $fresh) {
            return ['name' => null, 'line' => null, 'step' => null, 'speed_override_percent' => null];
        }

        $sample = $robot->telemetrySamples()->where('received_at', '>=', now()->subSeconds(30))
            ->where('sampled_at', '>=', now()->subSeconds(60))
            ->orderByDesc('received_at')->orderByDesc('id')->limit(8)->get()
            ->first(static function (RobotTelemetrySample $row): bool {
                $read = data_get($row->raw_payload, 'job_read');

                return is_array($read) && (($read['read_ok'] ?? false) === true
                    || ($read['message'] ?? 'unavailable') !== 'unavailable');
            });
        $job = data_get($sample?->raw_payload, 'job');
        if (data_get($sample?->raw_payload, 'simulated') === true
            || data_get($sample?->raw_payload, 'job_read.read_ok') !== true
            || ! is_array($job)) {
            return ['name' => null, 'line' => null, 'step' => null, 'speed_override_percent' => null];
        }

        $name = $job['name'] ?? null;
        $name = is_string($name) && trim($name) !== '' && strlen($name) <= 120 ? trim($name) : null;

        return [
            'name' => $name,
            'line' => $this->boundedInteger($job['line'] ?? null),
            'step' => $this->boundedInteger($job['step'] ?? null),
            'speed_override_percent' => $this->boundedNumber($job['speed_override_percent'] ?? null, 0, 100),
        ];
    }

    public function identity(Robot $robot): array
    {
        $samples = $robot->telemetrySamples()->orderByDesc('received_at')->limit(60)->get();
        foreach ($samples as $sample) {
            $raw = $sample->raw_payload;
            if (! is_array($raw) || ($raw['simulated'] ?? false) === true) {
                continue;
            }
            $parts = data_get($raw, 'system_info');
            if (! is_array($parts)) {
                continue;
            }
            $result = [];
            foreach (['robot_r1', 'station_s1', 'application_a1'] as $part) {
                $piece = $parts[$part] ?? null;
                $result[$part] = is_array($piece) && ($piece['read_ok'] ?? false) === true
                    ? [
                        'model_name' => $this->shortText($piece['model_name'] ?? null),
                        'software_version' => $this->shortText($piece['software_version'] ?? null),
                    ] : null;
            }
            $result['sampled_at'] = $sample->sampled_at?->timezone('Asia/Jakarta')->format('d M Y H:i:s');

            return $result;
        }

        return ['robot_r1' => null, 'station_s1' => null, 'application_a1' => null, 'sampled_at' => null];
    }

    public function counters(Robot $robot): array
    {
        $samples = RobotOperatingTimeSample::query()->where('robot_id', $robot->id)
            ->whereIn('time_type', array_keys(self::COUNTERS))
            ->orderByDesc('sampled_at')->orderByDesc('id')->limit(40)->get()->unique('time_type')->keyBy('time_type');
        $result = [];
        foreach (self::COUNTERS as $type => $label) {
            $sample = $samples->get($type);
            $parsed = $sample && $sample->read_ok ? $this->timeParser->parse($sample->value_raw) : null;
            $result[$type] = [
                'label' => $label,
                'hours' => $parsed === null ? null : round($parsed['seconds'] / 3600, 2),
                'read_ok' => $sample?->read_ok === true,
                'sampled_at' => $sample?->sampled_at?->timezone('Asia/Jakarta')->format('d M Y H:i:s'),
                'verified_for_pm' => $type === 'ServoPowerOnTimeRobot' && $sample?->value_seconds !== null
                    && config('rms_evidence.operating_text_verified', false)
                    && data_get($sample?->raw_payload, 'verified_for_pm') === true,
            ];
        }

        return $result;
    }

    private function boundedInteger(mixed $value): ?int
    {
        return is_int($value) && $value >= 0 && $value <= 1_000_000 ? $value : null;
    }

    private function boundedNumber(mixed $value, float $minimum, float $maximum): ?float
    {
        return is_numeric($value) && is_finite((float) $value)
            && (float) $value >= $minimum && (float) $value <= $maximum ? (float) $value : null;
    }

    private function shortText(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' && strlen($value) <= 120 ? trim($value) : null;
    }
}
