<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\RmsModel as Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RobotHealthAssessment extends Model
{
    protected $fillable = [
        'robot_id',
        'health_status',
        'evaluated_at',
        'rule_version',
        'assessment_source',
        'summary',
        'indicators',
    ];

    protected function casts(): array
    {
        return [
            'robot_id' => 'integer',
            'evaluated_at' => 'immutable_datetime',
            'indicators' => 'array',
        ];
    }

    public function robot(): BelongsTo
    {
        return $this->belongsTo(Robot::class);
    }

    public function recommendations(): HasMany
    {
        return $this->hasMany(MaintenanceRecommendation::class);
    }
}

