<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

final class AlarmCountermeasure extends RmsModel
{
    protected $table = 'rms_alarm_countermeasures';

    protected $fillable = [
        'controller_model', 'controller_model_key', 'alarm_code', 'subcode',
        'all_subcodes', 'scope_robot_id', 'firmware_version', 'source_type',
        'title', 'alarm_content', 'cause', 'measure', 'source_reference',
        'source_url', 'source_revision', 'reported_by', 'work_area',
        'result', 'result_notes', 'is_active', 'version', 'origin',
    ];

    protected function casts(): array
    {
        return [
            'all_subcodes' => 'boolean', 'scope_robot_id' => 'integer',
            'is_active' => 'boolean', 'version' => 'integer', 'origin' => 'array',
            'created_at' => 'immutable_datetime', 'updated_at' => 'immutable_datetime',
        ];
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(AlarmCountermeasureRevision::class, 'countermeasure_id');
    }
}
