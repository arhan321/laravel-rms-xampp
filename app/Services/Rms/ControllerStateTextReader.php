<?php

declare(strict_types=1);

namespace App\Services\Rms;

use App\Models\Robot;

/** Display evidence from the structured collector state, never a safety-rated status. */
final class ControllerStateTextReader
{
    public function __construct(private readonly ControllerStatePayload $parser) {}

    public function latest(Robot $robot): ?array
    {
        $attempt = $robot->telemetrySamples()
            ->where('received_at', '>=', now()->subSeconds(30))
            ->where('received_at', '<=', now()->addSeconds(5))
            ->where('sampled_at', '>=', now()->subSeconds(60))
            ->where('sampled_at', '<=', now()->addSeconds(5))
            ->orderByDesc('received_at')->orderByDesc('id')->limit(12)->get()
            ->first(static function ($sample): bool {
                $read = data_get($sample->raw_payload, 'state_read');

                return is_array($read) && (($read['read_ok'] ?? false) === true
                    || ($read['message'] ?? 'unavailable') !== 'unavailable'
                    || (is_int($read['status_code'] ?? null) && $read['status_code'] >= 0));
            });
        if ($attempt === null || data_get($attempt->raw_payload, 'simulated') === true
            || data_get($attempt->raw_payload, 'state_read.read_ok') !== true) {
            return null;
        }
        $state = $this->parser->parse($attempt->raw_payload);
        if ($state === null) {
            return null;
        }

        return [
            'source' => 'ymconnect_structured_state',
            'sampled_at' => $attempt->sampled_at?->toIso8601String(),
            'received_at' => $attempt->received_at?->toIso8601String(),
            'valid_until' => min($attempt->received_at->addSeconds(30), $attempt->sampled_at->addSeconds(60))->toIso8601String(),
            'control_mode' => $state['control_mode'],
            'cycle_mode' => $state['cycle_mode'],
            'running' => $state['running'],
            'servo_on' => $state['servo_on'],
            'alarming' => $state['alarm'],
            'hold' => $state['hold'],
            'error' => $state['error'],
            'verified' => false,
        ];
    }
}
