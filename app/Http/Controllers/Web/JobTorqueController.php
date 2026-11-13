<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Models\Robot;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Http\JsonResponse;
use Illuminate\Contracts\View\View;
use App\Http\Controllers\Controller;
use Illuminate\Http\StreamedResponse;
use App\Services\Rms\JobTorqueSummary;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use App\Services\Rms\JobMonitorEvidenceSummary;

/** New, read-only Job → Torque monitor. Existing RMS controllers stay untouched. */
final class JobTorqueController extends Controller
{
    private const TZ = 'Asia/Jakarta';

    public function index(Request $request, Robot $robot, JobTorqueSummary $summary, JobMonitorEvidenceSummary $evidence): View
    {
        $this->ensureSchema();
        $filters = $this->filters($request);
        $report = $summary->forRobot($robot, $filters);
        $options = $summary->jobOptions($robot, $filters);
        $query = $request->query();

        return view('rms.robots.job-torque', [
            'title' => 'Job Monitoring & Servo Monitor - Torq',
            'robot' => $robot,
            'filters' => $filters,
            'report' => $report,
            'jobEvidence' => $evidence->forRobot($robot, $report),
            'jobOptions' => $options,
            'exportUrl' => route('rms.robots.job-torque.export', array_merge(['robot' => $robot->id], $query)),
        ]);
    }

    /**
     * Read-only, same-origin Job Torque reconciliation endpoint.
     * probe=1 avoids expensive historical AVG/MAX SQL queries on every fallback poll.
     * Full requests return server-rendered Blade markup; no telemetry is modified.
     */
    public function live(Request $request, Robot $robot, JobTorqueSummary $summary, JobMonitorEvidenceSummary $evidence): JsonResponse
    {
        $this->ensureSchema();
        $latest = $robot->telemetrySamples()
            ->orderByDesc('received_at')->orderByDesc('id')
            ->first(['id', 'sampled_at', 'received_at']);

        $now = now();
        $ttl = max(5, min(300, (int) config('rms.telemetry_stale_seconds', 30)));
        $sampleAge = $latest?->sampled_at ? $now->getTimestamp() - $latest->sampled_at->getTimestamp() : null;
        $receivedAge = $latest?->received_at ? $now->getTimestamp() - $latest->received_at->getTimestamp() : null;
        $fresh = $sampleAge !== null && $receivedAge !== null
            && $sampleAge >= -5 && $receivedAge >= -5
            && $sampleAge <= 60 && $receivedAge <= $ttl;
        $meta = [
            'robot_id' => (int) $robot->getKey(),
            'sample_id' => $latest?->id,
            'sampled_at' => $latest?->sampled_at?->toIso8601String(),
            'received_at' => $latest?->received_at?->toIso8601String(),
            'received_age_seconds' => $receivedAge === null ? null : max(0, $receivedAge),
            'telemetry_fresh' => $fresh,
            'server_time' => $now->toIso8601String(),
            'stale_seconds' => $ttl,
        ];

        if ($request->query('probe') === '1') {
            return response()->json($meta)->header('Cache-Control', 'private, no-store')
                ->header('X-Content-Type-Options', 'nosniff');
        }

        $filters = $this->filters($request);
        $report = $summary->forRobot($robot, $filters);
        $jobEvidence = $evidence->forRobot($robot, $report);
        $html = view('rms.robots.partials.job-torque-live-data', [
            'report' => $report, 'filters' => $filters, 'jobEvidence' => $jobEvidence,
        ])->render();

        return response()->json([
            ...$meta,
            'html' => $html,
            'job_options' => $summary->jobOptions($robot, $filters),
            'job_read_status' => $jobEvidence['status'] ?? 'no_data',
        ])->header('Cache-Control', 'private, no-store')
            ->header('X-Content-Type-Options', 'nosniff');
    }

