<?php

declare(strict_types=1);

namespace App\Models;

class RobotSteelDustInspection extends RmsModel
{
    public const AXES = ['S', 'L', 'U', 'R', 'B', 'T'];

    protected $fillable = [
        'robot_id',
        'maintenance_plan_id',
        'submission_id',
        'inspected_on',
        'unit',
        'readings',
        'inspected_by',
        'limit_reference',
        'limit_approved_by',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'inspected_on' => 'immutable_date',
            'readings' => 'array',
        ];
    }

    public function setInspectedOnAttribute(string $date): void
    {
        $this->attributes['inspected_on'] = $date;
    }

    public function statusFor(string $axis): string
    {
        $reading = $this->readings[$axis] ?? [];
        $value = $reading['value'] ?? null;
        $limit = $reading['limit'] ?? null;

        if ($value === null) {
            return 'Belum diukur';
        }

        if ($limit === null || ! $this->limit_reference || ! $this->limit_approved_by) {
            return 'Batas belum diisi';
        }

        return (float) $value > (float) $limit ? 'Warning' : 'Dalam batas';
    }
}
