@extends('rms.layouts.app')
@section('heading', 'Follow Up Instruction')
@section('subtitle', 'Tindak lanjut pemeriksaan dan penanganan alarm robot.')
@push('styles')
<link rel="stylesheet" href="{{ asset('rms/css/alarm-countermeasures.css') }}">
<link rel="stylesheet" href="{{ asset('rms/css/alarm-follow-up.css') }}?v=3">
<script defer src="{{ asset('rms/js/alarm-follow-up-signature.js') }}"></script>
@endpush
@section('content')
@php
  $stages = \App\Models\RobotAlarmFollowUp::STAGES;
  $statuses = \App\Models\RobotAlarmFollowUp::STATUS;
  $results = \App\Models\RobotAlarmFollowUp::RESULTS;
  $sourceLabels = ['yaskawa_manual' => 'Referensi Yaskawa', 'field_experience' => 'Pengalaman tim jalur'];
  $revisionLabels = ['created'=>'Draft dibuat', 'updated'=>'Tindak lanjut diperbarui', 'evidence'=>'Data teknis diperbarui', 'reopened'=>'Dikembalikan ke draft', ...$statuses];
  $case = $fui?->evidence;
  $work = $fui?->work ?? [];
  $canEditDraft = $fui && $canWrite && $fui->status === 'draft';
  $recommendationSections = $automaticGuidance ? \App\Services\Rms\AlarmFollowUpEvidence::recommendationSections($automaticGuidance) : [];
@endphp
<div class="rms-cm-toolbar">
  <a class="btn" href="{{ route('rms.alarms.index', ['robot_id' => $context['robot_id']]) }}">← Alarm Center</a>
  <a class="btn" href="{{ route('rms.alarms.countermeasures.show', $routeParams) }}">Panduan penanganan</a>
  @if($fui)
    <a class="btn primary" href="{{ route('rms.alarms.fui.pdf', $routeParams) }}">Unduh {{ $fui->status === 'approved' ? 'PDF FUI' : 'PDF pratinjau' }}</a>
  @endif
</div>
@if(session('fui_success'))
  <div class="rms-cm-success" role="status">{{ session('fui_success') }}</div>
@endif
@if($errors->any())
  <div class="rms-cm-errors" role="alert"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
@endif

<section class="panel fui-section">
  <div class="panel-header">
    <div>
      <h2>{{ $fui?->number() ?? 'FUI untuk alarm ini' }}</h2>
      <p>{{ $case['robot']['code'] ?? $context['robot']->robot_code }} · {{ $fui?->alarm_code ?? $context['alarm_code'] }}{{ ($fui ? $fui->subcode : $context['subcode']) !== null ? ' / '.($fui ? $fui->subcode : $context['subcode']) : '' }} · {{ $case['alarm']['alarm_name'] ?? $context['alarm_name'] }}</p>
    </div>
    @if($fui)<span class="badge">{{ $statuses[$fui->status] }} · v{{ $fui->version }}</span>@endif
  </div>
  @if(!$fui)
    @if($canWrite)
      <form method="POST" action="{{ route('rms.alarms.fui.store', $routeParams) }}">@csrf<button class="btn primary">Buat FUI</button></form>
    @else
      <p>Penulisan mengikuti pengaturan akses Penanganan Alarm RMS.</p>
    @endif
  @else
    <dl class="rms-cm-facts">
      <div><dt>Robot</dt><dd>{{ $case['robot']['name'] }} · {{ $case['robot']['model'] ?: '—' }}</dd></div>
      <div><dt>Line / station</dt><dd>{{ $case['robot']['line'] ?: '—' }} / {{ $case['robot']['station'] ?: '—' }}</dd></div>
      <div><dt>Controller</dt><dd>{{ $case['robot']['controller_model'] ?: '—' }} · {{ $case['robot']['firmware'] ?: '—' }}</dd></div>
      <div><dt>Waktu RMS</dt><dd>{{ \App\Services\Rms\AlarmFollowUpEvidence::wib($case['alarm']['observed_at']) }}</dd></div>
    </dl>
    <p>PDF memuat Pareto dari {{ $case['pareto']['total'] }} rekaman riwayat unik robot ini dan tren torque serta posisi PULSE di sekitar alarm.</p>
    @if($canEditDraft)
      @if(!$automaticGuidance)
        <p class="fui-muted">Draft ini dibuat sebelum pengisian otomatis. Gunakan Ambil data terbaru untuk mengisi kondisi dan rekomendasi tanpa menghapus catatan yang tersimpan.</p>
      @endif
      <details><summary>Perbarui data teknis dan sumber panduan</summary>
        <form class="fui-form fui-inline" method="POST" action="{{ route('rms.alarms.fui.refresh', $routeParams) }}">
          @csrf
          <input type="hidden" name="version" value="{{ $fui->version }}">
          <label class="field">Nama pengisi<input class="input" name="editor" maxlength="120" required></label>
          <button class="btn">Ambil data terbaru</button>
        </form>
      </details>
    @endif
  @endif
