<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\RmsModel as Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RobotThresholdRule extends Model
{
    protected $guarded = ['id'];
    protected function casts(): array
    {
        return [
            'enabled'=>'boolean', 'unit_verified'=>'boolean', 'approved_at'=>'immutable_datetime',
            'warning_magnitude'=>'decimal:6', 'critical_magnitude'=>'decimal:6',
            'clear_hysteresis_percent'=>'decimal:2', 'last_evaluated_sample_id'=>'integer',
        ];
    }
    public function robot(): BelongsTo { return $this->belongsTo(Robot::class); }
    public function events(): HasMany { return $this->hasMany(RobotThresholdEvent::class, 'threshold_rule_id'); }
    public function isApproved(): bool
    {
        return $this->enabled && $this->unit_verified && $this->approved_at !== null
            && filled($this->approved_by) && filled($this->source_reference);
    }
}

