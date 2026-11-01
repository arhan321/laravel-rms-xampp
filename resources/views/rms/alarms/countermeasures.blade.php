@extends('rms.layouts.app')
@section('heading', 'Penanganan alarm')
@section('subtitle', 'Referensi Yaskawa dan pengalaman tim jalur untuk alarm yang teridentifikasi.')
@section('auto_refresh', 'off')
@push('styles')
<link rel="stylesheet" href="{{ asset('rms/css/alarm-countermeasures.css') }}?v=20261031-1">
@endpush
@section('content')
@php
  $manualForm = $formSource === 'yaskawa_manual';
  $baseUrl = route('rms.alarms.countermeasures.show', $routeParams);
@endphp
<div class="rms-cm-toolbar">
  <a class="btn" href="{{ route('rms.alarms.index', ['robot_id' => $context['robot_id'], 'search' => $context['alarm_code']]) }}">← Alarm center</a>
  <a class="btn" href="{{ route('rms.robots.show', $context['robot']) }}">Detail robot</a>
  @if($writesEnabled)
    <a class="btn primary" href="{{ $baseUrl }}#countermeasure-form">+ Pengalaman jalur</a>
    <a class="btn" href="{{ route('rms.alarms.countermeasures.show', [...$routeParams, 'source' => 'yaskawa_manual']) }}#countermeasure-form">+ Referensi Yaskawa</a>
  @endif
</div>
@if(session('countermeasure_success'))
  <div class="rms-cm-success" role="status">{{ session('countermeasure_success') }}</div>
@endif
<section class="panel rms-cm-context">
  <div class="rms-cm-heading"><div><span class="eyebrow">ALARM {{ $context['alarm_code'] }} / {{ $context['subcode'] ?? 'Tidak tersedia' }}</span><h2>{{ $context['alarm_name'] ?: 'Nama alarm belum tersedia' }}</h2></div><span class="badge rms-badge warning">PANDUAN PENANGANAN</span></div>
  <dl class="rms-cm-facts">
    <div><dt>Robot</dt><dd>{{ $context['robot']->name }} · {{ $context['robot']->robot_code }}</dd></div>
    <div><dt>Model controller</dt><dd>{{ $context['controller_model'] ?: 'Belum diisi' }}</dd></div>
    <div><dt>Firmware di data master</dt><dd>{{ $context['firmware_version'] ?? 'Belum diisi' }}</dd></div>
    <div><dt>Waktu controller (raw)</dt><dd>{{ $context['controller_time_raw'] ?? 'Tidak tersedia' }}</dd></div>
  </dl>
  <p class="rms-muted-small">Model dan firmware mengikuti data master robot saat ini. Cocokkan dengan unit serta revisi manual yang berlaku, terutama untuk alarm lama. Subcode ditampilkan apa adanya; arti axis mengikuti manual controller.</p>
</section>
<div class="rms-note rms-section-spaced">Panduan membantu pemeriksaan; bukan diagnosis otomatis. Ikuti SOP keselamatan dan isolasi energi sebelum pekerjaan listrik/mekanik. Status hasil pengalaman tidak mereset atau menutup alarm controller.</div>
<div class="rms-cm-columns rms-section-spaced">
  <section class="panel rms-cm-source">
    <div class="panel-header"><div><span class="eyebrow">SUMBER 01</span><h2>Referensi Yaskawa</h2><p>Input manual dari buku manual atau Alarm Detail pendant.</p></div><span class="rms-cm-count">{{ $manuals->total() }}</span></div>
    @forelse($manuals as $item)
      @include('rms.alarms.partials.countermeasure-card', ['item' => $item])
    @empty
      <div class="rms-cm-empty"><strong>Panduan Yaskawa belum diinput.</strong><p>Tambahkan Alarm Content, Cause, dan Measure beserta referensinya. YMConnect saat ini mengirim identitas alarm, bukan teks panduan pendant.</p></div>
    @endforelse
    @if($context['controller_model_key'] === 'YRC1000' && $context['alarm_code'] === '4337')
      <p><a class="btn" href="https://knowledge.motoman.com/hc/en-us/articles/18000895648407-YRC1000-ALARM-CODE-4337-OVERCURRENT-AMP" target="_blank" rel="noopener noreferrer">Buka referensi Yaskawa 4337 ↗</a></p>
      <p class="rms-muted-small">Link ini memerlukan internet. Teks yang disimpan di RMS tetap dapat dibaca secara lokal.</p>
    @endif
    <div class="rms-pagination">{{ $manuals->links() }}</div>
  </section>
  <section class="panel rms-cm-source">
    <div class="panel-header"><div><span class="eyebrow">SUMBER 02</span><h2>Pengalaman tim jalur</h2><p>Temuan, tindakan, dan hasil yang dilaporkan teknisi / foreman.</p></div><span class="rms-cm-count">{{ $experiences->total() }}</span></div>
    @forelse($experiences as $item)
      @include('rms.alarms.partials.countermeasure-card', ['item' => $item])
    @empty
      <div class="rms-cm-empty"><strong>Belum ada pengalaman yang sesuai.</strong><p>Catat tindakan dan hasil pemeriksaan agar dapat dipelajari ketika alarm serupa muncul kembali.</p></div>
    @endforelse
    <div class="rms-pagination">{{ $experiences->links() }}</div>
  </section>
