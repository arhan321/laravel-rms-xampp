<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Models\AlarmCountermeasure;
use App\Services\Rms\AlarmCountermeasureContext;
use App\Services\Rms\AlarmCountermeasureStore;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class AlarmCountermeasureController extends BaseRmsWebController
{
    public function __construct(
        private readonly AlarmCountermeasureContext $contexts,
        private readonly AlarmCountermeasureStore $store,
    ) {}

    public function show(Request $request, string $kind, string $record): View
    {
        $context = $this->context($request, $kind, $record);

        return $this->page($request, $context);
    }

    public function edit(Request $request, string $kind, string $record, string $countermeasure): View
    {
        $context = $this->context($request, $kind, $record);
        $entry = $this->store->matching($context)->whereKey($countermeasure)->firstOrFail();

        return $this->page($request, $context, $entry);
    }

    public function store(Request $request, string $kind, string $record): RedirectResponse
    {
        $this->requireWritable();
        $context = $this->context($request, $kind, $record);
        $entry = $this->store->save($context, $this->validated($request, $context));

        return $this->saved($context, $entry);
    }

    public function update(Request $request, string $kind, string $record, string $countermeasure): RedirectResponse
    {
        $this->requireWritable();
        $context = $this->context($request, $kind, $record);
        $entry = $this->store->matching($context)->whereKey($countermeasure)->firstOrFail();
        $entry = $this->store->save($context, $this->validated($request, $context, $entry), $entry);

        return $this->saved($context, $entry);
    }

    private function context(Request $request, string $kind, string $record): array
    {
        $data = $request->validate(['alarm_index' => ['nullable', 'integer', 'min:0', 'max:199']]);

        return $this->contexts->resolve($kind, $record, (int) ($data['alarm_index'] ?? 0));
    }

    private function page(Request $request, array $context, ?AlarmCountermeasure $editing = null): View
    {
        $filter = $request->validate(['source' => ['nullable', Rule::in(['yaskawa_manual', 'field_experience'])]]);
        $query = $this->store->matching($context)->orderByDesc('is_active')->orderByDesc('id');

        return view('rms.alarms.countermeasures', [
            'title' => 'Penanganan Alarm', 'context' => $context,
            'routeParams' => $this->routeParams($context),
            'manuals' => (clone $query)->where('source_type', 'yaskawa_manual')->paginate(10, ['*'], 'manual_page')->withQueryString(),
            'experiences' => (clone $query)->where('source_type', 'field_experience')->paginate(10, ['*'], 'experience_page')->withQueryString(),
            'editing' => $editing,
            'revisions' => $editing?->revisions()->orderByDesc('version')->limit(10)->get() ?? collect(),
            'formSource' => $editing?->source_type ?? ($filter['source'] ?? 'field_experience'),
            'writesEnabled' => (bool) config('rms_alarm_countermeasures.writes_enabled', false),
            'resultLabels' => [
                'not_tested' => 'Belum diuji', 'in_progress' => 'Sedang ditangani',
                'resolved' => 'Dilaporkan berhasil', 'improved' => 'Membaik / perlu tindak lanjut',
                'ineffective' => 'Tidak berhasil',
            ],
        ]);
    }

    private function validated(Request $request, array $context, ?AlarmCountermeasure $editing = null): array
    {
        if ($context['controller_model_key'] === '') {
            throw ValidationException::withMessages(['controller_model' => 'Lengkapi model controller pada data robot sebelum menyimpan panduan.']);
        }
        $source = $editing?->source_type ?? $request->input('source_type');
        $official = $source === 'yaskawa_manual';
        $data = $request->validate([
            'source_type' => [$editing ? 'sometimes' : 'required', Rule::in($editing ? [$editing->source_type] : ['yaskawa_manual', 'field_experience'])],
            'title' => ['required', 'string', 'max:180'],
            'alarm_content' => [$official ? 'required' : 'nullable', 'string', 'max:6000'],
            'cause' => [$official ? 'required' : 'nullable', 'string', 'max:6000'],
            'measure' => ['required', 'string', 'max:8000'],
            'source_reference' => [$official ? 'required' : 'nullable', 'string', 'max:500'],
            'source_url' => ['nullable', 'url:http,https', 'max:1000'],
            'source_revision' => ['nullable', 'string', 'max:120'],
            'reported_by' => ['required', 'string', 'max:120'],
            'work_area' => ['nullable', 'string', 'max:120'],
            'result' => [$official ? 'nullable' : 'required', Rule::in(['not_tested', 'in_progress', 'resolved', 'improved', 'ineffective'])],
            'result_notes' => [! $official && in_array($request->input('result'), ['resolved', 'improved', 'ineffective'], true) ? 'required' : 'nullable', 'string', 'max:4000'],
            'subcode_scope' => ['required', Rule::in(['exact', 'all'])],
            'robot_scope' => ['required', Rule::in(['robot', 'model'])],
            'firmware_scope' => ['required', Rule::in($context['firmware_version'] === null ? ['any'] : ['any', 'current'])],
            'is_active' => ['required', 'boolean'],
            'version' => [$editing ? 'required' : 'nullable', 'integer', 'min:1'],
        ], [
            'source_reference.required' => 'Cantumkan nomor/judul manual dan halaman, atau menu Alarm Detail pendant yang menjadi sumber.',
            'measure.required' => 'Isi langkah pemeriksaan atau penanganan alarm.',
            'reported_by.required' => 'Isi nama pengisi catatan.',
            'result_notes.required' => 'Jelaskan hasil pemeriksaan yang mendukung status penanganan ini.',
            'source_url.url' => 'Link sumber harus diawali http:// atau https://.',
        ]);
        $data['source_type'] = $source;

        return $data;
    }

    private function saved(array $context, AlarmCountermeasure $entry): RedirectResponse
    {
        return redirect()->route('rms.alarms.countermeasures.show', $this->routeParams($context))
            ->with('countermeasure_success', 'Catatan penanganan tersimpan (versi '.$entry->version.'). Status alarm controller tetap mengikuti pembacaan robot.');
    }

    private function routeParams(array $context): array
    {
        return ['kind' => $context['kind'], 'record' => $context['record'], 'alarm_index' => $context['alarm_index']];
    }

    private function requireWritable(): void
    {
        abort_unless((bool) config('rms_alarm_countermeasures.writes_enabled', false), 403,
            'Pencatatan countermeasure dinonaktifkan. Hubungi administrator RMS.');
    }
}
