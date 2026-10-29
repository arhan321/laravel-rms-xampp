<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\RmsModel as Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RobotOperatingTimeSample extends Model
{
    protected $fillable = [
        'robot_id',
        'time_type',
        'sampled_at',
        'received_at',
        'read_ok',
        'status_code',
        'status_message',
        'value_raw',
        'value_seconds',
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
            'value_seconds' => 'integer',
            'raw_payload' => 'array',
        ];
    }

    public function robot(): BelongsTo
    {
        return $this->belongsTo(Robot::class);
    }
}