</div>
<section class="panel rms-section-spaced" id="countermeasure-form">
  <div class="panel-header"><div><h2>{{ $editing ? 'Edit catatan penanganan' : 'Tambah catatan penanganan' }}</h2><p>{{ $manualForm ? 'Referensi Yaskawa — input manual' : 'Pengalaman tim jalur' }}</p></div>@if($editing)<span class="badge rms-badge unknown">Versi {{ $editing->version }}</span>@endif</div>
  @if($errors->any())
    <div class="rms-cm-errors" role="alert"><strong>Catatan belum disimpan.</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
  @endif
  @if(!$writesEnabled)
    <div class="rms-note">Mode input dinonaktifkan. Hubungi administrator untuk mengaktifkan pencatatan penanganan.</div>
  @elseif($context['controller_model_key'] === '')
    <div class="rms-note">Lengkapi model controller melalui Edit identitas robot sebelum menambahkan panduan.</div>
  @else
    @unless($editing)
      <div class="rms-cm-tabs" aria-label="Sumber catatan">
        <a class="btn {{ !$manualForm ? 'primary' : '' }}" href="{{ $baseUrl }}#countermeasure-form">Pengalaman jalur</a>
        <a class="btn {{ $manualForm ? 'primary' : '' }}" href="{{ route('rms.alarms.countermeasures.show', [...$routeParams, 'source' => 'yaskawa_manual']) }}#countermeasure-form">Referensi Yaskawa</a>
      </div>
    @endunless
    <form method="POST" action="{{ $editing ? route('rms.alarms.countermeasures.update', [...$routeParams, 'countermeasure' => $editing->id]) : route('rms.alarms.countermeasures.store', $routeParams) }}" class="rms-cm-form">
      @csrf
      @if($editing) @method('PUT') <input type="hidden" name="version" value="{{ old('version', $editing->version) }}"> @endif
      <input type="hidden" name="source_type" value="{{ $formSource }}">
      <label class="field">Judul catatan *<input class="input" name="title" value="{{ old('title', $editing?->title ?? $context['alarm_name']) }}" required maxlength="180"></label>
      <div class="rms-cm-form-grid">
        <label class="field">Nama pengisi / pengubah *<input class="input" name="reported_by" value="{{ old('reported_by') }}" required maxlength="120" placeholder="Nama teknisi atau foreman"></label>
        <label class="field">Jalur / area<input class="input" name="work_area" value="{{ old('work_area', $editing?->work_area) }}" maxlength="120" placeholder="Contoh: Welding 2 / DOJO"></label>
      </div>
      <label class="field">{{ $manualForm ? 'Alarm Content — penjelasan alarm *' : 'Gejala / kondisi saat kejadian' }}<textarea class="input" name="alarm_content" rows="3" maxlength="6000" @required($manualForm)>{{ old('alarm_content', $editing?->alarm_content) }}</textarea></label>
      <label class="field">{{ $manualForm ? 'Cause — kemungkinan penyebab dari sumber *' : 'Temuan / dugaan penyebab berdasarkan pemeriksaan' }}<textarea class="input" name="cause" rows="3" maxlength="6000" @required($manualForm)>{{ old('cause', $editing?->cause) }}</textarea></label>
      <label class="field">{{ $manualForm ? 'Measure — langkah penanganan dari sumber *' : 'Countermeasure — tindakan / langkah pemeriksaan *' }}<textarea class="input" name="measure" rows="5" maxlength="8000" required placeholder="Tuliskan langkah secara berurutan. Catat persyaratan keselamatan dan batasan penerapannya.">{{ old('measure', $editing?->measure) }}</textarea></label>
      @if($manualForm)
        <div class="rms-note">Salin atau rangkum dari sumber yang sesuai dengan unit. Panduan ini dicatat pengguna; sistem tidak memverifikasi keasliannya secara otomatis.</div>
      @endif
      <label class="field">{{ $manualForm ? 'Referensi manual / pendant dan halaman atau menu *' : 'Referensi pendukung (opsional)' }}<input class="input" name="source_reference" value="{{ old('source_reference', $editing?->source_reference) }}" maxlength="500" @required($manualForm) placeholder="Judul/nomor manual, halaman, atau menu Alarm Detail pada pendant"></label>
      <div class="rms-cm-form-grid">
        <label class="field">Revisi / tanggal dokumen<input class="input" name="source_revision" value="{{ old('source_revision', $editing?->source_revision) }}" maxlength="120"></label>
        <label class="field">Link sumber (opsional)<input class="input" type="url" name="source_url" value="{{ old('source_url', $editing?->source_url) }}" maxlength="1000" placeholder="https://..."></label>
      </div>
      @unless($manualForm)
        <label class="field">Hasil penanganan *<select class="input" name="result" required>@foreach($resultLabels as $key => $label)<option value="{{ $key }}" @selected(old('result', $editing?->result ?? 'not_tested') === $key)>{{ $label }}</option>@endforeach</select></label>
        <label class="field">Bukti / catatan hasil<textarea class="input" name="result_notes" rows="3" maxlength="4000" placeholder="Wajib diisi jika berhasil, membaik, atau tidak berhasil. Jelaskan hasil pemeriksaan; jangan hanya menyebut reset berhasil.">{{ old('result_notes', $editing?->result_notes) }}</textarea></label>
      @endunless
      <fieldset class="rms-cm-scope"><legend>Kapan catatan ini ditampilkan kembali?</legend>
        <div class="rms-cm-form-grid">
          <label class="field">Cakupan robot<select class="input" name="robot_scope" required><option value="robot" @selected(old('robot_scope', $editing ? ($editing->scope_robot_id === null ? 'model' : 'robot') : ($manualForm ? 'model' : 'robot')) === 'robot')>Hanya robot ini</option><option value="model" @selected(old('robot_scope', $editing ? ($editing->scope_robot_id === null ? 'model' : 'robot') : ($manualForm ? 'model' : 'robot')) === 'model')>Robot dengan model controller yang sama</option></select></label>
          <label class="field">Cakupan subcode<select class="input" name="subcode_scope" required><option value="exact" @selected(old('subcode_scope', $editing?->all_subcodes ? 'all' : 'exact') === 'exact')>Subcode ini saja: {{ $context['subcode'] ?? 'tidak tersedia' }}</option><option value="all" @selected(old('subcode_scope', $editing?->all_subcodes ? 'all' : 'exact') === 'all')>Semua subcode pada kode alarm ini</option></select></label>
          <label class="field">Cakupan firmware<select class="input" name="firmware_scope" required><option value="any" @selected(old('firmware_scope', $editing?->firmware_version ? 'current' : 'any') === 'any')>Semua firmware — pastikan panduan berlaku</option>@if($context['firmware_version'] !== null)<option value="current" @selected(old('firmware_scope', $editing?->firmware_version ? 'current' : 'any') === 'current')>Hanya {{ $context['firmware_version'] }}</option>@endif</select></label>
        </div>
        <p class="rms-muted-small">Model {{ $context['controller_model'] }} dan kode {{ $context['alarm_code'] }} tetap menjadi syarat. Pilih semua subcode hanya jika sumber / hasil pemeriksaan mendukungnya.</p>
      </fieldset>
      <input type="hidden" name="is_active" value="0">
      <label class="rms-cm-checkbox"><input type="checkbox" name="is_active" value="1" @checked((bool) old('is_active', $editing?->is_active ?? true))> Catatan aktif untuk dijadikan referensi. Nonaktifkan jika sudah tidak berlaku.</label>
      <p class="rms-muted-small">Nama pengisi dicatat sesuai input. Halaman ini mengikuti akses dashboard tanpa login; pencatatan ditujukan untuk jaringan internal yang dipercaya.</p>
      <div class="rms-cm-toolbar"><button class="btn primary" type="submit">{{ $editing ? 'Simpan perubahan' : 'Simpan countermeasure' }}</button>@if($editing)<a class="btn" href="{{ $baseUrl }}">Batal edit</a>@endif</div>
    </form>
  @endif
