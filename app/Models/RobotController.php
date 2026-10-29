<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\RmsModel as Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RobotController extends Model
{
    protected $fillable = [
        'controller_code',
        'model',
        'ip_address',
        'firmware_version',
        'location',
        'connection_status',
        'last_seen_at',
        'network_verified_at',
        'network_verification_status',
        'network_verification_method',
        'network_verification_message',
        'network_verification_latency_ms',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'last_seen_at' => 'immutable_datetime',
            'network_verified_at' => 'immutable_datetime',
            'network_verification_latency_ms' => 'float',
        ];
    }

    /**
     * Table robots uses controller_id (NOT robot_controller_id).
     * Eloquent's convention for this class would incorrectly guess
     * robot_controller_id without an explicit foreign key.
     */
    public function robots(): HasMany
    {
        return $this->hasMany(Robot::class, 'controller_id', 'id');
    }
}

