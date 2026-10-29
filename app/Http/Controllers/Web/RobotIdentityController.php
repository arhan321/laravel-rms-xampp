<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Events\RmsRobotChanged;
use App\Models\Robot;
use App\Models\RobotController as ControllerModel;
use App\Services\Rms\HomePositionMonitor;
use App\Services\Rms\RobotReachabilityProbe;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Edit RMS master data without changing robot_id or destroying telemetry/history.
 * Web write access is deliberately opt-in outside APP_ENV=local.
 * NEVER sends a robot command; reachability checks use an existing ICMP probe.
 */
final class RobotIdentityController extends BaseRmsWebController
{
    public function edit(Robot $robot): View
    {
        $robot->load('controller');

        return view('rms.robots.identity', [
            'title' => 'Edit Data Robot — '.$robot->name,
            'robot' => $robot,
            'axes' => HomePositionMonitor::AXES,
            'home' => data_get($robot->metadata, 'home_position', []),
            'probePorts' => config('rms.robot_probe.udp_ports', [10040, 10041]),
            'controllerShared' => $robot->controller?->robots()
                ->where('id', '!=', $robot->id)->exists() ?? false,
            'identityWriteAllowed' => $this->guestWriteAllowed(),
        ]);
    }

    public function update(Request $request, Robot $robot, RobotReachabilityProbe $probe): RedirectResponse
    {
        $this->ensureGuestWriteAllowed();
        $robot->load('controller');
        $controller = $robot->controller;
        abort_if($controller === null, 409, 'Robot tidak mempunyai controller yang terdaftar.');

        // Match the inputs of "Tambah Robot"; don't expose controller state or metadata.
        $data = $request->validate([
            'controller_code' => ['required', 'string', 'max:80', Rule::unique('robot_controllers', 'controller_code')->ignore($controller->id)],
            'controller_model' => ['required', 'string', 'max:80'],
            'ip_address' => ['required', 'ip', 'ipv4', Rule::unique('robot_controllers', 'ip_address')->ignore($controller->id)],
            'location' => ['nullable', 'string', 'max:150'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'robot_code' => ['required', 'string', 'max:80', Rule::unique('robots', 'robot_code')->ignore($robot->id)],
            'name' => ['required', 'string', 'max:150'],
            'model' => ['required', 'string', 'max:80'],
            'serial_number' => ['required', 'string', 'max:120', Rule::unique('robots', 'serial_number')->ignore($robot->id)],
            'robot_type' => ['required', 'string', 'max:120'],
            'nameplate_date' => ['required', 'date_format:Y-m'],
            'control_group' => ['required', 'string', 'max:8', 'regex:/^R[1-9][0-9]*$/i'],
            'axis_count' => ['required', 'integer', 'between:1,16'],
            'line_name' => ['nullable', 'string', 'max:100'],
            'station_name' => ['nullable', 'string', 'max:100'],
            'confirm_hardware_change' => ['sometimes', 'accepted'],
        ]);

        foreach (['controller_code', 'robot_code', 'control_group'] as $key) {
            $data[$key] = strtoupper(trim($data[$key]));
        }
        foreach (['controller_model', 'name', 'model', 'serial_number', 'robot_type'] as $key) {
            $data[$key] = trim($data[$key]);
        }
        foreach (['location', 'notes', 'line_name', 'station_name'] as $key) {
            $data[$key] = isset($data[$key]) && trim($data[$key]) !== '' ? trim($data[$key]) : null;
        }
        if (in_array('', array_map(fn (string $key): string => $data[$key],
            ['controller_code', 'controller_model', 'robot_code', 'name', 'model', 'serial_number', 'robot_type', 'control_group']), true)) {
            throw ValidationException::withMessages(['name' => 'Field wajib tidak boleh hanya berisi spasi.']);
        }

        // Ignore the current row but also protect case-insensitive robot/controller codes.
        if (ControllerModel::query()->where('id', '!=', $controller->id)
            ->whereRaw('LOWER(controller_code) = LOWER(?)', [$data['controller_code']])->exists()) {
            throw ValidationException::withMessages(['controller_code' => 'Controller code sudah terdaftar.']);
        }
        if (Robot::query()->where('id', '!=', $robot->id)
            ->whereRaw('LOWER(robot_code) = LOWER(?)', [$data['robot_code']])->exists()) {
            throw ValidationException::withMessages(['robot_code' => 'Robot code sudah terdaftar.']);
        }

        $controllerChanges = $this->controllerChanges($controller, $data);
        $networkChanged = $controller->ip_address !== $data['ip_address'];
        $groupChanged = strtoupper((string) $robot->control_group) !== $data['control_group'];
        $axisChanged = (int) $robot->axis_count !== (int) $data['axis_count'];
        $serialChanged = (string) $robot->serial_number !== $data['serial_number'];
        $hardwareChanged = $networkChanged || $groupChanged || $axisChanged;

        if ($hardwareChanged && ! $request->boolean('confirm_hardware_change')) {
            throw ValidationException::withMessages([
                'confirm_hardware_change' => 'Konfirmasi perubahan jaringan/control group/jumlah axis sebelum menyimpan.',
            ]);
        }
        if ($controllerChanges !== [] && $controller->robots()->where('id', '!=', $robot->id)->exists()) {
            throw ValidationException::withMessages([
                'controller_code' => 'Controller dipakai lebih dari satu robot. Data controller tidak boleh diedit melalui satu robot; ubah hanya data robot atau minta admin melakukan perubahan bersama.',
            ]);
        }

        // Changing the control group or axis definition of a historical robot
        // would mix measurements of different physical identities in one timeline.
        if (($groupChanged || $axisChanged) && $this->hasHistory($robot)) {
            throw ValidationException::withMessages([
                'control_group' => 'Robot sudah memiliki histori. Control group dan jumlah axis tidak dapat diubah melalui form ini agar histori tidak tertukar. Daftarkan identitas fisik baru melalui prosedur migrasi yang disetujui.',
            ]);
        }

        $sameGroup = Robot::query()->where('controller_id', $controller->id)
            ->where('control_group', $data['control_group'])->where('id', '!=', $robot->id)->exists();
        if ($sameGroup) {
            throw ValidationException::withMessages(['control_group' => 'Control group sudah dipakai robot lain pada controller ini.']);
        }

        // Only re-probe if the IP actually changed, just like the create form.
        // This checks ICMP reachability, not YMConnect compatibility or OEM identity.
        $probeResult = null;
        if ($networkChanged) {
            $probeResult = $probe->probe($data['ip_address']);
            if (! $probeResult['ok']) {
                throw ValidationException::withMessages(['ip_address' => $probeResult['message']]);
            }
        }

        DB::transaction(function () use ($robot, $controller, $data, $probeResult,
            $controllerChanges, $groupChanged, $axisChanged, $serialChanged): void {
            $lockedController = ControllerModel::query()->lockForUpdate()->findOrFail($controller->id);
            $lockedRobot = Robot::query()->lockForUpdate()->findOrFail($robot->id);

            // Prevent conflicting concurrent changes while the operator had the form open.
            if ($lockedRobot->updated_at?->ne($robot->updated_at) ||
                $lockedController->updated_at?->ne($controller->updated_at)) {
                throw ValidationException::withMessages(['name' => 'Data robot atau controller berubah dari sesi lain. Muat ulang form dan periksa kembali.']);
            }
            if ($controllerChanges !== [] && $lockedController->robots()->where('id', '!=', $lockedRobot->id)->exists()) {
                throw ValidationException::withMessages(['controller_code' => 'Controller saat ini digunakan robot lain.']);
            }
            if (Robot::query()->where('controller_id', $lockedController->id)
                ->where('control_group', $data['control_group'])->where('id', '!=', $lockedRobot->id)->exists()) {
                throw ValidationException::withMessages(['control_group' => 'Control group sudah digunakan oleh robot lain.']);
            }
            if (($groupChanged || $axisChanged) && $this->hasHistory($lockedRobot)) {
                throw ValidationException::withMessages(['control_group' => 'Terdapat histori baru; perubahan control group/jumlah axis dibatalkan.']);
            }

            if ($controllerChanges !== []) {
                $update = [
                    'controller_code' => $data['controller_code'],
                    'model' => $data['controller_model'],
                    'ip_address' => $data['ip_address'],
                    'location' => $data['location'],
                    'notes' => $data['notes'],
                ];
                if ($probeResult !== null) {
                    // Any previous "connected" flag belongs to the OLD IP.
                    $update += [
                        'connection_status' => 'unknown',
                        'last_seen_at' => null,
                        'network_verified_at' => now(),
                        'network_verification_status' => $probeResult['status'],
                        'network_verification_method' => $probeResult['method'],
                        'network_verification_message' => $probeResult['message'],
                        'network_verification_latency_ms' => $probeResult['latency_ms'],
                    ];
                }
                $lockedController->update($update);
            }

            $meta = $lockedRobot->metadata ?? [];
            if ($serialChanged || $groupChanged || $axisChanged) {
                // Existing home reference was approved for the prior physical identity.
                unset($meta['home_position']);
            }
            $lockedRobot->update([
                'robot_code' => $data['robot_code'],
                'name' => $data['name'],
                'model' => $data['model'],
                'serial_number' => $data['serial_number'],
                'robot_type' => $data['robot_type'],
                'nameplate_date' => $data['nameplate_date'],
                'control_group' => $data['control_group'],
                'axis_count' => (int) $data['axis_count'],
                'line_name' => $data['line_name'],
                'station_name' => $data['station_name'],
                'metadata' => $meta,
            ]);
        }, 3);

        $this->notify($robot, 'RobotIdentity');
        Log::info('rms.robot.identity_updated', [
            'robot_id' => $robot->id,
            'controller_id' => $controller->id,
            'controller_fields_changed' => array_keys($controllerChanges),
            'network_changed' => $networkChanged,
            'home_reference_reset' => $serialChanged || $groupChanged || $axisChanged,
        ]);

        $message = 'Data robot berhasil diperbarui tanpa menghapus histori telemetry, alarm, atau maintenance.';
        if ($serialChanged || $groupChanged || $axisChanged) {
            $message .= ' Referensi home position lama dibatalkan; lakukan verifikasi ulang.';
        }
        if ($networkChanged || $groupChanged) {
            $message .= ' Pastikan collector.conf/collector.dojo.conf diperbarui dan collector di-restart terkontrol sesuai prosedur teknisi.';
        }

        return redirect()->route('rms.robots.identity', $robot)
            ->with('identity_success', $message);
    }

    /** Compare normalized client fields with ONLY editable controller master data. */
    private function controllerChanges(ControllerModel $controller, array $data): array
    {
        $proposed = [
            'controller_code' => $data['controller_code'],
            'model' => $data['controller_model'],
            'ip_address' => $data['ip_address'],
            'location' => $data['location'],
            'notes' => $data['notes'],
        ];
        return array_filter($proposed, static fn ($value, $key) =>
            $value !== $controller->getAttribute($key), ARRAY_FILTER_USE_BOTH);
    }

    /** Physical identity fields cannot be reassigned after robot-scoped history exists. */
    private function hasHistory(Robot $robot): bool
    {
        foreach (['telemetrySamples', 'operatingTimeSamples', 'alarmSnapshots',
            'alarmEvents', 'connectionEvents', 'healthAssessments',
            'maintenancePlans', 'maintenanceLogs', 'maintenanceRecommendations'] as $relation) {
            if ($robot->{$relation}()->exists()) {
                return true;
            }
        }
        return false;
    }

    private function guestWriteAllowed(): bool
    {
        return (bool) config('rms_identity.guest_write_enabled', false);
    }

    private function ensureGuestWriteAllowed(): void
    {
        abort_unless($this->guestWriteAllowed(), 403,
            'Pengeditan identitas tanpa login dinonaktifkan. Aktifkan hanya melalui kebijakan IT/CIT di LAN terpercaya.');
    }

    /** Existing verified-home endpoint is retained intact. */
    public function saveHome(Request $request, Robot $robot): RedirectResponse
    {
        $rules = [
            'home_axes' => ['required', 'array:S,L,U,R,B,T'],
            'tolerance_pulses' => ['required', 'integer', 'between:0,1000000'],
            'verified_by' => ['required', 'string', 'max:120'],
            'source_reference' => ['required', 'string', 'max:255'],
            'home_verified' => ['accepted'],
            'captured_sample_id' => ['nullable', 'integer', 'min:1'],
        ];
        foreach (HomePositionMonitor::AXES as $axis) {
            $rules['home_axes.'.$axis] = ['required', 'integer', 'between:-2147483648,2147483647'];
        }
        $data = $request->validate($rules);
        if (blank($robot->serial_number) || (int) $robot->axis_count !== 6) {
            throw ValidationException::withMessages(['home_axes' => 'Lengkapi serial number dan gunakan robot enam axis untuk referensi home ini.']);
        }
        $axes = array_map('intval', $data['home_axes']);
        if (! empty($data['captured_sample_id'])) {
            $sample = $robot->telemetrySamples()->find($data['captured_sample_id']);
            $recent = $sample?->received_at?->betweenIncluded(
                now()->subSeconds((int) config('rms.telemetry_stale_seconds', 30)), now()->addSeconds(5));
            if (! $recent || ! $sample?->sampled_at?->betweenIncluded(now()->subSeconds(60), now()->addSeconds(5)) || ! $sample?->position_ok || data_get($sample->raw_payload, 'simulated') === true
                || data_get($sample->raw_payload, 'group') !== $robot->control_group
                || ! in_array(data_get($sample->raw_payload, 'position_unit'), ['pulse', 'pulses'], true)) {
                throw ValidationException::withMessages(['captured_sample_id' => 'Posisi yang diambil sudah tidak segar/valid. Ambil ulang atau masukkan referensi pendant secara manual.']);
            }
            foreach (HomePositionMonitor::AXES as $axis) {
                if (! isset($sample->position_pulses[$axis]) || (float) $axes[$axis] !== (float) $sample->position_pulses[$axis]) {
                    throw ValidationException::withMessages(['home_axes' => 'Posisi hasil capture berubah. Ambil ulang atau gunakan input manual.']);
                }
            }
        }
        DB::transaction(function () use ($robot, $data, $axes): void {
            $locked = Robot::query()->lockForUpdate()->findOrFail($robot->id);
            if ($locked->serial_number !== $robot->serial_number || $locked->control_group !== $robot->control_group) {
                throw ValidationException::withMessages(['home_axes' => 'Identitas robot berubah. Muat ulang dan periksa kembali referensi home.']);
            }
            $metadata = $locked->metadata ?? [];
            $metadata['home_position'] = [
                'axes' => $axes, 'tolerance_pulses' => (int) $data['tolerance_pulses'],
                'group' => $locked->control_group, 'serial_number' => $locked->serial_number,
                'verified' => true, 'verified_by' => $data['verified_by'],
                'source_reference' => $data['source_reference'], 'verified_at' => now()->toIso8601String(),
                'captured_sample_id' => $data['captured_sample_id'] ?? null,
            ];
            $locked->update(['metadata' => $metadata]);
        });
        $this->notify($robot, 'HomePositionReference');

        return back()->with('identity_success', 'Referensi home position berhasil disimpan.');
    }

    private function notify(Robot $robot, string $source): void
    {
        if (! config('rms_live.enabled', false)) {
            return;
        }
        try {
            event(new RmsRobotChanged((int) $robot->id, $source));
        } catch (\Throwable $exception) {
            Log::warning('RMS identity live notification failed.', [
                'robot_id' => $robot->id, 'exception' => $exception::class,
            ]);
        }
    }
}