</section>
@if($editing && $revisions->isNotEmpty())
<section class="panel rms-section-spaced"><h2>Riwayat perubahan catatan</h2><p class="rms-muted-small">Maksimal 10 versi terakhir. Setiap penyimpanan menyimpan salinan catatan sebelumnya melalui versi tersendiri.</p>
@foreach($revisions as $revision)
  <details class="rms-cm-revision"><summary>Versi {{ $revision->version }} · {{ $revision->edited_by }} · {{ $revision->recorded_at?->timezone('Asia/Jakarta')->format('d M Y H:i:s') }} WIB</summary>
    <dl class="rms-cm-text"><dt>Judul</dt><dd>{{ data_get($revision->payload, 'title') }}</dd><dt>Alarm Content / gejala</dt><dd>{{ data_get($revision->payload, 'alarm_content') ?: '—' }}</dd><dt>Cause / temuan</dt><dd>{{ data_get($revision->payload, 'cause') ?: '—' }}</dd><dt>Countermeasure</dt><dd>{{ data_get($revision->payload, 'measure') }}</dd><dt>Hasil</dt><dd>{{ $resultLabels[data_get($revision->payload, 'result')] ?? '—' }} · {{ data_get($revision->payload, 'result_notes') ?: '—' }}</dd><dt>Sumber</dt><dd>{{ data_get($revision->payload, 'source_reference') ?: '—' }} · {{ data_get($revision->payload, 'source_revision') ?: '—' }}</dd></dl>
  </details>
@endforeach
</section>
@endif
@endsection
