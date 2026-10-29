<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Models\MaintenanceLog;
use App\Models\MaintenancePlan;
use App\Models\Robot;
use App\Models\RobotOperatingTimeSample;
use App\Services\Rms\MaintenanceCategories;
use Illuminate\Contracts\View\View;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Operator-friendly PM: NO login, NO approval, NO separate verification.
 * The server validates data and records work history; it NEVER commands a robot.
 * This public-web workflow MUST only be exposed on a trusted industrial network.
 */
class MaintenanceController extends BaseRmsWebController
{
    public function index(Request $request): View
    {
        // Original filters and pagination are preserved.
        $filter = $request->validate([
            'robot_id' => ['nullable', 'integer', 'exists:robots,id'],
            'active' => ['nullable', 'in:all,yes,no'],
        ]);
        $plansQuery = MaintenancePlan::query()->with('robot');
        $logsQuery = MaintenanceLog::query()->with(['robot', 'maintenancePlan']);
        if (!empty($filter['robot_id'])) {
            $plansQuery->where('robot_id', $filter['robot_id']);
            $logsQuery->where('robot_id', $filter['robot_id']);
        }
        if (($filter['active'] ?? 'all') === 'yes') {
            $plansQuery->where('is_active', true);
        } elseif (($filter['active'] ?? 'all') === 'no') {
            $plansQuery->where('is_active', false);
        }
        $plans = $plansQuery->orderBy('next_due_at')->orderBy('id')
            ->paginate(20, ['*'], 'plan_page')->withQueryString();
        $logs = $logsQuery->orderByDesc('created_at')
            ->paginate(20, ['*'], 'log_page')->withQueryString();

        $timeSamples = $this->latestPerRobot(
            RobotOperatingTimeSample::class,
            collect($plans->items())->pluck('robot_id')->unique()->values(),
            'sampled_at',
            ['time_type' => 'ServoPowerOnTimeRobot']
        );
        $dueStatuses = [];
        foreach ($plans->items() as $plan) {
            $time = $timeSamples->get($plan->robot_id);
            $verifiedHours = $time !== null && $time->read_ok && $time->value_seconds !== null
                ? (float) $time->value_seconds / 3600.0 : null;
            // IMPORTANT: evaluate only the selected basis. A historical due date
            // on an operating-hour plan must not make that plan overdue.
            $byHours = $plan->basis === 'operating_hours' && $plan->next_due_operating_hours !== null
                && $verifiedHours !== null ? $verifiedHours >= (float)$plan->next_due_operating_hours : null;
            $byDate = $plan->basis === 'calendar' && $plan->next_due_at !== null
                ? $plan->next_due_at->lessThanOrEqualTo(now()) : null;
            $dueStatuses[$plan->id] = [
                'due_by_hours' => $byHours,
                'due_by_date' => $byDate,
                'verified_operating_hours' => $verifiedHours,
                'sampled_at' => $time?->sampled_at,
                'hours_remaining' => $plan->basis === 'operating_hours' && $verifiedHours !== null
                    && $plan->next_due_operating_hours !== null
                    ? round((float)$plan->next_due_operating_hours - $verifiedHours, 2) : null,
                'due' => $plan->is_active && ($byHours === true || $byDate === true),
            ];
        }

        $categories = [];
        if (! empty($filter['robot_id'])) {
            $robotId = (int) $filter['robot_id'];
            $categoryPlans = MaintenancePlan::query()->where('robot_id', $robotId)
                ->where('is_active', true)->whereNotNull('component_category')->get();
            $latestServo = RobotOperatingTimeSample::query()->where('robot_id', $robotId)
                ->where('time_type', 'ServoPowerOnTimeRobot')
                ->orderByDesc('sampled_at')->orderByDesc('id')->first();
            $verifiedHours = $latestServo?->read_ok && $latestServo->value_seconds !== null
                ? $latestServo->value_seconds / 3600 : null;
            $hoursFresh = $latestServo?->sampled_at?->greaterThanOrEqualTo(now()->subMinutes(5))
                && $latestServo?->received_at?->greaterThanOrEqualTo(now()->subMinutes(5));
            foreach (MaintenanceCategories::LABELS as $key => $label) {
                $work = $categoryPlans->where('component_category', $key);
                $due = false;
                $unknown = false;
                $remaining = null;
                foreach ($work as $plan) {
                    if ($plan->basis === 'operating_hours') {
                        if ($verifiedHours === null || $plan->next_due_operating_hours === null) {
                            $unknown = true;
                        } else {
                            $hours = (float) $plan->next_due_operating_hours - $verifiedHours;
                            $remaining = $remaining === null ? $hours : min($remaining, $hours);
                            $due = $due || $hours <= 0;
                            $unknown = $unknown || ! $hoursFresh;
                        }
                    } elseif ($plan->next_due_at === null) {
                        $unknown = true;
                    } else {
                        $due = $due || $plan->next_due_at->lessThanOrEqualTo(now());
                    }
                }
                $categories[$key] = [
                    'label' => $label, 'plans' => $work->count(), 'hours_remaining' => $remaining,
                    'status' => $work->isEmpty() ? 'unconfigured' : ($due ? 'due' : ($unknown ? 'unknown' : 'scheduled')),
                ];
            }
        }

        return view('rms.maintenance.index', [
            'title' => 'Preventive Maintenance',
            'robots' => Robot::query()->orderBy('robot_code')->get(['id','robot_code','name']),
            'plans' => $plans,
            'logs' => $logs,
            'dueStatuses' => $dueStatuses,
            'componentCategories' => MaintenanceCategories::LABELS,
            'categoryOverview' => $categories,
            'filter' => $filter,
            'note' => 'Interval servis ditentukan SOP/manual yang berlaku, bukan rekomendasi otomatis OEM. Jam terbaru bisa berupa histori lama; jangan anggap LIVE tanpa timestamp segar.',
        ]);
    }

