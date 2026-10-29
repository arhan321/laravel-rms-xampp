<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Models\Robot;
use App\Models\RobotReducerMeasurement;
use App\Models\RobotThresholdRule;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Trusted-LAN configuration screens for evidence-backed RMS rules.
 *
 * This never sends a command to YMConnect or to the robot. It only stores
 * threshold/evidence records used by the read-only cockpit. The form keeps
 * the same approval requirements as the Artisan commands.
 */
final class RmsConfigurationController extends BaseRmsWebController
{
    private const AXES = ['S', 'L', 'U', 'R', 'B', 'T'];

    public function edit(Robot $robot): View
    {
        $robot->load('controller');

        $rules = RobotThresholdRule::query()
            ->where('robot_id', $robot->id)
            ->where('metric', 'torque_magnitude')
            ->orderBy('axis')
            ->get()
            ->keyBy('axis');

        $reducerMeasurements = RobotReducerMeasurement::query()
            ->where('robot_id', $robot->id)
            ->orderByDesc('measured_at')
            ->limit(60)
            ->get();

        return view('rms.robots.configuration', [
            'title' => 'Konfigurasi — '.$robot->name,
            'robot' => $robot,
            'axes' => self::AXES,
            'rules' => $rules,
            'reducerMeasurements' => $reducerMeasurements,
        ]);
    }

    public function saveThreshold(Request $request, Robot $robot): RedirectResponse
    {
        $data = $request->validate([
            'axis' => ['required', Rule::in(self::AXES)],
            'warning_magnitude' => ['required', 'numeric', 'gt:0', 'max:999999999999'],
            'critical_magnitude' => ['required', 'numeric', 'gt:warning_magnitude', 'max:999999999999'],
            'clear_hysteresis_percent' => ['required', 'numeric', 'min:0', 'max:30'],
            'source_reference' => ['nullable', 'string', 'max:250'],
            'approved_by' => ['nullable', 'string', 'max:120'],
            'enabled' => ['sometimes', 'boolean'],
            'confirm_unit' => ['sometimes', 'accepted'],
        ]);

        $enabled = $request->boolean('enabled');
        if ($enabled && (
            !$request->boolean('confirm_unit')
            || blank($data['source_reference'] ?? null)
            || blank($data['approved_by'] ?? null)
        )) {
            throw ValidationException::withMessages([
                'approval' => 'Untuk mengaktifkan batas pemantauan, centang konfirmasi pencocokan nilai lalu isi sumber penetapan batas dan nama pemeriksa.',
            ]);
        }

        $rule = RobotThresholdRule::query()->firstOrNew([
            'robot_id' => $robot->id,
            'axis' => strtoupper((string) $data['axis']),
            'metric' => 'torque_magnitude',
        ]);

        if ($rule->exists && $rule->events()->where('status', 'open')->exists()) {
            throw ValidationException::withMessages([
                'axis' => 'Axis ini masih memiliki event batas berstatus aktif. Tinjau atau tutup event tersebut sebelum mengubah batas.',
            ]);
        }

        $rule->fill([
            'unit' => 'sdk_raw',
            'warning_magnitude' => $data['warning_magnitude'],
            'critical_magnitude' => $data['critical_magnitude'],
            'clear_hysteresis_percent' => $data['clear_hysteresis_percent'],
            'enabled' => $enabled,
            'unit_verified' => $enabled && $request->boolean('confirm_unit'),
            'source_reference' => $enabled ? trim((string) $data['source_reference']) : null,
            'approved_by' => $enabled ? trim((string) $data['approved_by']) : null,
            'approved_at' => $enabled ? now() : null,
            'last_evaluated_sample_id' => null,
        ]);
        $rule->save();

        Log::info('rms.threshold.web_configured', [
            'robot_id' => $robot->id,
            'axis' => $rule->axis,
            'enabled' => $enabled,
            'source' => 'trusted_lan_web_form',
        ]);

        return redirect(route('rms.robots.configuration', $robot).'#threshold-config')
            ->with('config_success', "Batas pemantauan axis {$rule->axis} berhasil disimpan.");
    }

    public function storeReducerMeasurement(Request $request, Robot $robot): RedirectResponse
    {
        $data = $request->validate([
            'axis' => ['required', Rule::in(self::AXES)],
            'measured_at' => ['nullable', 'date_format:Y-m-d\\TH:i'],
            'condition_value' => ['nullable', 'numeric', 'max:999999999999'],
            'condition_unit' => ['nullable', 'string', 'max:50'],
            'remaining_life_hours' => ['nullable', 'numeric', 'min:0', 'max:999999999999'],
            'lower_threshold' => ['nullable', 'numeric', 'max:999999999999'],
            'upper_threshold' => ['nullable', 'numeric', 'max:999999999999'],
            'source_type' => ['required', Rule::in(['oem_cockpit', 'oem_report', 'technician_measurement'])],
            'source_reference' => ['required', 'string', 'max:255'],
            'verified_by' => ['required', 'string', 'max:120'],
            'verified' => ['accepted'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $conditionValue = $data['condition_value'] ?? null;
        $remainingLife = $data['remaining_life_hours'] ?? null;
        if ($conditionValue === null && $remainingLife === null) {
            throw ValidationException::withMessages([
                'condition_value' => 'Isi nilai hasil pemeriksaan atau sisa umur yang tercantum pada sumber terverifikasi.',
            ]);
        }
        if ($conditionValue !== null && blank($data['condition_unit'] ?? null)) {
            throw ValidationException::withMessages([
                'condition_unit' => 'Satuan wajib diisi jika nilai hasil pemeriksaan diisi.',
            ]);
        }
        if (($data['lower_threshold'] ?? null) !== null
            && ($data['upper_threshold'] ?? null) !== null
            && (float) $data['lower_threshold'] >= (float) $data['upper_threshold']) {
            throw ValidationException::withMessages([
                'upper_threshold' => 'Batas atas harus lebih besar daripada batas bawah.',
            ]);
        }

        $measuredAt = now();
        if (filled($data['measured_at'] ?? null)) {
            try {
                // The operator enters the form in WIB; PostgreSQL stores the instant.
                $measuredAt = CarbonImmutable::createFromFormat(
                    'Y-m-d\\TH:i',
                    (string) $data['measured_at'],
                    'Asia/Jakarta'
                )->utc();
            } catch (\Throwable) {
                throw ValidationException::withMessages([
                    'measured_at' => 'Waktu pengukuran tidak valid.',
                ]);
            }
        }

        RobotReducerMeasurement::query()->create([
            'robot_id' => $robot->id,
            'axis' => strtoupper((string) $data['axis']),
            'measured_at' => $measuredAt,
            'condition_value' => $conditionValue,
            'condition_unit' => $data['condition_unit'] ?? null,
            'remaining_life_hours' => $remainingLife,
            'lower_threshold' => $data['lower_threshold'] ?? null,
            'upper_threshold' => $data['upper_threshold'] ?? null,
            'source_type' => $data['source_type'],
            'source_reference' => trim((string) $data['source_reference']),
            'is_verified' => true,
            'verified_by' => trim((string) $data['verified_by']),
            'notes' => $data['notes'] ?? null,
        ]);

        Log::info('rms.reducer.web_recorded', [
            'robot_id' => $robot->id,
            'axis' => $data['axis'],
            'source_type' => $data['source_type'],
            'source' => 'trusted_lan_web_form',
        ]);

        return redirect(route('rms.robots.configuration', $robot).'#reducer-config')
            ->with('config_success', 'Data pemeriksaan speed reducer berhasil disimpan sebagai data terverifikasi internal.');
    }
}
