<?php

declare(strict_types=1);

namespace App\Services\Rms;

/** Accept both structured packets and the earlier state_read.raw JSON contract. */
final class ControllerStatePayload
{
    public function parse(?array $raw): ?array
    {
        if ($raw === null || ($raw['simulated'] ?? false) === true
            || data_get($raw, 'state_read.read_ok') !== true) {
            return null;
        }

        $state = $raw['controller_state'] ?? null;
        if (! is_array($state)) {
            $text = data_get($raw, 'state_read.raw');
            $state = is_string($text) && strlen($text) <= 8192
                ? json_decode($text, true) : null;
        }
        if (! is_array($state) || ($state['known'] ?? false) !== true) {
            return null;
        }
        foreach (['running', 'servo_on', 'alarm', 'hold', 'error'] as $field) {
            if (! array_key_exists($field, $state) || ! is_bool($state[$field])) {
                return null;
            }
        }

        return [
            'known' => true,
            'running' => $state['running'], 'servo_on' => $state['servo_on'],
            'alarm' => $state['alarm'], 'hold' => $state['hold'], 'error' => $state['error'],
            'control_mode' => in_array($state['control_mode'] ?? null, ['Teach', 'Play', 'Remote'], true)
                ? $state['control_mode'] : null,
            'cycle_mode' => in_array($state['cycle_mode'] ?? null, ['Step', 'Cycle', 'Automatic'], true)
                ? $state['cycle_mode'] : null,
        ];
    }
}
