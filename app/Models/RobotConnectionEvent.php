<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\RmsModel as Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RobotConnectionEvent extends Model
{
    protected $fillable = [
        'robot_id',
        'state',
        'message',
        'ip_address',
        'occurred_at',
        'raw_payload',
    ];

    protected function casts(): array
    {
        return [
            'robot_id' => 'integer',
            'occurred_at' => 'immutable_datetime',
            'raw_payload' => 'array',
        ];
    }

    public function robot(): BelongsTo
    {
        return $this->belongsTo(Robot::class);
    }
}

