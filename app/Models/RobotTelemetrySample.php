<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\RmsModel as Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RobotTelemetrySample extends Model
{
    protected $fillable = [
        'robot_id',
        'collector_session_id',
        'sequence',
        'collector_uptime_seconds',
        'job_name',
        'sampled_at',
        'received_at',
        'position_ok',
        'position_status_code',
        'position_status_message',
        'position_sampled_at',
        'position_pulses',
        'torque_ok',
        'torque_status_code',
        'torque_status_message',
        'torque_sampled_at',
        'torque_unit',
        'torque_axes',
        'raw_payload',
        'servo_current_estimate',
        'servo_current_s_arms',
        'servo_current_l_arms',
        'servo_current_u_arms',
        'servo_current_r_arms',
        'servo_current_b_arms',
        'servo_current_t_arms',
    ];

    protected function casts(): array
    {
        return [
            'robot_id' => 'integer',
            'sequence' => 'integer',
            'collector_uptime_seconds' => 'integer',
            'sampled_at' => 'immutable_datetime',
            'received_at' => 'immutable_datetime',
            'position_ok' => 'boolean',
            'position_status_code' => 'integer',
            'position_sampled_at' => 'immutable_datetime',
            'position_pulses' => 'array',
            'torque_ok' => 'boolean',
            'torque_status_code' => 'integer',
            'torque_sampled_at' => 'immutable_datetime',
            'torque_axes' => 'array',
            'raw_payload' => 'array',
            'servo_current_estimate' => 'array',
            'servo_current_s_arms' => 'float',
            'servo_current_l_arms' => 'float',
            'servo_current_u_arms' => 'float',
            'servo_current_r_arms' => 'float',
            'servo_current_b_arms' => 'float',
            'servo_current_t_arms' => 'float',
        ];
    }

    public function robot(): BelongsTo
    {
        return $this->belongsTo(Robot::class);
    }
}
