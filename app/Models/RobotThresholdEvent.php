<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\RmsModel as Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RobotThresholdEvent extends Model
{
    protected $guarded = ['id'];
    protected function casts(): array
    {
        return [
            'started_at'=>'immutable_datetime', 'last_seen_at'=>'immutable_datetime',
            'closed_at'=>'immutable_datetime', 'peak_magnitude'=>'decimal:6',
        ];
    }
    public function robot(): BelongsTo { return $this->belongsTo(Robot::class); }
    public function rule(): BelongsTo { return $this->belongsTo(RobotThresholdRule::class, 'threshold_rule_id'); }
}

