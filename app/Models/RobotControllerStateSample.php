<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\RmsModel as Model;

final class RobotControllerStateSample extends Model
{
    protected $fillable = [
        'robot_id', 'telemetry_sample_id', 'sampled_at', 'received_at',
        'running', 'servo_on', 'alarm', 'hold', 'error', 'control_mode', 'cycle_mode',
    ];

    protected function casts(): array
    {
        return [
            'sampled_at' => 'immutable_datetime', 'received_at' => 'immutable_datetime',
            'running' => 'boolean', 'servo_on' => 'boolean', 'alarm' => 'boolean',
            'hold' => 'boolean', 'error' => 'boolean',
        ];
    }
}

