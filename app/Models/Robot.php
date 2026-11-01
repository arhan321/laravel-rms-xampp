<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\RmsModel as Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Robot extends Model
{
    protected $fillable = [
        'controller_id',
        'robot_group_id',
        'robot_code',
        'name',
        'model',
        'serial_number',
        'robot_type',
        'nameplate_date',
        'control_group',
        'axis_count',
        'line_name',
        'station_name',
        'is_active',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'controller_id' => 'integer',
            'robot_group_id' => 'integer',
            'axis_count' => 'integer',
            'is_active' => 'boolean',
            'metadata' => 'array',
        ];
    }

    public function controller(): BelongsTo
    {
        return $this->belongsTo(RobotController::class);
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(RobotGroup::class, 'robot_group_id');
    }

    public function telemetrySamples(): HasMany
    {
        return $this->hasMany(RobotTelemetrySample::class);
    }

    public function operatingTimeSamples(): HasMany
    {
        return $this->hasMany(RobotOperatingTimeSample::class);
    }

    public function alarmSnapshots(): HasMany
    {
        return $this->hasMany(RobotAlarmSnapshot::class);
    }

    public function alarmEvents(): HasMany
    {
        return $this->hasMany(RobotAlarmEvent::class);
    }

    public function connectionEvents(): HasMany
    {
        return $this->hasMany(RobotConnectionEvent::class);
    }

    public function healthAssessments(): HasMany
    {
        return $this->hasMany(RobotHealthAssessment::class);
    }

    public function maintenancePlans(): HasMany
    {
        return $this->hasMany(MaintenancePlan::class);
    }

    public function maintenanceLogs(): HasMany
    {
        return $this->hasMany(MaintenanceLog::class);
    }

    public function maintenanceRecommendations(): HasMany
    {
        return $this->hasMany(MaintenanceRecommendation::class);
    }
}
