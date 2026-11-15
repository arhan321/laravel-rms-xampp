<?php

declare(strict_types=1);

namespace App\Models;

final class RobotAlarmFollowUpRevision extends RmsModel
{
    protected $fillable = ['version', 'action', 'recorded_by', 'payload'];

    protected function casts(): array
    {
        return ['payload' => 'array'];
    }
}
