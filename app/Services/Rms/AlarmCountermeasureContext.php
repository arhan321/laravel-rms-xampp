<?php

declare(strict_types=1);

namespace App\Services\Rms;

use App\Models\RobotAlarmEvent;
use App\Models\RobotAlarmHistoryEntry;
use App\Models\RobotAlarmSnapshot;

/** Resolves an observed alarm from stored evidence, never from client-supplied alarm fields. */
final class AlarmCountermeasureContext
{
    public function __construct(private readonly AlarmTextParser $parser) {}

    public function resolve(string $kind, string $record, int $alarmIndex = 0): array
    {
        $class = match ($kind) {
            'history' => RobotAlarmHistoryEntry::class,
            'event' => RobotAlarmEvent::class,
            'snapshot' => RobotAlarmSnapshot::class,
            default => abort(404),
        };
        $entry = $class::query()->with('robot.controller')->findOrFail($record);
        abort_unless($entry->robot !== null, 404);
        if ($kind === 'snapshot') {
            $alarm = $this->snapshotAlarms($entry)[$alarmIndex] ?? null;
            abort_unless(is_array($alarm), 404, 'Alarm pada pembacaan ini tidak dapat diidentifikasi.');
        } else {
            $alarm = [
                'alarm_code' => $entry->alarm_code, 'subcode' => $entry->subcode,
                'alarm_name' => $entry->alarm_name,
                'controller_time_raw' => $kind === 'history' ? $entry->controller_time_raw
                    : data_get($entry->details, 'controller_time_raw'),
            ];
        }
        $code = $this->cleanCode($alarm['alarm_code'] ?? null);
        abort_if($code === null, 422, 'Kode alarm belum tersedia pada record ini.');
        $controller = $entry->robot->controller;
        $model = trim((string) $controller?->model);

        return [
            'kind' => $kind, 'record' => (string) $entry->id,
            'alarm_index' => $kind === 'snapshot' ? $alarmIndex : 0,
            'robot' => $entry->robot, 'robot_id' => (int) $entry->robot_id,
            'controller_model' => $model, 'controller_model_key' => self::modelKey($model),
            'firmware_version' => self::firmwareKey($controller?->firmware_version),
            'alarm_code' => $code, 'subcode' => $this->cleanCode($alarm['subcode'] ?? null),
            'alarm_name' => (string) ($alarm['alarm_name'] ?? ''),
            'controller_time_raw' => $alarm['controller_time_raw'] ?? null,
            'category' => $kind === 'history' ? $entry->category : null,
        ];
    }

    /** @return array<int, array{alarm_code:string, subcode:?string, alarm_name:string, controller_time_raw:?string}> */
    public function snapshotAlarms(RobotAlarmSnapshot $snapshot): array
    {
        if (! $snapshot->read_ok || data_get($snapshot->raw_payload, 'simulated') === true) {
            return [];
        }
        $alarms = $snapshot->parsed_alarms;
        if (! is_array($alarms) || $alarms === []) {
            $alarms = data_get($snapshot->raw_payload, 'active_alarms');
        }
        if (! is_array($alarms) || $alarms === []) {
            $alarms = $this->parser->parse($snapshot->sdk_text)['alarms'];
        }
        $result = [];
        foreach (array_slice($alarms, 0, 200) as $alarm) {
            if (! is_array($alarm) || ($code = $this->cleanCode($alarm['alarm_code'] ?? null)) === null) {
                continue;
            }
            $result[] = [
                'alarm_code' => $code, 'subcode' => $this->cleanCode($alarm['subcode'] ?? null),
                'alarm_name' => (string) ($alarm['alarm_name'] ?? ''),
                'controller_time_raw' => isset($alarm['controller_time_raw'])
                    ? (string) $alarm['controller_time_raw'] : null,
            ];
        }

        return $result;
    }

    public static function modelKey(string $model): string
    {
        return strtoupper(preg_replace('/[\s-]+/', '', trim($model)) ?? '');
    }

    public static function firmwareKey(?string $firmware): ?string
    {
        $key = strtoupper(trim($firmware ?? ''));

        return $key === '' ? null : $key;
    }

    private function cleanCode(mixed $value): ?string
    {
        if (! is_string($value) && ! is_int($value)) {
            return null;
        }
        $value = trim((string) $value);

        return $value !== '' && strlen($value) <= 60 && ! preg_match('/[\x00-\x1f\x7f]/', $value)
            ? $value : null;
    }
}
