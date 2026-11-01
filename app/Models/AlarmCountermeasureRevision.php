<?php

declare(strict_types=1);

namespace App\Models;

final class AlarmCountermeasureRevision extends RmsModel
{
    protected $table = 'rms_alarm_countermeasure_revisions';

    public $timestamps = false;

    protected $fillable = ['countermeasure_id', 'version', 'edited_by', 'payload', 'recorded_at'];

    protected function casts(): array
    {
        return ['version' => 'integer', 'payload' => 'array', 'recorded_at' => 'immutable_datetime'];
    }
}
