<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\RmsModel as Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MaintenanceRecommendation extends Model
{
    protected $fillable = [
        'robot_id',
        'robot_health_assessment_id',
        'maintenance_plan_id',
        'source',
        'priority',
        'status',
        'title',
        'recommendation_text',
        'evidence',
        'generated_at',
        'acknowledged_at',
        'acknowledged_by',
        'closed_at',
        'closure_note',
    ];

    protected function casts(): array
    {
        return [
            'robot_id' => 'integer',
            'robot_health_assessment_id' => 'integer',
            'maintenance_plan_id' => 'integer',
            'evidence' => 'array',
            'generated_at' => 'immutable_datetime',
            'acknowledged_at' => 'immutable_datetime',
            'closed_at' => 'immutable_datetime',
        ];
    }

    public function robot(): BelongsTo
    {
        return $this->belongsTo(Robot::class);
    }

    public function healthAssessment(): BelongsTo
    {
        return $this->belongsTo(RobotHealthAssessment::class);
    }

    public function maintenancePlan(): BelongsTo
    {
        return $this->belongsTo(MaintenancePlan::class);
    }
}

