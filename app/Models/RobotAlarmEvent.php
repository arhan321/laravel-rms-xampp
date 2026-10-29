<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\RmsModel as Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RobotAlarmEvent extends Model
{
    protected $fillable = [
        'robot_id',
        'event_key',
        'alarm_code',
        'subcode',
        'alarm_name',
        'severity',
        'status',
        'started_at',
        'last_seen_at',
        'cleared_at',
        'source',
        'details',
    ];

    protected function casts(): array
    {
        return [
            'robot_id' => 'integer',
            'started_at' => 'immutable_datetime',
            'last_seen_at' => 'immutable_datetime',
            'cleared_at' => 'immutable_datetime',
            'details' => 'array',
        ];
    }

    public function robot(): BelongsTo
    {
        return $this->belongsTo(Robot::class);
    }
}

