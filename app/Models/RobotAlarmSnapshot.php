<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\RmsModel as Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RobotAlarmSnapshot extends Model
{
    protected $fillable = [
        'robot_id',
        'sampled_at',
        'received_at',
        'read_ok',
        'status_code',
        'status_message',
        'sdk_text',
        'active_alarm_count',
        'parsed_alarms',
        'raw_payload',
    ];

    protected function casts(): array
    {
        return [
            'robot_id' => 'integer',
            'sampled_at' => 'immutable_datetime',
            'received_at' => 'immutable_datetime',
            'read_ok' => 'boolean',
            'status_code' => 'integer',
            'active_alarm_count' => 'integer',
            'parsed_alarms' => 'array',
            'raw_payload' => 'array',
        ];
    }

    public function robot(): BelongsTo
    {
        return $this->belongsTo(Robot::class);
    }
}

