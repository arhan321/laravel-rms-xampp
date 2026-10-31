<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Models\Robot;
use Carbon\CarbonImmutable;
use App\Support\RmsDatabase;
use Illuminate\Http\Request;
use App\Models\MaintenanceLog;
use App\Models\RobotAlarmEvent;
use Illuminate\Validation\Rule;
use App\Models\RobotAlarmSnapshot;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Controller;
use App\Models\RobotConnectionEvent;
use App\Models\RobotTelemetrySample;
use App\Models\RobotHealthAssessment;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Schema;
use App\Models\RobotOperatingTimeSample;
use App\Models\MaintenanceRecommendation;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * RMS Yaskawa: read-only, cross-robot logs with WIB time filters.
 * Works with the original 11 RMS migrations/models. No robot commands.
 */
final class RobotLogController extends Controller
{
    private const TZ = 'Asia/Jakarta';
    private const PAGE_SIZES = [25, 50, 100];
    private const XLSX_LIMIT = 5000; // 5.000 total rows across categories, cap shown in the report.

    // Quick preset for user-selected important events (excluding high-frequency telemetry).
    private const IMPORTANT_EVENTS = ['alarm_event', 'connection', 'health', 'maintenance', 'recommendation'];

    // Expressions are hard-coded: never construct SQL identifiers from request input.
    private const SOURCES = [
        'servo_monitor' => [RobotTelemetrySample::class, 'robot_telemetry_samples', 'sampled_at'],
        'servo_torque' => [RobotTelemetrySample::class, 'robot_telemetry_samples', 'sampled_at'],
        'control_power' => [RobotOperatingTimeSample::class, 'robot_operating_time_samples', 'sampled_at'],
        'operating' => [RobotOperatingTimeSample::class, 'robot_operating_time_samples', 'sampled_at'],
        'alarm_snapshot' => [RobotAlarmSnapshot::class, 'robot_alarm_snapshots', 'sampled_at'],
        'alarm_event' => [RobotAlarmEvent::class, 'robot_alarm_events', 'started_at'],
        'connection' => [RobotConnectionEvent::class, 'robot_connection_events', 'occurred_at'],
        'health' => [RobotHealthAssessment::class, 'robot_health_assessments', 'evaluated_at'],
        // Completed_at is preferred; in-progress actions use started_at, otherwise created_at.
        'maintenance' => [MaintenanceLog::class, 'maintenance_logs', 'COALESCE(completed_at, started_at, created_at)'],
        'recommendation' => [MaintenanceRecommendation::class, 'maintenance_recommendations', 'generated_at'],
    ];

    // Keep old telemetry bookmarks while exposing the two readings separately.
    private const TYPE_ALIASES = ['telemetry' => ['servo_monitor', 'servo_torque']];

    // Avoid loading large collector raw_payload JSONB for every visible row.
    private const DETAIL_COLUMNS = [
        'servo_monitor' => ['id', 'sequence', 'position_ok', 'position_status_code', 'position_status_message', 'position_sampled_at', 'position_pulses'],
        'servo_torque' => ['id', 'sequence', 'torque_ok', 'torque_status_code', 'torque_status_message', 'torque_sampled_at', 'torque_unit', 'torque_axes'],
        'control_power' => ['id', 'time_type', 'read_ok', 'status_code', 'status_message', 'value_raw', 'value_seconds'],
        'operating' => ['id', 'time_type', 'read_ok', 'status_code', 'status_message', 'value_raw', 'value_seconds'],
        'alarm_snapshot' => ['id', 'read_ok', 'sdk_text', 'active_alarm_count', 'parsed_alarms', 'status_code', 'status_message'],
        'alarm_event' => ['id', 'event_key', 'status', 'alarm_code', 'alarm_name', 'severity', 'subcode', 'source', 'last_seen_at', 'cleared_at', 'details'],
        'connection' => ['id', 'state', 'message', 'ip_address'],
        'health' => ['id', 'health_status', 'summary', 'rule_version', 'assessment_source', 'indicators'],
        'maintenance' => ['id', 'result', 'action_type', 'performed_by', 'findings', 'action_taken', 'started_at', 'completed_at', 'verified_by', 'verified_at'],
        'recommendation' => ['id', 'status', 'title', 'priority', 'recommendation_text', 'source', 'evidence'],
    ];

