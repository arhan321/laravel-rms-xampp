<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

final class RobotAlarmFollowUp extends RmsModel
{
    public const STAGES = [
        'analyzed' => 'Technical Engineer',
        'validated' => 'Section Head',
        'approved' => 'Group Leader',
    ];

    public const STATUS = [
        'draft' => 'Draft',
        'analyzed' => 'Dianalisis',
        'validated' => 'Divalidasi',
        'approved' => 'Disetujui',
    ];

    public const RESULTS = [
        'open' => 'Perlu tindak lanjut',
        'monitor' => 'Dipantau',
        'completed' => 'Pekerjaan selesai',
    ];

    protected $fillable = [
        'robot_id', 'source_kind', 'source_record', 'alarm_code', 'subcode', 'alarm_observed_at',
        'status', 'version', 'evidence', 'work', 'signatures',
    ];

    protected function casts(): array
    {
        return [
            'evidence' => 'array', 'work' => 'array', 'signatures' => 'array',
            'version' => 'integer', 'alarm_observed_at' => 'immutable_datetime',
        ];
    }

    public function number(): string
    {
        return sprintf('FUI-ADM-%s-%06d', $this->created_at->timezone('Asia/Jakarta')->format('Y'), $this->id);
    }

    public function nextStage(): ?string
    {
        return match ($this->status) {
            'draft' => 'analyzed',
            'analyzed' => 'validated',
            'validated' => 'approved',
            default => null,
        };
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(RobotAlarmFollowUpRevision::class, 'follow_up_id');
    }
}
