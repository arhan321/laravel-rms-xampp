<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\RmsModel as Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class RobotAlarmHistoryEntry extends Model
{
    protected $fillable = [
        'robot_id', 'first_snapshot_id', 'fingerprint', 'category', 'alarm_code',
        'subcode', 'alarm_name', 'controller_time_raw', 'first_observed_at', 'last_observed_at',
    ];

    protected function casts(): array
    {
        return [
            'first_observed_at' => 'immutable_datetime',
            'last_observed_at' => 'immutable_datetime',
        ];
    }

    public function robot(): BelongsTo
    {
        return $this->belongsTo(Robot::class);
    }
}

