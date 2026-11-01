<?php

declare(strict_types=1);

namespace App\Services\Rms;

use App\Models\AlarmCountermeasure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class AlarmCountermeasureStore
{
    public function matching(array $context): Builder
    {
        return AlarmCountermeasure::query()
            ->where('controller_model_key', $context['controller_model_key'])
            ->where('alarm_code', $context['alarm_code'])
            ->where(function (Builder $query) use ($context): void {
                $query->where('all_subcodes', true)->orWhere('subcode', $context['subcode']);
            })
            ->where(function (Builder $query) use ($context): void {
                $query->whereNull('scope_robot_id')->orWhere('scope_robot_id', $context['robot_id']);
            })
            ->where(function (Builder $query) use ($context): void {
                $query->whereNull('firmware_version');
                if ($context['firmware_version'] !== null) {
                    $query->orWhere('firmware_version', $context['firmware_version']);
                }
            });
    }

    public function save(array $context, array $data, ?AlarmCountermeasure $existing = null): AlarmCountermeasure
    {
        return DB::transaction(function () use ($context, $data, $existing): AlarmCountermeasure {
            if ($existing !== null) {
                $row = $this->matching($context)->whereKey($existing->id)->lockForUpdate()->firstOrFail();
                if ($row->version !== (int) $data['version']) {
                    throw ValidationException::withMessages([
                        'version' => 'Catatan sudah diubah oleh pengguna lain. Salin perubahan Anda, lalu muat ulang halaman edit.',
                    ]);
                }
            } else {
                $row = new AlarmCountermeasure([
                    'controller_model' => $context['controller_model'],
                    'controller_model_key' => $context['controller_model_key'],
                    'alarm_code' => $context['alarm_code'], 'subcode' => $context['subcode'],
                    'source_type' => $data['source_type'],
                    'origin' => array_diff_key($context, ['robot' => true]), 'version' => 0,
                ]);
            }
            $row->fill([
                'all_subcodes' => $data['subcode_scope'] === 'all',
                'scope_robot_id' => $data['robot_scope'] === 'robot' ? $context['robot_id'] : null,
                'firmware_version' => $data['firmware_scope'] === 'current' ? $context['firmware_version'] : null,
                'title' => $data['title'], 'alarm_content' => $data['alarm_content'] ?? null,
                'cause' => $data['cause'] ?? null, 'measure' => $data['measure'],
                'source_reference' => $data['source_reference'] ?? null,
                'source_url' => $data['source_url'] ?? null, 'source_revision' => $data['source_revision'] ?? null,
                'reported_by' => $data['reported_by'], 'work_area' => $data['work_area'] ?? null,
                'result' => $row->source_type === 'yaskawa_manual' ? 'not_tested' : $data['result'],
                'result_notes' => $row->source_type === 'yaskawa_manual' ? null : ($data['result_notes'] ?? null),
                'is_active' => (bool) $data['is_active'], 'version' => $row->version + 1,
            ]);
            if ($existing !== null && $data['subcode_scope'] === 'exact' && $row->getOriginal('all_subcodes')) {
                $row->subcode = $context['subcode'];
            }
            $row->save();
            $row->revisions()->create([
                'version' => $row->version, 'edited_by' => $data['reported_by'],
                'payload' => $row->only($row->getFillable()), 'recorded_at' => now(),
            ]);

            return $row;
        }, 3);
    }
}
