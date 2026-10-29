<?php

declare(strict_types=1);

namespace App\Services\Rms;

use App\Models\RobotOperatingTimeSample;

class OperatingTimeDisplay
{
    public function __construct(private readonly OperatingTimeTextParser $parser) {}

    /**
     * Display controller hours independently of their eligibility for PM.
     * Historical diagnostic samples remain visible without changing stored data.
     *
     * @return array{hours:?float,seconds:?int,candidate_hours:?float,verified:bool,status:string,text:string,caption:string,sampled_at:?string,sampled_wib:?string}
     */
    public function forSample(?RobotOperatingTimeSample $sample, string $timeType = 'ServoPowerOnTimeRobot'): array
    {
        $isServo = $timeType === 'ServoPowerOnTimeRobot';
        $counterLabel = $isServo ? 'jam servo' : 'waktu controller';
        $sampledAt = $sample?->sampled_at;
        $sampledWib = $sampledAt?->timezone('Asia/Jakarta')->format('d M Y H:i:s');
        $display = [
            'hours' => null,
            'seconds' => null,
            'candidate_hours' => null,
            'verified' => false,
            'status' => 'unavailable',
            'text' => 'Belum tersedia',
            'caption' => 'Belum ada pembacaan '.$counterLabel.'.',
            'sampled_at' => $sampledAt?->toIso8601String(),
            'sampled_wib' => $sampledWib,
        ];

        if ($sample === null || $sample->time_type !== $timeType
            || ! in_array($timeType, ['ServoPowerOnTimeRobot', 'ControllerOnTime'], true)) {
            return $display;
        }

        $stamp = $sampledWib === null ? '' : ' · '.$sampledWib.' WIB';
        if (! $sample->read_ok) {
            return array_replace($display, [
                'status' => 'read_failed',
                'caption' => 'Pembacaan '.$counterLabel.' gagal'.$stamp,
            ]);
        }

        $candidate = $this->parser->parse($sample->value_raw);
        $storedSeconds = $sample->value_seconds;
        $seconds = $storedSeconds !== null && $storedSeconds >= 0
            ? $storedSeconds : ($candidate['seconds'] ?? null);
        if ($seconds === null) {
            return array_replace($display, [
                'caption' => 'Format jam belum dikenali; lihat Robot logs'.$stamp,
            ]);
        }

        $raw = is_array($sample->raw_payload) ? $sample->raw_payload : [];
        $verified = $isServo && $storedSeconds !== null && $storedSeconds >= 0
            && (bool) config('rms_evidence.operating_text_verified', false)
            && ($raw['verified_for_pm'] ?? true) === true
            && ($raw['simulated'] ?? false) !== true;
        $hours = round($seconds / 3600, 4);

        return array_replace($display, [
            'hours' => $hours,
            'seconds' => $seconds,
            'candidate_hours' => $candidate['hours'] ?? null,
            'verified' => $verified,
            'status' => $verified ? 'verified' : 'unverified',
            'text' => number_format($seconds / 3600, 2, ',', '.').' h',
            'caption' => ($isServo ? ($verified ? 'Terverifikasi untuk PM' : 'Perlu pencocokan dengan pendant untuk PM') : 'Controller Power On Time dari pembacaan SDK').$stamp,
        ]);
    }
}