    private ?string $maintenanceTimestampExpression = null;

    public function index(Request $request)
    {
        $filters = $this->filters($request);
        // Numbered navigation needs a total count and a stable page number.
        $page = $this->query($filters)
            ->paginate($filters['per_page'])
            ->appends($this->queryParams($filters))
            ->fragment('rms-logs-results');

        return view('rms.logs.index', [
            'title' => 'Robot log history',
            'robots' => Robot::query()->orderBy('robot_code')->get(['id', 'robot_code', 'name']),
            'entries' => $this->hydrateRows(collect($page->items())),
            'page' => $page,
            'filters' => $filters,
            'types' => $this->types(),
            'exportUrl' => route('rms.logs.export', $this->queryParams($filters)),
        ]);
    }

    /**
     * Export XLSX untuk teknisi; selalu menggunakan filter UI yang sama.
     * Sumber log dipilih satu per satu supaya alarm tidak tenggelam
     * di antara ribuan sampel telemetry.
     */
    public function export(Request $request): StreamedResponse
    {
        $filters = $this->filters($request);
        if (!class_exists(\PhpOffice\PhpSpreadsheet\Spreadsheet::class)) {
            abort(500, 'PhpSpreadsheet belum tersedia. Jalankan composer require phpoffice/phpspreadsheet.');
        }

        $selected = $filters['types'] === [] ? array_keys(self::SOURCES) : $filters['types'];
        // Each export category runs separately, but must keep the same counter
        // partition as the combined web query when Control Power Time is selected.
        $filters['separate_control_power'] = in_array('control_power', $selected, true);
        $name = 'RMS_Yaskawa_Log_Detail_' . CarbonImmutable::now(self::TZ)->format('Ymd_His') . '.xlsx';

        return response()->streamDownload(function () use ($filters, $selected): void {
            $counts = [];
            foreach ($selected as $type) {
                $single = $filters;
                $single['types'] = [$type];
                $counts[$type] = (int) $this->query($single)->count();
            }

            $quotas = $this->exportQuotas($counts, self::XLSX_LIMIT);
            $entries = [];
            foreach ($quotas as $type => $allowed) {
                if ($allowed <= 0) continue;
                $single = $filters;
                $single['types'] = [$type];
                $cursor = $this->query($single)->limit($allowed)->cursor();
                foreach ($cursor->chunk(200) as $batch) {
                    foreach ($this->hydrateRows(collect($batch)) as $entry) {
                        $entries[] = $entry;
                    }
                }
            }
            // Sort chronologically across sources; timestamps are stored as UTC sort keys.
            usort($entries, static function (array $a, array $b): int {
                return strcmp((string) $b['sort_utc'], (string) $a['sort_utc']);
            });

            (new \App\Services\RmsRobotLogExcelExporter())->write(
                $entries,
                $filters,
                $counts,
                $selected,
                $this->types(),
                self::XLSX_LIMIT
            );
        }, $name, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * Limit total rows, but reserve capacity for each selected log type.
     * Unlike global LIMIT, 48 operating-hour entries + 2 alarm events
     * will not disappear behind a high-frequency telemetry stream.
     */
    private function exportQuotas(array $counts, int $limit): array
    {
        $ordered = $counts;
        asort($ordered, SORT_NUMERIC);
        $remaining = $limit;
        $quota = array_fill_keys(array_keys($counts), 0);
        $totalTypes = count($ordered);
        $index = 0;

        foreach ($ordered as $type => $count) {
            $leftTypes = $totalTypes - $index;
            $fairShare = $leftTypes > 0 ? intdiv($remaining, $leftTypes) : 0;
            $allocate = min((int) $count, $fairShare);
            $quota[$type] = $allocate;
            $remaining -= $allocate;
            $index++;
        }
        return $quota;
    }

    /** @return array<string, string> Individually selectable database log sources. */
    private function types(): array
    {
        return [
            'servo_monitor' => 'Servo Monitor (posisi / pulse)',
            'servo_torque' => 'Servo Monitor - Torq',
            'control_power' => 'Control Power Time',
            'alarm_event' => 'Alarm events',
            'alarm_snapshot' => 'Alarm readings',
            'connection' => 'Connection events',
            'operating' => 'Operating Time (semua counter)',
            'health' => 'Equipment health',
            'maintenance' => 'Maintenance journal',
            'recommendation' => 'Maintenance recommendation',
        ];
    }

    private function filters(Request $request): array
    {
        $acceptedTypes = array_merge(array_keys(self::SOURCES), array_keys(self::TYPE_ALIASES));
        $v = $request->validate([
            // robot_ids[] is the new checkbox multi-select input.
            // robot_id is accepted for old links from the individual robot page.
            'robot_ids' => ['sometimes', 'array', 'max:500'],
            'robot_ids.*' => ['required', 'integer', 'distinct', Rule::exists('robots', 'id')],
            'robot_id' => ['nullable', 'integer', Rule::exists('robots', 'id')],
            // types[] is a multi-select. Empty/absent means all sources.
            // Legacy ?type=... links continue to work when types[] is absent.
            'types' => ['sometimes', 'array', 'max:'.count($acceptedTypes)],
            'types.*' => ['required', 'string', 'distinct', Rule::in($acceptedTypes)],
            'type' => ['nullable', 'string', Rule::in(array_merge(['all', 'events'], $acceptedTypes))],
            'preset' => ['nullable', Rule::in(['shift_pagi', 'shift_2', 'full_day', 'custom'])],
            'date' => ['nullable', 'date_format:Y-m-d'],
            'from' => ['nullable', 'date_format:Y-m-d\\TH:i'],
            'to' => ['nullable', 'date_format:Y-m-d\\TH:i'],
            'per_page' => ['nullable', 'integer', Rule::in(self::PAGE_SIZES)],
        ]);

        $preset = $v['preset'] ?? 'shift_pagi';
        $day = $v['date'] ?? CarbonImmutable::now(self::TZ)->format('Y-m-d');

        if ($preset === 'custom') {
            if (empty($v['from']) || empty($v['to'])) {
                throw ValidationException::withMessages([
                    'from' => 'Jam mulai dan jam selesai wajib diisi untuk rentang manual.',
                ]);
            }
            $fromLocal = CarbonImmutable::createFromFormat('!Y-m-d\\TH:i', $v['from'], self::TZ);
            $toLocal = CarbonImmutable::createFromFormat('!Y-m-d\\TH:i', $v['to'], self::TZ);
        } else {
            [$fromLocal, $toLocal] = match ($preset) {
                'shift_pagi' => [
                    CarbonImmutable::parse($day . ' 07:15:00', self::TZ),
                    CarbonImmutable::parse($day . ' 16:00:00', self::TZ),
                ],
                // Shift 2 starts on the selected date and ends the following morning.
                'shift_2' => [
                    CarbonImmutable::parse($day . ' 21:00:00', self::TZ),
                    CarbonImmutable::parse($day . ' 05:45:00', self::TZ)->addDay(),
                ],
                default => [
                    CarbonImmutable::parse($day . ' 00:00:00', self::TZ),
                    CarbonImmutable::parse($day . ' 23:59:00', self::TZ),
                ],
            };
        }

        if ($fromLocal === false || $toLocal === false || $fromLocal->greaterThan($toLocal)) {
            throw ValidationException::withMessages(['to' => 'Jam selesai harus sama atau setelah jam mulai.']);
        }
        if (($toLocal->getTimestamp() - $fromLocal->getTimestamp()) > 7 * 86400) {
            throw ValidationException::withMessages(['to' => 'Rentang maksimal 7 hari per pencarian.']);
        }

        // Empty array = all robots (no WHERE IN restriction).
        // Preserve old ?robot_id=123 deep links when robot_ids[] is absent.
        $selectedIds = array_key_exists('robot_ids', $v)
            ? array_map('intval', $v['robot_ids'])
            : (isset($v['robot_id']) ? [(int) $v['robot_id']] : []);
        $selectedIds = array_values(array_unique($selectedIds));
        sort($selectedIds, SORT_NUMERIC);

        // Explicit multi-selection wins over legacy type= links.
        // No selection means all sources, consistent with the robot filter.
        if (array_key_exists('types', $v)) {
            $selectedTypes = $v['types'];
        } else {
            $selectedTypes = match ($v['type'] ?? 'all') {
                'all' => [],
                'events' => self::IMPORTANT_EVENTS,
                default => [$v['type']],
            };
        }

        // Deterministic order for URLs, selected chips, and CSV links.
        $selectedTypes = $this->normalizeTypes($selectedTypes);

        return [
            'robot_ids' => $selectedIds,
            'types' => $selectedTypes,
            'preset' => $preset,
            'date' => $day,
            'from' => $fromLocal->format('Y-m-d\\TH:i'),
            'to' => $toLocal->format('Y-m-d\\TH:i'),
            // End time includes the whole selected minute (e.g. up to 16:00:59.999).
            'from_utc' => $fromLocal->utc()->toIso8601String(),
            'until_utc' => $toLocal->addMinute()->utc()->toIso8601String(),
            'per_page' => (int) ($v['per_page'] ?? 50),
        ];
    }

    /** Canonical IDs also prevent duplicate branches from legacy/new selections. */
    private function normalizeTypes(array $types): array
    {
        $expanded = [];
        foreach ($types as $type) {
            foreach (self::TYPE_ALIASES[$type] ?? [$type] as $canonical) {
                $expanded[] = $canonical;
            }
        }

        return array_values(array_intersect(array_keys(self::SOURCES), $expanded));
    }

    private function queryParams(array $f): array
    {
        return array_filter([
            'robot_ids' => $f['robot_ids'] !== [] ? $f['robot_ids'] : null,
            'types' => $f['types'] !== [] ? $f['types'] : null,
            'preset' => $f['preset'],
            'date' => $f['date'],
            'from' => $f['preset'] === 'custom' ? $f['from'] : null,
            'to' => $f['preset'] === 'custom' ? $f['to'] : null,
            'per_page' => $f['per_page'],
        ], static fn ($value) => $value !== null && $value !== '');
    }

    /**
     * Native SQL Server migrations and PostgreSQL imports use different names
     * for the same computed maintenance timestamp. Prefer the indexed column
     * when available, otherwise retain the original COALESCE expression.
     */
    private function maintenanceTimestamp(): string
    {
        if ($this->maintenanceTimestampExpression !== null) {
            return $this->maintenanceTimestampExpression;
        }

        foreach (['rms_log_at', 'rms_log_time_sort'] as $column) {
            if (Schema::hasColumn('maintenance_logs', $column)) {
                return $this->maintenanceTimestampExpression = $column;
            }
        }

        return $this->maintenanceTimestampExpression = self::SOURCES['maintenance'][2];
    }

    private function query(array $f): Builder
    {
        $selected = $this->normalizeTypes($f['types']);
        $selected = $selected === [] ? array_keys(self::SOURCES) : $selected;

        $combined = null;
        foreach ($selected as $type) {
            [, $table, $timestamp] = self::SOURCES[$type];
            if ($type === 'maintenance' && DB::connection()->getDriverName() === 'sqlsrv') {
                $timestamp = $this->maintenanceTimestamp();
            }
            $utcTimestamp = RmsDatabase::utcTimestamp($timestamp);
            $part = DB::table($table)
                ->selectRaw("id AS record_id, robot_id, $utcTimestamp AS logged_at_utc, CAST(? AS varchar(32)) AS log_type", [$type])
                ->whereRaw("$timestamp >= ? AND $timestamp < ?", [$f['from_utc'], $f['until_utc']]);
            if ($type === 'control_power') {
                $part->where('time_type', 'ControllerOnTime');
            } elseif ($type === 'operating' && ($f['separate_control_power'] ?? in_array('control_power', $selected, true))) {
                // A controller-power sample appears once when both categories are selected.
                // Operating Time alone retains the legacy all-counter behavior.
                $part->where('time_type', '<>', 'ControllerOnTime');
            }
            if ($f['robot_ids'] !== []) {
                $part->whereIntegerInRaw('robot_id', $f['robot_ids']);
            }
            if ($combined === null) {
                $combined = $part;
            } else {
                $combined->unionAll($part);
            }
        }

        return DB::query()->fromSub($combined, 'rms_log_index')
            ->orderByDesc('logged_at_utc')
            ->orderByDesc('log_type')
            ->orderByDesc('record_id');
    }

    /** @return array<int, array<string, mixed>> */
    private function hydrateRows(Collection $index): array
    {
        if ($index->isEmpty()) {
            return [];
        }
        $robots = Robot::query()
            ->whereIn('id', $index->pluck('robot_id')->unique()->all())
            ->get(['id', 'robot_code', 'name'])->keyBy('id');

        // Position and torque share physical telemetry rows. Load each model once
        // per page/batch and present the requested readings without copying DB data.
        $groups = [];
        foreach (self::SOURCES as $type => [$model]) {
            $ids = $index->where('log_type', $type)->pluck('record_id')->all();
            if ($ids === []) {
                continue;
            }
            $groups[$model] ??= ['types' => [], 'ids' => [], 'columns' => []];
            $groups[$model]['types'][] = $type;
            $groups[$model]['ids'] = array_merge($groups[$model]['ids'], $ids);
            $groups[$model]['columns'] = array_merge($groups[$model]['columns'], self::DETAIL_COLUMNS[$type]);
        }
        $records = [];
        foreach ($groups as $model => $group) {
            $rows = $model::query()->whereIn('id', array_values(array_unique($group['ids'])))
                ->get(array_values(array_unique($group['columns'])))->keyBy('id');
            foreach ($group['types'] as $type) {
                $records[$type] = $rows;
            }
        }

        $entries = [];
        foreach ($index as $row) {
            $model = $records[$row->log_type][(int) $row->record_id] ?? null;
            if ($model === null) {
                continue;
            }
            $robot = $robots[(int) $row->robot_id] ?? null;
            [$status, $tone, $summary, $details] = $this->describe($row->log_type, $model);
            $localTime = CarbonImmutable::parse((string) $row->logged_at_utc, 'UTC')->setTimezone(self::TZ);
            $entries[] = [
                'key' => $row->log_type . '-' . $row->record_id,
                'record_id' => (int) $row->record_id,
                'sort_utc' => (string) $row->logged_at_utc,
                'robot_id' => (int) $row->robot_id,
                'robot_code' => $robot?->robot_code ?? ('ID ' . $row->robot_id),
                'robot_name' => $robot?->name ?? 'Robot tidak ditemukan',
                'time_wib' => $localTime->format('d/m/Y H:i:s'),
                'type' => $row->log_type,
                'type_label' => $this->types()[$row->log_type] ?? $row->log_type,
                'status' => $status,
                'tone' => $tone,
                'summary' => $summary,
                'details' => $details,
            ];
        }

        return $entries;
    }

    /** @return array{string, string, string, array<string, mixed>} */
    private function describe(string $type, object $r): array
    {
        return match ($type) {
            'servo_monitor', 'servo_torque' => $this->describeServo($type, $r),
            'control_power', 'operating' => $this->describeOperating($r),
            'alarm_snapshot' => [
                $r->read_ok ? 'READ OK' : 'READ FAILED',
                $r->read_ok ? 'ok' : 'warning',
                $r->read_ok ? 'Snapshot active alarm (bukan jaminan tidak ada alarm)' : 'Gagal membaca active alarms',
                ['sdk_text' => $r->sdk_text, 'parsed_alarms' => $r->parsed_alarms, 'read_ok' => $r->read_ok, 'active_alarm_count' => $r->active_alarm_count, 'status_code' => $r->status_code, 'message' => $r->status_message],
            ],
            'alarm_event' => [
                strtoupper((string) $r->status),
                $r->status === 'active' ? 'danger' : 'neutral',
                ($r->alarm_code ?: 'Alarm') . ' — ' . ($r->alarm_name ?: 'Alarm event'),
                ['event_key' => $r->event_key, 'alarm_code' => $r->alarm_code, 'alarm_name' => $r->alarm_name, 'severity' => $r->severity, 'subcode' => $r->subcode, 'source' => $r->source, 'last_seen_at' => $r->last_seen_at?->toIso8601String(), 'cleared_at' => $r->cleared_at?->toIso8601String(), 'details' => $r->details],
            ],
            'connection' => [
                strtoupper((string) $r->state),
                $r->state === 'connected' ? 'ok' : 'warning',
                $r->message ?: 'Perubahan status koneksi controller',
                ['state' => $r->state, 'ip_address' => $r->ip_address],
            ],
            'health' => [
                strtoupper((string) $r->health_status),
                $r->health_status === 'healthy' ? 'ok' : (in_array($r->health_status, ['critical', 'offline'], true) ? 'danger' : 'warning'),
                $r->summary ?: 'Equipment health assessment',
                ['rule_version' => $r->rule_version, 'assessment_source' => $r->assessment_source, 'indicators' => $r->indicators],
            ],
            'maintenance' => [
                strtoupper((string) $r->result),
                $r->result === 'completed' ? 'ok' : 'neutral',
                'Maintenance ' . $r->action_type . ' oleh ' . $r->performed_by,
                ['findings' => $r->findings, 'action_taken' => $r->action_taken,
                    'started_at' => $r->started_at?->toIso8601String(),
                    'completed_at' => $r->completed_at?->toIso8601String(),
                    'verified_by' => $r->verified_by, 'verified_at' => $r->verified_at?->toIso8601String()],
            ],
            'recommendation' => [
                strtoupper((string) $r->status),
                $r->status === 'open' ? 'warning' : 'neutral',
                $r->title . ' (' . $r->priority . ')',
                ['recommendation' => $r->recommendation_text, 'priority' => $r->priority, 'source' => $r->source, 'evidence' => $r->evidence],
            ],
            default => ['UNKNOWN', 'neutral', 'Unknown log type', []],
        };
    }

    /** Historical readings remain visible even when the current robot is stale. */
    private function describeServo(string $type, object $r): array
    {
        $position = $type === 'servo_monitor';
        $ok = (bool) ($position ? $r->position_ok : $r->torque_ok);
        $axes = $position ? $r->position_pulses : $r->torque_axes;
        $label = $position ? 'Servo Monitor' : 'Servo Monitor - Torq';
        $details = $position ? [
            'sequence' => $r->sequence,
            'position_ok' => $ok,
            'position_status_code' => $r->position_status_code,
            'position_status_message' => $r->position_status_message,
            'position_sampled_at' => $r->position_sampled_at?->toIso8601String(),
            'position_pulses' => $ok && is_array($axes) ? $axes : null,
            'position_unit' => 'pulse',
        ] : [
            'sequence' => $r->sequence,
            'torque_ok' => $ok,
            'torque_status_code' => $r->torque_status_code,
            'torque_status_message' => $r->torque_status_message,
            'torque_sampled_at' => $r->torque_sampled_at?->toIso8601String(),
            'torque_unit' => $r->torque_unit,
            'torque_axes' => $ok && is_array($axes) ? $axes : null,
        ];
        $values = [];
        foreach (['S', 'L', 'U', 'R', 'B', 'T'] as $axis) {
            $value = $ok && is_array($axes) ? ($axes[$axis] ?? null) : null;
            $values[] = $axis.' '.(is_numeric($value) && is_finite((float) $value)
                ? number_format((float) $value, $position ? 0 : 4, ',', '.') : '—');
        }

        return [$ok ? 'READ OK' : 'READ FAILED', $ok ? 'ok' : 'warning',
            $label.' · '.($ok ? implode(' · ', $values) : 'Pembacaan gagal / tidak tersedia'), $details];
    }

    private function describeOperating(object $r): array
    {
        $label = match ($r->time_type) {
            'ControllerOnTime' => 'Control Power Time',
            'ServoPowerOnTimeRobot' => 'Servo Operating Hours',
            'PlayBackTimeRobot' => 'Playback Time R1',
            'MovingTimeRobot' => 'Moving Time R1',
            default => (string) $r->time_type,
        };
        $seconds = $r->read_ok && is_numeric($r->value_seconds)
            && is_finite((float) $r->value_seconds) && (float) $r->value_seconds >= 0
            ? $r->value_seconds : null;
        $value = ! $r->read_ok ? 'Pembacaan gagal'
            : ($seconds !== null ? number_format((float) $seconds / 3600, 2, ',', '.').' h'
                : ($r->value_raw ?? 'Durasi belum tersedia'));

        return [$r->read_ok ? 'READ OK' : 'READ FAILED', $r->read_ok ? 'ok' : 'warning',
            $label.': '.$value, [
                'time_type' => $r->time_type, 'counter_label' => $label,
                'read_ok' => (bool) $r->read_ok, 'raw_value' => $r->value_raw,
                'value_seconds' => $seconds,
                'hours' => $seconds === null ? null : (float) $seconds / 3600,
                'status_code' => $r->status_code, 'message' => $r->status_message,
            ]];
    }

    private function safeCsv(string $text): string
    {
        // Prevent spreadsheet formula injection from robot or operator-supplied text.
        return preg_match('/^[\s\x00-\x1F]*[=+\-@]/u', $text) ? "'" . $text : $text;
    }
}



