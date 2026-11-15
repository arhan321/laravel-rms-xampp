<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\MaintenancePlan;
use App\Models\Robot;
use App\Models\RobotSteelDustInspection;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SteelDustController extends Controller
{
    private const TIMEZONE = 'Asia/Jakarta';

    public function index(Request $request): View
    {
        $today = CarbonImmutable::today(self::TIMEZONE);
        $filters = $request->validate([
            'robot_id' => ['nullable', 'integer', 'exists:robots,id'],
            'period_end' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:'.$today->toDateString()],
            'unit' => ['nullable', 'string', 'max:24'],
            'plan_id' => ['nullable', 'integer', 'min:1'],
        ]);
        $robots = Robot::query()->orderBy('robot_code')->get(['id', 'robot_code', 'name']);
        $robot = $robots->firstWhere('id', $filters['robot_id'] ?? $robots->first()?->id);
        $end = isset($filters['period_end']) ? CarbonImmutable::parse($filters['period_end'], self::TIMEZONE) : $today;
        $start = $end->subMonthsNoOverflow(2);
        $plans = collect();
        $inspections = collect();
        $selectedPlan = null;

        if ($robot) {
            $plans = MaintenancePlan::query()->where('robot_id', $robot->id)->orderBy('title')->get();
            $selectedPlan = $plans->firstWhere('id', $filters['plan_id'] ?? null);
            $inspections = RobotSteelDustInspection::query()
                ->where('robot_id', $robot->id)
                ->whereBetween('inspected_on', [$start->toDateString(), $end->toDateString()])
                ->when($selectedPlan, fn ($query) => $query->where('maintenance_plan_id', $selectedPlan->id))
                ->orderByDesc('inspected_on')->orderByDesc('id')
                ->get();
        }

        $units = $inspections->pluck('unit')->uniqueStrict()->sort()->values();
        $selectedUnit = $units->containsStrict($filters['unit'] ?? null) ? $filters['unit'] : $inspections->first()?->unit;
        $inspections = $inspections->filter(fn (RobotSteelDustInspection $inspection) => $inspection->unit === $selectedUnit);
        $points = $inspections->reverse()->map(fn (RobotSteelDustInspection $inspection) => [
            'date' => $inspection->inspected_on->toDateString(),
            'readings' => collect(RobotSteelDustInspection::AXES)->mapWithKeys(fn (string $axis) => [
                $axis => [
                    'value' => $inspection->readings[$axis]['value'] ?? null,
                    'warning' => $inspection->statusFor($axis) === 'Warning',
                ],
            ])->all(),
        ])->values();

        return view('rms.maintenance.steel-dust', [
            'title' => 'Steel Dust Monitoring',
            'robots' => $robots,
            'robot' => $robot,
            'today' => $today,
            'start' => $start,
            'end' => $end,
            'plans' => $plans,
            'selectedPlan' => $selectedPlan,
            'units' => $units,
            'selectedUnit' => $selectedUnit,
            'axes' => RobotSteelDustInspection::AXES,
            'inspections' => $inspections,
            'chartData' => [
                'start' => $start->toDateString(),
                'end' => $end->toDateString(),
                'unit' => $selectedUnit,
                'axes' => RobotSteelDustInspection::AXES,
                'points' => $points,
            ],
            'submissionId' => (string) Str::uuid(),
            'canWrite' => (bool) config('rms_pm.guest_write_enabled', false),
        ]);
    }

    public function store(Request $request, Robot $robot): RedirectResponse
    {
        abort_unless((bool) config('rms_pm.guest_write_enabled', false), 403,
            'Input Steel Dust tanpa login belum diaktifkan di environment ini.');

        $today = CarbonImmutable::today(self::TIMEZONE)->toDateString();
        $numberRules = ['nullable', 'regex:/\A[0-9]{1,8}(?:[.,][0-9]{1,6})?\z/'];
        $axes = RobotSteelDustInspection::AXES;
        $rules = [
            'submission_id' => ['required', 'uuid'],
            'inspected_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.$today],
            'unit' => ['required', 'string', 'max:24'],
            'inspected_by' => ['required', 'string', 'max:120'],
            'maintenance_plan_id' => ['nullable', 'integer', 'min:1'],
            'values' => ['required', 'array:'.implode(',', $axes)],
            'limits' => ['nullable', 'array:'.implode(',', $axes)],
            'limit_reference' => ['nullable', 'string', 'max:250'],
            'limit_approved_by' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
        $messages = [];
        foreach ($axes as $axis) {
            $rules['values.'.$axis] = $numberRules;
            $rules['limits.'.$axis] = $numberRules;
            $messages['values.'.$axis.'.regex'] = "Hasil Axis {$axis} harus berupa angka tanpa pemisah ribuan, maksimal 8 digit utuh dan 6 desimal.";
            $messages['limits.'.$axis.'.regex'] = "Batas Axis {$axis} harus berupa angka tanpa pemisah ribuan, maksimal 8 digit utuh dan 6 desimal.";
        }
        $data = $request->validate($rules, $messages);

        $readings = [];
        $hasLimits = false;
        foreach ($axes as $axis) {
            $value = $data['values'][$axis] ?? null;
            $limit = $data['limits'][$axis] ?? null;
            if ($value === null && $limit !== null) {
                throw ValidationException::withMessages(['limits.'.$axis => "Isi hasil Axis {$axis} untuk menggunakan batasnya."]);
            }
            if ($value !== null) {
                $readings[$axis] = [
                    'value' => str_replace(',', '.', (string) $value),
                    'limit' => $limit === null ? null : str_replace(',', '.', (string) $limit),
                ];
                $hasLimits = $hasLimits || $limit !== null;
            }
        }
        if ($readings === []) {
            throw ValidationException::withMessages(['values' => 'Isi hasil pengukuran minimal satu axis.']);
        }
        if ($hasLimits) {
            $request->validate([
                'limit_reference' => ['required', 'string', 'max:250'],
                'limit_approved_by' => ['required', 'string', 'max:120'],
            ], [
                'limit_reference.required' => 'Isi rujukan SOP atau laporan persetujuan batas.',
                'limit_approved_by.required' => 'Isi nama atau jabatan pihak yang menyetujui batas.',
            ]);
        }

        $inspection = DB::transaction(function () use ($robot, $data, $readings, $hasLimits): RobotSteelDustInspection {
            $lockedRobot = Robot::query()->lockForUpdate()->findOrFail($robot->id);
            $previous = RobotSteelDustInspection::query()
                ->where('robot_id', $lockedRobot->id)->where('submission_id', $data['submission_id'])->first();
            if ($previous) {
                return $previous;
            }

            $planId = $data['maintenance_plan_id'] ?? null;
            if ($planId !== null && ! MaintenancePlan::query()
                ->where('robot_id', $lockedRobot->id)->whereKey($planId)->exists()) {
                throw ValidationException::withMessages(['maintenance_plan_id' => 'Pilih jadwal PM milik robot ini.']);
            }

            return RobotSteelDustInspection::query()->create([
                'robot_id' => $lockedRobot->id,
                'maintenance_plan_id' => $planId,
                'submission_id' => $data['submission_id'],
                'inspected_on' => $data['inspected_on'],
                'unit' => $data['unit'],
                'readings' => $readings,
                'inspected_by' => $data['inspected_by'],
                'limit_reference' => $hasLimits ? $data['limit_reference'] : null,
                'limit_approved_by' => $hasLimits ? $data['limit_approved_by'] : null,
                'notes' => $data['notes'] ?? null,
            ]);
        }, 3);

        $inspectedOn = $inspection->inspected_on->toDateString();
        $earliest = CarbonImmutable::parse($today, self::TIMEZONE)->subMonthsNoOverflow(2)->toDateString();

        return redirect()->route('rms.maintenance.steel-dust.index', [
            'robot_id' => $robot->id,
            'period_end' => $inspectedOn < $earliest ? $inspectedOn : $today,
            'plan_id' => $inspection->maintenance_plan_id,
            'unit' => $inspection->unit,
        ])->with('steel_dust_success', 'Hasil pemeriksaan Steel Dust tersimpan dan tersedia di grafik.');
    }
}