</section>

@if($automaticGuidance && !$canEditDraft)
  <section class="panel fui-section">
    <div class="panel-header"><h2>Kondisi dan rekomendasi dari RMS</h2></div>
    <h3>Kondisi saat ini</h3>
    <p class="fui-text">{{ $automaticGuidance['condition'] }}</p>
    <div class="fui-grid">
      @foreach($recommendationSections as $section)
        <article class="fui-source">
          <h3>{{ $section['title'] }}</h3>
          <p class="fui-text">{{ $section['text'] }}</p>
        </article>
      @endforeach
    </div>
  </section>
@endif

@if($fui)
  <section class="panel fui-section">
    <div class="panel-header"><h2>Fault Code dan Alarm History</h2></div>
    <p>Seluruh histori unik robot ini · Diambil {{ \App\Services\Rms\AlarmFollowUpEvidence::wib($case['captured_at'] ?? null) }}</p>
    @if($case['pareto']['rows'] === [])
      <p>Belum ada riwayat alarm controller untuk robot ini.</p>
    @else
      <details>
        <summary>Rincian Pareto dan alarm terakhir</summary>
        <div class="fui-table-wrap">
          <table class="data-table">
            <thead><tr><th>Kode / Subcode</th><th>Alarm</th><th>Jumlah</th><th>Persen</th><th>Kumulatif</th></tr></thead>
            <tbody>
              @foreach($case['pareto']['rows'] as $row)
                <tr><td>{{ $row['label'] }}</td><td>{{ $row['name'] }}</td><td>{{ $row['count'] }}</td><td>{{ $row['percent'] }}%</td><td>{{ $row['cumulative'] }}%</td></tr>
              @endforeach
            </tbody>
          </table>
          <table class="data-table">
            <thead><tr><th>Kode / Subcode</th><th>Alarm</th><th>Kategori</th><th>Waktu controller (raw)</th><th>Pertama terlihat RMS</th></tr></thead>
            <tbody>
              @foreach($case['history'] as $entry)
                <tr><td>{{ $entry['code'] }} / {{ $entry['subcode'] ?? '—' }}</td><td>{{ $entry['name'] }}</td><td>{{ $entry['category'] }}</td><td>{{ $entry['raw'] ?? '—' }}</td><td>{{ $entry['seen'] }}</td></tr>
              @endforeach
            </tbody>
          </table>
        </div>
      </details>
    @endif
  </section>

  <section class="panel fui-section">
    <div class="panel-header"><h2>Sumber panduan</h2></div>
    <div class="fui-grid">
      @foreach($sourceLabels as $key => $label)
        <div class="fui-source">
          <h3>{{ $label }}</h3>
          @forelse($case['references'][$key] as $guide)
            <article>
              <strong>{{ $guide['title'] }}</strong>
              <p class="fui-muted">Versi {{ $guide['version'] }} · {{ $guide['source_reference'] ?: ($guide['work_area'] ?: $guide['reported_by']) }}</p>
              <p class="fui-text">{{ $guide['measure'] }}</p>
            </article>
          @empty
            <p>Belum ada panduan cocok yang aktif di RMS.</p>
          @endforelse
        </div>
      @endforeach
    </div>
  </section>

  <section class="panel fui-section">
    <div class="panel-header"><h2>Tindak lanjut mekanik</h2></div>
    @php
      $editableFields = $automaticGuidance ? array_replace($workFields, [
          'current_condition' => 'Catatan kondisi lapangan',
          'recommendation' => 'Tambahan rekomendasi dari tim',
          'mechanic_action' => 'Tindakan yang dilakukan',
      ]) : $workFields;
      $optionalFields = ['current_condition', 'recommendation'];
    @endphp
    @if($case['previous'])
      <details>
        <summary>FUI sebelumnya · {{ $case['previous']['number'] }}</summary>
        @foreach($workFields as $field => $label)
          <p><strong>{{ $label }}</strong></p><p class="fui-text">{{ $case['previous']['work'][$field] ?? '—' }}</p>
        @endforeach
      </details>
    @endif
    @if($canEditDraft)
      <form class="fui-form" method="POST" action="{{ route('rms.alarms.fui.update', $routeParams) }}">
        @csrf @method('PATCH')
        <input type="hidden" name="version" value="{{ $fui->version }}">
        @if($automaticGuidance)
          <p class="fui-muted" id="fui-system-hint">Kondisi dan kedua rekomendasi diisi otomatis oleh RMS. Catat hasil pemeriksaan, tindakan yang dilakukan, dan status pekerjaan di bawahnya.</p>
          <label class="field fui-system-condition" for="fui-system-condition">Kondisi saat ini · Otomatis dari RMS
            <textarea id="fui-system-condition" class="input fui-system-value" rows="4" readonly aria-describedby="fui-system-hint">{{ $automaticGuidance['condition'] }}</textarea>
          </label>
          <div class="fui-grid">
            @foreach($recommendationSections as $source => $section)
              <label class="field" for="fui-system-{{ $source }}">{{ $section['title'] }} · Otomatis dari RMS
                <textarea id="fui-system-{{ $source }}" class="input fui-system-value" rows="6" readonly aria-describedby="fui-system-hint">{{ $section['text'] }}</textarea>
              </label>
            @endforeach
          </div>
        @endif
        <div class="fui-grid">
          <label class="field">Pengisi (Team Leader / Foreman)<input class="input" name="editor" value="{{ old('editor', $work['editor'] ?? '') }}" required maxlength="120"></label>
          <label class="field">Nama mekanik<input class="input" name="mechanic_name" value="{{ old('mechanic_name', $work['mechanic_name'] ?? '') }}" maxlength="120"></label>
          <label class="field">Tanggal tindak lanjut (WIB)<input class="input" type="date" name="follow_date" max="{{ now('Asia/Jakarta')->toDateString() }}" value="{{ old('follow_date', $work['follow_date'] ?? '') }}"></label>
          <label class="field">Jam operasi saat tindak lanjut<input class="input" type="number" name="follow_hours" min="0" max="999999999" step="any" value="{{ old('follow_hours', $work['follow_hours'] ?? '') }}"></label>
          @foreach($editableFields as $field => $label)
            @continue($automaticGuidance && in_array($field, $optionalFields, true))
            <label class="field">{{ $label }}<textarea class="input" name="{{ $field }}" maxlength="2500" rows="3">{{ old($field, $work[$field] ?? '') }}</textarea></label>
          @endforeach
          <label class="field">Status pekerjaan<select class="input" name="result">@foreach($results as $key => $label)<option value="{{ $key }}" @selected(old('result', $work['result'] ?? 'open') === $key)>{{ $label }}</option>@endforeach</select></label>
        </div>
        @if($automaticGuidance)
          <details class="fui-extra-notes" @if(filled(old('current_condition', $work['current_condition'] ?? null)) || filled(old('recommendation', $work['recommendation'] ?? null)) || $errors->hasAny($optionalFields)) open @endif>
            <summary>Catatan tambahan (opsional)</summary>
            <div class="fui-grid">
              @foreach($optionalFields as $field)
                <label class="field">{{ $editableFields[$field] }}<textarea class="input" name="{{ $field }}" maxlength="2500" rows="3">{{ old($field, $work[$field] ?? '') }}</textarea></label>
              @endforeach
            </div>
          </details>
        @endif
        <button type="submit" class="btn primary">Simpan tindak lanjut</button>
      </form>
    @else
      <p>Mekanik {{ $work['mechanic_name'] ?? '—' }} · {{ $work['follow_date'] ?? '—' }} · {{ $results[$work['result'] ?? ''] ?? '—' }}</p>
      <div class="fui-grid">
        @foreach($editableFields as $field => $label)
          <div><strong>{{ $label }}</strong><p class="fui-text">{{ $work[$field] ?? '—' }}</p></div>
        @endforeach
      </div>
    @endif
  </section>

  <section class="panel fui-section">
    <div class="panel-header"><h2>Tanda tangan internal</h2></div>
    <p class="fui-muted">RMS mencatat nama, goresan, dan waktu yang dibubuhkan melalui akses internal Alarm Center.</p>
    <ol class="fui-stage-list">
      @foreach($stages as $key => $role)
        <li><strong>{{ $role }}</strong> · {{ $fui->signatures[$key]['name'] ?? 'Menunggu tanda tangan' }}
          @if(isset($fui->signatures[$key]['at'])) · {{ \App\Services\Rms\AlarmFollowUpEvidence::wib($fui->signatures[$key]['at']) }}@endif
        </li>
      @endforeach
    </ol>
    @if($canWrite && $fui->nextStage())
      <form class="fui-form" method="POST" action="{{ route('rms.alarms.fui.signature', $routeParams) }}" data-fui-signature>
        @csrf
        <input type="hidden" name="version" value="{{ $fui->version }}">
        <input type="hidden" name="stage" value="{{ $fui->nextStage() }}">
        <input type="hidden" name="signature" value="">
        <label class="field">Nama penanda tangan ({{ $stages[$fui->nextStage()] }})<input class="input" name="name" maxlength="120" required></label>
        <canvas width="720" height="240" aria-label="Kotak tanda tangan. Gunakan mouse atau layar sentuh."></canvas>
        <p class="fui-muted" data-signature-message aria-live="polite">Bubuhkan tanda tangan dengan mouse atau layar sentuh.</p>
        <label><input type="checkbox" name="consent" value="1" required> Saya membubuhkan tanda tangan saya pada tahap ini.</label>
        <div class="rms-cm-toolbar"><button type="button" class="btn" data-signature-clear>Hapus goresan</button><button type="submit" class="btn primary" disabled>Simpan tanda tangan</button></div>
      </form>
    @endif
    @if($canWrite && in_array($fui->status, ['analyzed','validated'], true))
      <details>
        <summary>Minta revisi</summary>
        <p>FUI kembali ke draft dan memerlukan tanda tangan baru.</p>
        <form class="fui-form fui-inline" method="POST" action="{{ route('rms.alarms.fui.reopen', $routeParams) }}">
          @csrf
          <input type="hidden" name="version" value="{{ $fui->version }}">
          <label class="field">Nama pengisi<input class="input" name="editor" maxlength="120" required></label>
          <label class="field">Alasan revisi<input class="input" name="reason" maxlength="500" required></label>
          <button class="btn">Kembalikan ke draft</button>
        </form>
      </details>
    @endif
  </section>
  <section class="panel fui-section">
    <details>
      <summary><strong>Riwayat perubahan</strong></summary>
      <ol class="fui-stage-list">
        @foreach($revisions as $revision)
          <li>v{{ $revision->version }} · {{ $revisionLabels[$revision->action] ?? $revision->action }} · {{ $revision->recorded_by ?? 'RMS' }}
            · {{ \App\Services\Rms\AlarmFollowUpEvidence::wib($revision->created_at?->toIso8601String()) }}
            @if(filled($revision->payload['reason'] ?? null))<p class="fui-text">{{ $revision->payload['reason'] }}</p>@endif
          </li>
        @endforeach
      </ol>
    </details>
  </section>
@endif
@endsection