    /** Create a PM plan from the same page (no account/login required). */
    public function storePlan(Request $request): RedirectResponse
    {
        $this->ensureGuestWriteAllowed();
        $data = $this->validatePlan($request);
        $data['is_active'] = $request->boolean('is_active', true);
        $data['created_by'] = 'RMS web operator (tanpa login)';
        $data = $this->resolveInitialDue($data, null);
        $plan = MaintenancePlan::create($data);
        Log::info('rms.pm.plan_created', ['plan_id' => $plan->id, 'robot_id' => $plan->robot_id, 'source' => 'web_no_login']);
        return redirect()->route('rms.maintenance.index')
            ->with('pm_success', 'Jadwal PM berhasil ditambahkan.');
    }

    /** Adjust an existing plan; never erase previous maintenance logs. */
    public function updatePlan(Request $request, MaintenancePlan $maintenancePlan): RedirectResponse
    {
        $this->ensureGuestWriteAllowed();
        $data = $this->validatePlan($request, $maintenancePlan);
        $data['is_active'] = $request->boolean('is_active');
        $data = $this->resolveInitialDue($data, $maintenancePlan);
        $before = $maintenancePlan->only(['basis','interval_hours','interval_days',
            'next_due_operating_hours','next_due_at','is_active',
            'component_axis','component_category','grease_specification','reference_document']);
        $maintenancePlan->update($data);
        Log::info('rms.pm.plan_updated', [
            'plan_id' => $maintenancePlan->id, 'before' => $before,
            'after' => $maintenancePlan->fresh()->only(array_keys($before)),
            'source' => 'web_no_login',
        ]);
        return redirect()->route('rms.maintenance.index', ['robot_id' => $maintenancePlan->robot_id])
            ->with('pm_success', 'Jadwal PM berhasil diperbarui.');
    }