    public function export(Request $request, Robot $robot, JobTorqueSummary $summary): StreamedResponse
    {
        $this->ensureSchema();
        $filters = $this->filters($request);
        $report = $summary->forRobot($robot, $filters);
        $filename = 'RMS_Job_Torque_Robot_'.$robot->id.'_'.now(self::TZ)->format('Ymd_His').'.csv';
        return response()->streamDownload(static function () use ($filters, $report, $robot): void {
            $stream = fopen('php://output', 'w');
            if ($stream === false) return;
            // BOM makes UTF-8 readable in Excel on local Windows workstations.
            fwrite($stream, "\xEF\xBB\xBF");
            fputcsv($stream, ['Robot', 'Job', 'Axis', 'Count valid axis', 'Sample Count Job',
                'AVG', 'MAX', 'Mode', 'Unit', 'First (WIB)', 'Last (WIB)',
                'Period Start (WIB)', 'Period End Exclusive (WIB)', 'Coverage (%)', 'Data Source / Caveat']);
            foreach (array_slice($report['groups'], 0, JobTorqueSummary::MAX_JOBS) as $group) {
                foreach (JobTorqueSummary::AXES as $axis) {
                    $data = $group['axes'][$axis];
                    fputcsv($stream, [
                        self::safeCell($robot->robot_code), self::safeCell($group['job']), $axis,
                        $data['count'], $group['sample_count'], $data['average'], $data['maximum'],
                        $filters['mode'], $report['unit'], $group['first_at'], $group['last_at'],
                        $filters['from_label'], $filters['until_label'], $report['coverage_percent'],
                        'Only directly job-tagged, non-simulated SDK raw torque samples; no job interpolation; unverified job name',
                    ]);
                }
            }
            fclose($stream);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store, private',
        ]);
    }

    private static function safeCell(string $value): string
    {
        // CSV/Excel formula injection protection for controller/job-provided text.
        return preg_match('/^[=+@\-\t\r]/', ltrim($value)) ? "'".$value : $value;
    }

    private function ensureSchema(): void
    {
        abort_unless(Schema::hasColumn('robot_telemetry_samples', 'job_name'), 503,
            'Kolom job_name belum tersedia. Jalankan php artisan migrate setelah backup SQL Server.');
    }

    /** @return array<string,mixed> */
    private function filters(Request $request): array
    {
        $v = $request->validate([
            'preset' => ['nullable', Rule::in(['shift_pagi', 'shift_2', 'full_day', 'custom'])],
            'date' => ['nullable', 'date_format:Y-m-d'],
            'from' => ['nullable', 'date_format:Y-m-d\TH:i'],
            'to' => ['nullable', 'date_format:Y-m-d\TH:i'],
            'job' => ['nullable', 'string', 'max:120'],
            'mode' => ['nullable', Rule::in(['absolute', 'signed'])],
        ]);
        $preset = $v['preset'] ?? 'shift_pagi';
        $day = $v['date'] ?? CarbonImmutable::now(self::TZ)->format('Y-m-d');
        if ($preset === 'custom') {
            if (empty($v['from']) || empty($v['to'])) {
                throw ValidationException::withMessages([
                    'from' => 'Isi waktu mulai dan selesai untuk rentang manual.',
                ]);
            }
            $fromLocal = CarbonImmutable::createFromFormat('!Y-m-d\TH:i', $v['from'], self::TZ);
            $toLocal = CarbonImmutable::createFromFormat('!Y-m-d\TH:i', $v['to'], self::TZ);
        } else {
            [$fromLocal, $toLocal] = match ($preset) {
                'shift_pagi' => [
                    CarbonImmutable::parse($day.' 07:15:00', self::TZ),
                    CarbonImmutable::parse($day.' 16:00:00', self::TZ),
                ],
                'shift_2' => [
                    CarbonImmutable::parse($day.' 21:00:00', self::TZ),
                    CarbonImmutable::parse($day.' 05:45:00', self::TZ)->addDay(),
                ],
                default => [
                    CarbonImmutable::parse($day.' 00:00:00', self::TZ),
                    CarbonImmutable::parse($day.' 23:59:00', self::TZ),
                ],
            };
        }
        if ($fromLocal === false || $toLocal === false || $fromLocal >= $toLocal) {
            throw ValidationException::withMessages(['to' => 'Rentang waktu harus memiliki jam akhir setelah jam awal.']);
        }
        if ($toLocal->getTimestamp() - $fromLocal->getTimestamp() > 7 * 86400) {
            throw ValidationException::withMessages(['to' => 'Maksimal periode analisis adalah tujuh hari.']);
        }
        // Inclusive last minute, same semantics as the existing Robot Logs.
        $untilExclusive = $toLocal->addMinute();
        $job = trim((string) ($v['job'] ?? ''));
        return [
            'preset' => $preset,
            'date' => $day,
            'from' => $v['from'] ?? $fromLocal->format('Y-m-d\TH:i'),
            'to' => $v['to'] ?? $toLocal->format('Y-m-d\TH:i'),
            'job' => $job === '' ? null : $job,
            'mode' => $v['mode'] ?? 'absolute',
            'from_utc' => $fromLocal->utc()->toIso8601String(),
            'until_utc' => $untilExclusive->utc()->toIso8601String(),
            'from_label' => $fromLocal->format('d M Y H:i'),
            'until_label' => $untilExclusive->format('d M Y H:i'),
        ];
    }
}
