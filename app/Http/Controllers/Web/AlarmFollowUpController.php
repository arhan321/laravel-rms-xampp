<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Models\Robot;
use App\Models\RobotAlarmFollowUp;
use App\Services\Rms\AlarmCountermeasureContext;
use App\Services\Rms\AlarmFollowUpEvidence;
use App\Services\Rms\AlarmFollowUpPdf;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class AlarmFollowUpController extends BaseRmsWebController
{
    private const WORK_FIELDS = [
        'current_condition' => 'Kondisi saat ini', 'recommendation' => 'Rekomendasi tindakan',
        'mechanic_findings' => 'Hasil pemeriksaan mekanik', 'mechanic_action' => 'Tindak lanjut oleh mekanik',
        'foreman_alternative' => 'Solusi alternatif dari Foreman',
    ];

    public function __construct(
        private readonly AlarmCountermeasureContext $contexts,
        private readonly AlarmFollowUpEvidence $evidence,
    ) {}

    public function show(string $kind, string $record): View
    {
        $context = $this->contexts->resolve($kind, $record);
        $record = $context['record'];
        $report = $this->query($kind, $record)->first();

        return view('rms.alarms.follow-up', [
            'title' => 'Follow Up Instruction', 'context' => $context, 'fui' => $report,
            'routeParams' => [$kind, $record], 'workFields' => self::WORK_FIELDS,
            'revisions' => $report?->revisions()->orderByDesc('version')->get() ?? collect(),
            'canWrite' => $this->canWrite(),
        ]);
    }

    public function store(string $kind, string $record): RedirectResponse
    {
        $this->requireWrite();
        $context = $this->contexts->resolve($kind, $record);
        $record = $context['record'];
        abort_if($context['observed_at'] === null, 422, 'Waktu alarm RMS belum tersedia.');
        DB::transaction(function () use ($context, $kind, $record): void {
            Robot::query()->lockForUpdate()->findOrFail($context['robot_id']);
            if ($this->query($kind, $record)->exists()) {
                return;
            }

            $fui = RobotAlarmFollowUp::query()->create([
                'robot_id' => $context['robot_id'], 'source_kind' => $kind, 'source_record' => $record,
                'alarm_code' => $context['alarm_code'], 'subcode' => $context['subcode'],
                'alarm_observed_at' => CarbonImmutable::parse($context['observed_at'])->timezone(config('app.timezone')),
                'status' => 'draft', 'version' => 1, 'evidence' => $this->evidence->capture($context),
            ]);
            $this->record($fui, 'created', null);
        }, 3);

        return $this->backTo($kind, $record, 'Draft FUI tersedia.');
    }

    public function update(Request $request, string $kind, string $record): RedirectResponse
    {
        $this->requireWrite();
        $rules = [
            'version' => ['required', 'integer', 'min:1'], 'editor' => ['required', 'string', 'max:120'],
            'mechanic_name' => ['nullable', 'string', 'max:120'],
            'follow_date' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:'.now('Asia/Jakarta')->toDateString()],
            'follow_hours' => ['nullable', 'numeric', 'min:0', 'max:999999999'],
            'result' => ['required', Rule::in(array_keys(RobotAlarmFollowUp::RESULTS))],
        ];
        foreach (self::WORK_FIELDS as $field => $label) {
            $rules[$field] = ['nullable', 'string', 'max:2500'];
        }
        $data = $request->validate($rules);

        DB::transaction(function () use ($kind, $record, $data): void {
            $fui = $this->locked($kind, $record, (int) $data['version']);
            $this->requireDraft($fui);
            $fui->work = array_diff_key($data, ['version' => true]);
            $this->saveRevision($fui, 'updated', $data['editor']);
        }, 3);

        return $this->backTo($kind, $record, 'Tindak lanjut FUI tersimpan.');
    }

    public function refresh(Request $request, string $kind, string $record): RedirectResponse
    {
        $this->requireWrite();
        $data = $request->validate(['version' => ['required', 'integer', 'min:1'], 'editor' => ['required', 'string', 'max:120']]);
        $context = $this->contexts->resolve($kind, $record);
        $record = $context['record'];

        DB::transaction(function () use ($context, $kind, $record, $data): void {
            $fui = $this->locked($kind, $record, (int) $data['version']);
            $this->requireDraft($fui);
            abort_if($context['observed_at'] === null, 422, 'Waktu alarm RMS belum tersedia.');
            $fui->evidence = $this->evidence->capture($context);
            $fui->alarm_observed_at = CarbonImmutable::parse($context['observed_at'])->timezone(config('app.timezone'));
            $this->saveRevision($fui, 'evidence', $data['editor']);
        }, 3);

        return $this->backTo($kind, $record, 'Data robot, alarm, dan sumber panduan diperbarui.');
    }

    public function sign(Request $request, string $kind, string $record): RedirectResponse
    {
        $this->requireWrite();
        $data = $request->validate([
            'version' => ['required', 'integer', 'min:1'], 'name' => ['required', 'string', 'max:120'],
            'stage' => ['required', Rule::in(array_keys(RobotAlarmFollowUp::STAGES))],
            'signature' => ['required', 'string', 'max:28000', 'json'], 'consent' => ['accepted'],
        ]);
        $strokes = $this->signatureStrokes($data['signature']);

        DB::transaction(function () use ($kind, $record, $data, $strokes): void {
            $fui = $this->locked($kind, $record, (int) $data['version']);
            if ($fui->nextStage() !== $data['stage']) {
                throw ValidationException::withMessages(['stage' => 'Ikuti urutan analisis, validasi, lalu persetujuan.']);
            }
            if ($fui->status === 'draft' && (
                blank($fui->work['mechanic_name'] ?? null) || blank($fui->work['follow_date'] ?? null)
                || blank($fui->work['mechanic_action'] ?? null)
            )) {
                throw ValidationException::withMessages(['stage' => 'Simpan nama mekanik, tanggal, dan tindak lanjut sebelum menandatangani.']);
            }
            $signatures = $fui->signatures ?? [];
            $signatures[$data['stage']] = ['name' => $data['name'], 'at' => now()->utc()->toIso8601String(), 'strokes' => $strokes];
            $fui->signatures = $signatures;
            $fui->status = $data['stage'];
            $this->saveRevision($fui, $data['stage'], $data['name']);
        }, 3);

        return $this->backTo($kind, $record, 'Tanda tangan tersimpan.');
    }

    public function reopen(Request $request, string $kind, string $record): RedirectResponse
    {
        $this->requireWrite();
        $data = $request->validate([
            'version' => ['required', 'integer', 'min:1'], 'editor' => ['required', 'string', 'max:120'],
            'reason' => ['required', 'string', 'max:500'],
        ]);
        DB::transaction(function () use ($kind, $record, $data): void {
            $fui = $this->locked($kind, $record, (int) $data['version']);
            if (! in_array($fui->status, ['analyzed', 'validated'], true)) {
                throw ValidationException::withMessages(['reason' => 'Revisi hanya tersedia pada FUI yang sedang menunggu persetujuan akhir.']);
            }
            $fui->status = 'draft';
            $fui->signatures = [];
            $this->saveRevision($fui, 'reopened', $data['editor'], $data['reason']);
        }, 3);

        return $this->backTo($kind, $record, 'FUI kembali ke draft. Tanda tangan lama tercatat di riwayat revisi.');
    }

    public function download(string $kind, string $record, AlarmFollowUpPdf $pdf): Response
    {
        $fui = $this->query($kind, $record)->firstOrFail();

        return response($pdf->render($fui), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$fui->number().'-v'.$fui->version.'.pdf"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function query(string $kind, string $record): Builder
    {
        return RobotAlarmFollowUp::query()->where('source_kind', $kind)->where('source_record', $record);
    }

    private function locked(string $kind, string $record, int $version): RobotAlarmFollowUp
    {
        $fui = $this->query($kind, $record)->lockForUpdate()->firstOrFail();
        if ($version !== $fui->version) {
            throw ValidationException::withMessages(['version' => 'FUI sudah diubah. Muat ulang halaman lalu periksa versi terbaru.']);
        }

        return $fui;
    }

    private function requireDraft(RobotAlarmFollowUp $fui): void
    {
        if ($fui->status !== 'draft') {
            throw ValidationException::withMessages(['version' => 'Isi laporan hanya bisa diubah dalam status draft.']);
        }
    }

    private function saveRevision(RobotAlarmFollowUp $fui, string $action, string $editor, ?string $reason = null): void
    {
        $fui->version++;
        $fui->save();
        $this->record($fui, $action, $editor, $reason);
    }

    private function record(RobotAlarmFollowUp $fui, string $action, ?string $editor, ?string $reason = null): void
    {
        $payload = [...$fui->only(['status', 'work', 'signatures']), 'reason' => $reason];
        if (in_array($action, ['created', 'evidence'], true)) {
            $payload['evidence'] = $fui->evidence;
        }
        $fui->revisions()->create([
            'version' => $fui->version, 'action' => $action, 'recorded_by' => $editor, 'payload' => $payload,
        ]);
    }

    private function signatureStrokes(string $json): array
    {
        $strokes = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        $error = ValidationException::withMessages(['signature' => 'Bubuhkan tanda tangan pada kotak tanda tangan.']);
        if (! is_array($strokes) || ! array_is_list($strokes) || count($strokes) < 1 || count($strokes) > 40) {
            throw $error;
        }
        $points = 0;
        $length = 0.0;
        foreach ($strokes as $stroke) {
            if (! is_array($stroke) || ! array_is_list($stroke) || count($stroke) < 2) {
                throw $error;
            }
            $last = null;
            foreach ($stroke as $point) {
                if (! is_array($point) || ! array_is_list($point) || count($point) !== 2
                    || ! is_int($point[0]) || ! is_int($point[1])
                    || min($point) < 0 || max($point) > 1000 || ++$points > 1500) {
                    throw $error;
                }
                if ($last !== null) {
                    $length += hypot($point[0] - $last[0], $point[1] - $last[1]);
                }
                $last = $point;
            }
        }
        if ($length < 20) {
            throw $error;
        }

        return $strokes;
    }

    private function backTo(string $kind, string $record, string $message): RedirectResponse
    {
        return redirect()->route('rms.alarms.fui.show', [$kind, $record])->with('fui_success', $message);
    }

    private function canWrite(): bool
    {
        return (bool) config('rms_alarm_countermeasures.writes_enabled', false);
    }

    private function requireWrite(): void
    {
        abort_unless($this->canWrite(), 403, 'Pencatatan FUI mengikuti pengaturan penulisan penanganan alarm RMS.');
    }
}