    /**
     * One-step finish: create journal + calculate next interval atomically.
     * No approve/verify step. No robot control command and no auto-healthy claim.
     */
    public function completeWork(Request $request, MaintenancePlan $maintenancePlan): RedirectResponse
    {
        $this->ensureGuestWriteAllowed();
        $data = $request->validate([
            'performed_by' => ['required', 'string', 'max:120'],
            'action_taken' => ['nullable', 'string', 'max:4000'],
            'findings' => ['nullable', 'string', 'max:4000'],
            'current_operating_hours' => ['nullable', 'numeric', 'min:0', 'max:999999999'],
        ]);
        DB::transaction(function () use ($maintenancePlan, $data): void {
            $plan = MaintenancePlan::query()->lockForUpdate()->findOrFail($maintenancePlan->id);
            if (!$plan->is_active) {
                throw ValidationException::withMessages(['work' => 'Jadwal nonaktif. Aktifkan terlebih dahulu.']);
            }
            // Guard against accidental double clicks / two immediate concurrent submits.
            if ($plan->last_completed_at && $plan->last_completed_at->greaterThan(now()->subSeconds(20))) {
                throw ValidationException::withMessages(['work' => 'Pekerjaan ini baru saja dicatat. Periksa jurnal sebelum mengulangi.']);
            }
            $completionHours = null;
            if ($plan->basis === 'operating_hours') {
                if (!array_key_exists('current_operating_hours', $data) || $data['current_operating_hours'] === null) {
                    throw ValidationException::withMessages([
                        'current_operating_hours' => 'Masukkan jam operasi yang dibaca saat pekerjaan selesai (misalnya dari pendant).',
                    ]);
                }
                $completionHours = (float)$data['current_operating_hours'];
            }
            $now = now();
            $log = MaintenanceLog::create([
                'robot_id' => $plan->robot_id,
                'maintenance_plan_id' => $plan->id,
                'action_type' => 'preventive',
                'result' => 'completed',
                'performed_by' => trim($data['performed_by']),
                'started_at' => null,
                'completed_at' => $now,
                'action_taken' => $data['action_taken'] ?? null,
                'findings' => $data['findings'] ?? null,
                'supporting_data' => [
                    'entry_source' => 'web_operator_no_login',
                    'completion_operating_hours' => $completionHours,
                    'completion_hours_source' => $completionHours !== null ? 'operator_entered' : null,
                    'previous_due_operating_hours' => $plan->next_due_operating_hours,
                    'previous_due_at' => $plan->next_due_at?->toIso8601String(),
                    'pm_basis' => $plan->basis,
                    'pm_interval_hours' => $plan->interval_hours,
                    'pm_interval_days' => $plan->interval_days,
                    'pm_title' => $plan->title,
                    'component_category' => $plan->component_category,
                ],
            ]);
            // Re-base the NEXT due from the actual reported completion point.
            $changes = ['last_completed_at' => $now];
            if ($plan->basis === 'operating_hours') {
                $changes['next_due_operating_hours'] = round($completionHours + (float)$plan->interval_hours, 2);
                $changes['next_due_at'] = null;
            } else {
                $changes['next_due_at'] = $now->copy()->addDays((int)$plan->interval_days);
                $changes['next_due_operating_hours'] = null;
            }
            $plan->update($changes);
        }, 3);
        Log::info('rms.pm.work_completed', [
            'plan_id' => $maintenancePlan->id, 'robot_id' => $maintenancePlan->robot_id,
            'source' => 'web_no_login',
        ]);
        return redirect()->route('rms.maintenance.index', ['robot_id' => $maintenancePlan->robot_id])
            ->with('pm_success', 'Pekerjaan selesai dicatat. Jadwal berikutnya otomatis diperbarui.');
    }

    private function ensureGuestWriteAllowed(): void
    {
        // Safe default: local development only. To activate in a trusted
        // production LAN, set RMS_PM_GUEST_WRITE_ENABLED=true explicitly.
        abort_unless((bool) config('rms_pm.guest_write_enabled', false), 403,
            'Pengubahan jadwal tanpa login belum diaktifkan di environment ini.');
    }

