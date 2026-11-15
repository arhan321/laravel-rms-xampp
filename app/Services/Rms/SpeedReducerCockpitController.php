<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Robot;
use App\Models\RobotReducerMeasurement;
use App\Services\Rms\SpeedReducerOemImport;
use App\Services\Rms\SpeedReducerTelemetryTrend;
use App\Services\Rms\TorqueShiftSummary;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Throwable;

final class SpeedReducerCockpitController extends Controller
{
    private const MAX_CSV_BYTES = 500_000;

    public function show(Request $request, Robot $robot, SpeedReducerTelemetryTrend $trend): JsonResponse
    {
        $filter = $request->validate([
            'group' => ['nullable', 'regex:'.SpeedReducerOemImport::GROUP_PATTERN],
            'source' => ['nullable', 'in:oem,telemetry'],
        ]);
        $preferred = strtoupper((string) $robot->control_group);
        $telemetryGroup = SpeedReducerTelemetryTrend::GROUP;
        $group = $filter['group'] ?? $telemetryGroup;
        $groups = collect([$telemetryGroup]);
        $history = collect();
        $oemUnavailable = false;
        try {
            if (Schema::hasTable('robot_speed_reducer_readings')) {
                $rows = DB::table('robot_speed_reducer_readings')->where('robot_id', $robot->getKey());
                $groups = (clone $rows)->distinct()->pluck('control_group')->push($telemetryGroup);
                $group = $filter['group'] ?? ($groups->contains($preferred) ? $preferred : $telemetryGroup);
                $history = $rows->where('control_group', $group)
                    ->select([
                        'axis', 'date_wib', 'measurement_pct', 'variation_pp', 'operating_hours',
                        'remaining_life_hours', 'variation_lower_pp', 'variation_upper_pp',
                        'life_lower_hours', 'life_upper_hours', 'source_reference',
                    ])
                    ->orderByDesc('date_wib')->orderBy('axis')->limit(6000)
                    ->get()->reverse()->values();
            }
        } catch (Throwable $exception) {
            $oemUnavailable = true;
            $this->reportReadFailure($exception, $robot, $group, 'oem');
        }

        $telemetry = collect();
        $telemetryUnavailable = false;
        if ($group === $telemetryGroup && ($filter['source'] ?? null) !== 'oem') {
            try {
                $telemetry = $trend->forRobot($robot);
            } catch (Throwable $exception) {
                $telemetryUnavailable = true;
                $this->reportReadFailure($exception, $robot, $group, 'telemetry');
            }
        }

        $inspectionLife = collect();
        $inspectionUnavailable = false;
        // Inspection evidence is recorded for the robot's registered group.
        if ($group === $preferred || ($preferred === '' && $group === $telemetryGroup)) {
            try {
                $inspectionLife = $this->inspectionLife($robot);
            } catch (Throwable $exception) {
                $inspectionUnavailable = true;
                $this->reportReadFailure($exception, $robot, $group, 'inspection');
            }
        }

        return response()->json([
            'groups' => $groups->push($group)->unique()->sort()->values(),
            'group' => $group,
            'rows' => $history,
            'oem_unavailable' => $oemUnavailable,
            'telemetry_rows' => $telemetry,
            'telemetry_unavailable' => $telemetryUnavailable,
            'inspection_life_rows' => $inspectionLife,
            'inspection_unavailable' => $inspectionUnavailable,
        ], 200, ['Cache-Control' => 'no-store'], JSON_INVALID_UTF8_SUBSTITUTE);
    }

    private function inspectionLife(Robot $robot): Collection
    {
        return RobotReducerMeasurement::query()
            ->where('robot_id', $robot->getKey())
            ->where('is_verified', true)
            ->whereIn('axis', TorqueShiftSummary::AXES)
            ->where('remaining_life_hours', '>=', 0)
            ->whereNotNull('measured_at')
            ->where('measured_at', '<=', now()->utc())
            ->orderByDesc('measured_at')->orderByDesc('id')->limit(300)->get()
            ->reverse()->map(fn (RobotReducerMeasurement $row): array => [
                'axis' => $row->axis,
                'date_wib' => $row->measured_at->timezone('Asia/Jakarta')->toDateString(),
                'remaining_life_hours' => $row->remaining_life_hours,
                'source_reference' => $row->source_reference,
            ])->values();
    }

    private function reportReadFailure(Throwable $exception, Robot $robot, string $group, string $source): void
    {
        Log::warning('rms.reducer.read_failed', [
            'robot_id' => $robot->getKey(),
            'group' => $group,
            'source' => $source,
            'exception' => $exception,
        ]);
    }

    public function store(Request $request, Robot $robot, SpeedReducerOemImport $import): RedirectResponse
    {
        try {
            $data = $request->validateWithBag('reducerOem', [
                'csv' => ['nullable', 'required_without:csv_file', 'string', 'max:500000'],
                // A CSV exported from Excel can also be detected as plain text.
                'csv_file' => ['nullable', 'file', 'extensions:csv', 'max:489'],
                'source_reference' => ['required', 'string', 'max:255'],
                'verified_by' => ['required', 'string', 'max:120'],
                'verified' => ['accepted'],
            ], [
                'csv.required_without' => 'Pilih file CSV atau tempel isi CSV.',
                'csv_file.uploaded' => 'File CSV gagal diunggah. Periksa ukuran file dan coba lagi.',
                'csv_file.extensions' => 'Pilih file dengan ekstensi .csv.',
                'csv_file.max' => 'Ukuran file CSV maksimal 500 KB.',
            ]);

            $csv = $data['csv'] ?? '';
            if (isset($data['csv_file'])) {
                $csv = file_get_contents($data['csv_file']->getPathname(), false, null, 0, self::MAX_CSV_BYTES + 1);
                if ($csv === false || strlen($csv) > self::MAX_CSV_BYTES || ! preg_match('//u', $csv) || str_contains($csv, "\0")) {
                    throw ValidationException::withMessages([
                        'csv_file' => 'Gunakan file CSV UTF-8 yang dapat dibaca dengan ukuran maksimal 500 KB.',
                    ]);
                }
            }

            if (! Schema::hasTable('robot_speed_reducer_readings')) {
                throw ValidationException::withMessages([
                    'csv' => 'Tabel hasil diagnosis belum tersedia. Jalankan migrasi RMS sebelum menyimpan CSV.',
                ]);
            }

            $result = $import->store((int) $robot->getKey(), $csv, $data['source_reference'], $data['verified_by']);
        } catch (ValidationException $exception) {
            throw $exception->errorBag('reducerOem')
                ->redirectTo(route('rms.robots.configuration', $robot).'#speed-reducer-oem');
        }

        $count = $result['count'];
        Log::info('rms.reducer.oem_imported', ['robot_id' => $robot->getKey(), 'rows' => $count]);

        return redirect()->to(route('rms.robots.cockpit', [
            'robot' => $robot,
            'reducer_source' => 'oem',
            'reducer_group' => $result['first_group'],
        ]).'#speed-reducer-diagnosis')
            ->with('reducer_oem_success', "{$count} baris hasil diagnosis Yaskawa tersimpan.");
    }
}
