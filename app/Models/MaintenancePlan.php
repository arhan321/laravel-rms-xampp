<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\RmsModel as Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MaintenancePlan extends Model
{
    protected $fillable = [
        'robot_id',
        'component_category',
        'title',
        'description',
        'basis',
        'interval_hours',
        'interval_days',
        'next_due_operating_hours',
        'next_due_at',
        'last_completed_at',
        'is_active',
        'created_by',
        // Added PM operator fields; existing columns kept intact.
        'component_axis',
        'grease_specification',
        'reference_document',
        'work_instructions',
    ];

    protected function casts(): array
    {
        return [
            'robot_id' => 'integer',
            'interval_hours' => 'decimal:2',
            'interval_days' => 'integer',
            'next_due_operating_hours' => 'decimal:2',
            'next_due_at' => 'immutable_datetime',
            'last_completed_at' => 'immutable_datetime',
            'is_active' => 'boolean',
        ];
    }

    public function robot(): BelongsTo
    {
        return $this->belongsTo(Robot::class);
    }

    public function logs(): HasMany
    {
        return $this->hasMany(MaintenanceLog::class);
    }

    public function recommendations(): HasMany
    {
        return $this->hasMany(MaintenanceRecommendation::class);
    }
}