    private function validatePlan(Request $request, ?MaintenancePlan $existing = null): array
    {
        $data = $request->validate([
            'robot_id' => ['required', 'integer', 'exists:robots,id'],
            'title' => ['required', 'string', 'max:180'],
            'description' => ['nullable', 'string', 'max:4000'],
            'component_category' => ['nullable', Rule::in(array_keys(MaintenanceCategories::LABELS))],
            'basis' => ['required', Rule::in(['operating_hours', 'calendar'])],
            'interval_hours' => ['nullable', 'numeric', 'gt:0', 'max:99999999'],
            'interval_days' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'next_due_operating_hours' => ['nullable', 'numeric', 'min:0', 'max:999999999'],
            'next_due_at' => ['nullable', 'date'],
            'component_axis' => ['nullable', Rule::in(['S','L','U','R','B','T','ALL'])],
            'grease_specification' => ['nullable', 'string', 'max:250'],
            'reference_document' => ['nullable', 'string', 'max:250'],
            'work_instructions' => ['nullable', 'string', 'max:4000'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        if ($data['basis'] === 'operating_hours' && empty($data['interval_hours'])) {
            throw ValidationException::withMessages(['interval_hours' => 'Interval jam operasi harus lebih besar dari nol.']);
        }
        if ($data['basis'] === 'calendar' && empty($data['interval_days'])) {
            throw ValidationException::withMessages(['interval_days' => 'Interval hari harus lebih besar dari nol.']);
        }
        if (!empty($data['next_due_at']) && $data['basis'] === 'calendar') {
            // HTML datetime-local is entered in WIB; PostgreSQL expects an instant.
            try {
                $data['next_due_at'] = CarbonImmutable::createFromFormat(
                    'Y-m-d\TH:i', $data['next_due_at'], 'Asia/Jakarta'
                )->utc();
            } catch (\Throwable $e) {
                throw ValidationException::withMessages(['next_due_at' => 'Format waktu servis tidak valid.']);
            }
        }
        if ($existing && (int)$data['robot_id'] !== (int)$existing->robot_id) {
            throw ValidationException::withMessages(['robot_id' => 'Robot tidak dapat diganti pada jadwal lama. Buat jadwal baru untuk robot lain.']);
        }
        return $data;
    }

    private function resolveInitialDue(array $data, ?MaintenancePlan $existing): array
    {
        if ($data['basis'] === 'calendar') {
            $data['interval_hours'] = null;
            $data['next_due_operating_hours'] = null;
            if (empty($data['next_due_at'])) {
                if ($existing && $existing->basis === 'calendar' && $existing->next_due_at) {
                    $data['next_due_at'] = $existing->next_due_at;
                } else {
                    $data['next_due_at'] = now()->addDays((int)$data['interval_days']);
                }
            }
            return $data;
        }
        $data['interval_days'] = null;
        $data['next_due_at'] = null;
        if (!isset($data['next_due_operating_hours'])) {
            if ($existing && $existing->basis === 'operating_hours' && $existing->next_due_operating_hours !== null) {
                $data['next_due_operating_hours'] = $existing->next_due_operating_hours;
            } else {
                $time = RobotOperatingTimeSample::query()
                    ->where('robot_id', (int)$data['robot_id'])
                    ->where('time_type', 'ServoPowerOnTimeRobot')
                    ->where('read_ok', true)->whereNotNull('value_seconds')
                    ->latest('sampled_at')->first();
                if (!$time) {
                    throw ValidationException::withMessages([
                        'next_due_operating_hours' => 'Belum ada jam operasi terverifikasi. Isi target jam servis pertama secara manual.',
                    ]);
                }
                // Historical hour is only used as initial planning reference.
                $data['next_due_operating_hours'] = round((float)$time->value_seconds / 3600 + (float)$data['interval_hours'], 2);
            }
        }
        return $data;
    }
}
