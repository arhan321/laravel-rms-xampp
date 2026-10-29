<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\RmsModel as Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MaintenanceLog extends Model
{
    protected $fillable = [
        'robot_id',
        'maintenance_plan_id',
        'maintenance_recommendation_id', // Added: nullable FK; existing PM log fields preserved
        'action_type',
        'result',
        'performed_by',
        'started_at',
        'completed_at',
        'findings',
        'action_taken',
        'supporting_data',
        'verified_by',
        'verified_at',
    ];

    protected function casts(): array
    {
        return [
            'robot_id' => 'integer',
            'maintenance_plan_id' => 'integer',
            'maintenance_recommendation_id' => 'integer',
            'started_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'supporting_data' => 'array',
            'verified_at' => 'immutable_datetime',
        ];
    }

    public function robot(): BelongsTo
    {
        return $this->belongsTo(Robot::class);
    }

    public function maintenancePlan(): BelongsTo
    {
        return $this->belongsTo(MaintenancePlan::class);
    }

    public function maintenanceRecommendation(): BelongsTo
    {
        return $this->belongsTo(MaintenanceRecommendation::class);
    }
}

