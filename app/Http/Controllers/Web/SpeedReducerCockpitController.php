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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

final class SpeedReducerCockpitController extends Controller
{
    private const MAX_CSV_BYTES = 500_000;

    public function show(Request $request, Robot $robot, SpeedReducerTelemetryTrend $trend): JsonResponse
    {
        $filter = $request->validate(['group' => ['nullable', 'regex:'.SpeedReducerOemImport::GROUP_PATTERN]]);
        $rows = DB::table('robot_speed_reducer_readings')->where('robot_id', $robot->getKey());
        $hasOemTable = Schema::hasTable('robot_speed_reducer_readings');
        $groups = $hasOemTable ? (clone $rows)->distinct()->pluck('control_group') : collect();
        $preferred = strtoupper((string) $robot->control_group);
        $telemetryGroup = SpeedReducerTelemetryTrend::GROUP;
        $groups = $groups->push($telemetryGroup)->unique()->sort()->values();
        $group = $filter['group'] ?? ($groups->contains($preferred) ? $preferred : $telemetryGroup);

        $history = $hasOemTable ? $rows->where('control_group', $group)
            ->select([
                'axis', 'date_wib', 'measurement_pct', 'variation_pp', 'operating_hours',
                'remaining_life_hours', 'variation_lower_pp', 'variation_upper_pp',
                'life_lower_hours', 'life_upper_hours', 'source_reference',
            ])
            ->orderByDesc('date_wib')->orderBy('axis')->limit(6000)
            ->get()->reverse()->values() : collect();

        // Inspection evidence is recorded for the robot's registered group.
        $inspectionLife = $group === $preferred || ($preferred === '' && $group === $telemetryGroup)
            ? RobotReducerMeasurement::query()
                ->where('robot_id', $robot->getKey())
                ->where('is_verified', true)
                ->whereIn('axis', TorqueShiftSummary::AXES)
                ->where('remaining_life_hours', '>=', 0)
                ->where('measured_at', '<=', now()->utc())
                ->orderByDesc('measured_at')->orderByDesc('id')->limit(300)->get()
                ->reverse()->map(fn (RobotReducerMeasurement $row): array => [
                    'axis' => $row->axis,
                    'date_wib' => $row->measured_at->timezone('Asia/Jakarta')->toDateString(),
                    'remaining_life_hours' => $row->remaining_life_hours,
                    'source_reference' => $row->source_reference,
                ])->values()
            : collect();

        return response()->json([
            'groups' => $groups->push($group)->unique()->sort()->values(),
            'group' => $group,
            'rows' => $history,
            'telemetry_rows' => $group === $telemetryGroup ? $trend->forRobot($robot) : collect(),
            'inspection_life_rows' => $inspectionLife,
        ])->header('Cache-Control', 'no-store');
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
