<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Events\RmsRobotChanged;
use App\Models\Robot;
use App\Services\Rms\ServoCurrentEstimator;
use App\Services\Rms\ServoCurrentMonitor;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

/** Uses the existing trusted-LAN identity-write gate; never sends robot commands. */
final class ServoCurrentController extends BaseRmsWebController
{
    public function edit(Robot $robot, ServoCurrentMonitor $monitor): View
    {
        $robot->load('controller');
        $snapshot = $monitor->build($robot, $robot->telemetrySamples()->orderByDesc('received_at')->orderByDesc('id')->first());

        return view('rms.robots.servo-current', [
            'title' => 'Parameter Arus Servo - '.$robot->name,
            'robot' => $robot, 'axes' => ServoCurrentEstimator::AXES,
            'profiles' => $snapshot['profiles'], 'servoCurrent' => $snapshot,
            'writeAllowed' => (bool) config('rms_identity.guest_write_enabled', false),
        ]);
    }

    public function live(Robot $robot, ServoCurrentMonitor $monitor): JsonResponse
    {
        return response()->json($monitor->recent($robot))
            ->header('Cache-Control', 'no-store, private')
            ->header('X-Content-Type-Options', 'nosniff');
    }

    public function update(Request $request, Robot $robot, ServoCurrentMonitor $monitor): RedirectResponse
    {
        abort_unless(config('rms_identity.guest_write_enabled', false), 403,
            'Pengeditan parameter motor dinonaktifkan pada lingkungan ini. Gunakan kebijakan akses yang sama dengan edit identitas robot.');
        $data = $request->validate([
            'axis' => ['required', Rule::in(ServoCurrentEstimator::AXES)],
            'profile_version' => ['nullable', 'uuid'],
            'motor_model' => ['required', 'string', 'max:120'],
            'nominal_torque_nm' => ['required', 'numeric', 'gt:0', 'max:10000000'],
            'torque_constant_nm_per_arms' => ['required', 'numeric', 'min:0.00000001', 'max:10000000'],
            'input_unit' => ['required', Rule::in(['sdk_raw', 'percent'])],
            'torque_percent_per_unit' => ['required', 'numeric', 'min:0.00000001', 'max:10000'],
            'source_reference' => ['required', 'string', 'max:500'],
            'checked_by' => ['required', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'enabled' => ['sometimes', 'boolean'],
            'basis_confirmed' => ['sometimes', 'accepted'],
        ]);
        foreach (['motor_model', 'source_reference', 'checked_by'] as $field) {
            $data[$field] = trim($data[$field]);
            if ($data[$field] === '') {
                throw ValidationException::withMessages([$field => 'Field ini wajib diisi dan tidak boleh hanya spasi.']);
            }
        }
        if ($request->boolean('enabled') && ! $request->boolean('basis_confirmed')) {
            throw ValidationException::withMessages(['basis_confirmed' => 'Konfirmasi acuan 100% torque, skala SDK, dan Kt dalam Nm/Arms sebelum mengaktifkan estimasi.']);
        }
        $expectedIdentity = $monitor->identity($robot);
        DB::transaction(function () use ($robot, $monitor, $request, $data, $expectedIdentity): void {
            $locked = Robot::query()->lockForUpdate()->findOrFail($robot->id);
            if ($monitor->identity($locked) !== $expectedIdentity) {
                throw ValidationException::withMessages(['axis' => 'Identitas robot berubah. Muat ulang form sebelum menyimpan.']);
            }
            $metadata = $locked->metadata ?? [];
            $profiles = $monitor->profiles($locked);
            $versions = array_values($profiles[$data['axis']] ?? []);
            $previous = $versions !== [] ? $versions[array_key_last($versions)] : null;
            if (($previous['id'] ?? null) !== ($data['profile_version'] ?? null)) {
                throw ValidationException::withMessages(['profile_version' => 'Parameter axis ini telah berubah dari sesi lain. Muat ulang form.']);
            }
            $versions[] = [
                'id' => (string) Str::uuid(), 'axis' => $data['axis'],
                'effective_from' => now()->utc()->format('Y-m-d\TH:i:s.u\Z'),
                'identity' => $expectedIdentity, 'motor_model' => $data['motor_model'],
                'nominal_torque_nm' => (float) $data['nominal_torque_nm'],
                'torque_constant_nm_per_arms' => (float) $data['torque_constant_nm_per_arms'],
                'torque_percent_per_unit' => (float) $data['torque_percent_per_unit'],
                'input_unit' => $data['input_unit'], 'current_unit' => 'Arms',
                'source_reference' => $data['source_reference'], 'checked_by' => $data['checked_by'],
                'notes' => trim((string) ($data['notes'] ?? '')),
                'enabled' => $request->boolean('enabled'),
                'basis_confirmed' => $request->boolean('basis_confirmed'),
                'formula_version' => 'torque_kt_v1', 'accuracy_verified' => false,
            ];
            $profiles[$data['axis']] = $versions;
            $metadata['servo_current'] = ['schema_version' => 1, 'profiles' => $profiles];
            $locked->update(['metadata' => $metadata]);
        }, 3);

        Log::info('rms.servo_current.profile_saved', ['robot_id' => $robot->id, 'axis' => $data['axis'], 'enabled' => $request->boolean('enabled')]);
        if (config('rms_live.enabled', false)) {
            try {
                event(new RmsRobotChanged((int) $robot->id, 'ServoCurrentProfile'));
            } catch (Throwable $exception) {
                Log::warning('RMS servo current live notification failed.', ['robot_id' => $robot->id, 'exception' => $exception::class]);
            }
        }

        return redirect(route('rms.robots.servo-current', $robot).'#motor-'.$data['axis'])
            ->with('servo_current_success', 'Profil motor axis '.$data['axis'].' disimpan. Perhitungan mulai dari sampel baru setelah waktu penyimpanan.');
    }
}
