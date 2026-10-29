<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\RmsModel as Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RobotReducerMeasurement extends Model
{
    protected $guarded = ['id'];
    protected function casts(): array
    {
        return [
            'measured_at'=>'immutable_datetime', 'is_verified'=>'boolean',
            'condition_value'=>'decimal:6', 'remaining_life_hours'=>'decimal:2',
            'lower_threshold'=>'decimal:6', 'upper_threshold'=>'decimal:6',
        ];
    }
    public function robot(): BelongsTo { return $this->belongsTo(Robot::class); }
}

