@extends('rms.layouts.app')
@section('heading', 'Edit Data Robot')
@section('subtitle', 'Perbarui data controller dan robot yang sebelumnya dimasukkan melalui form Tambah Robot.')
@push('styles')
<style>
  .rms-identity-edit-intro {margin:16px 0 22px; padding:15px 18px; border:1px solid #d7e7db; border-radius:12px; background:#f6faf7; color:#315345; font-size:12px; line-height:1.65}
  .rms-identity-edit-intro strong {display:block; margin-bottom:3px; color:#1d4933}
  .rms-identity-form .registry-grid {grid-template-columns:repeat(3,minmax(0,1fr)); gap:16px}
  .rms-identity-form .registry-grid .field {min-width:0}
  .rms-identity-form .registry-grid .field input, .rms-identity-form .registry-grid .field textarea {width:100%; min-width:0}
  .rms-identity-form .registry-grid .field-wide {grid-column:1/-1}
  .rms-identity-form .registry-actions {display:flex; flex-wrap:wrap; align-items:center; gap:10px; justify-content:flex-end; margin:20px 0 0}
  .rms-edit-block {margin-bottom:19px}
  .rms-edit-hardware-warning {margin-top:17px; padding:13px 15px; border:1px solid #edd2a4; border-radius:10px; background:#fffaf0; color:#78521a; font-size:12px; line-height:1.6}
  .rms-edit-hardware-warning strong {display:block; color:#8b5114}
  .rms-edit-hardware-warning[hidden] {display:none}
  .rms-edit-confirm {display:flex; align-items:flex-start; gap:10px; margin-top:14px; font-size:12px; color:#385748; line-height:1.55}
  .rms-edit-confirm input {margin-top:3px; flex:0 0 auto; accent-color:#277951}
  .rms-edit-helper {display:block; margin-top:4px; font-size:11px; color:#73847b; line-height:1.55}
  .rms-edit-shared {padding:10px 13px; margin-bottom:18px; border:1px solid #ead4a8; border-radius:9px; background:#fff8ec; color:#76511f; font-size:12px}
  .rms-identity-form [readonly] {background:#f0f2f0; color:#708179; cursor:not-allowed}
  .rms-identity-form fieldset {padding:0; border:0; margin:0; min-width:0}
  .rms-identity-form fieldset[disabled] {opacity:.58}
  @media (max-width:980px) {.rms-identity-form .registry-grid {grid-template-columns:repeat(2,minmax(0,1fr))}}
  @media (max-width:650px) {.rms-identity-form .registry-grid {grid-template-columns:1fr}.rms-identity-form .registry-actions{justify-content:flex-start}}
</style>
@endpush
@section('content')
<div class="back"><a class="btn text" href="{{ route('rms.robots.show', $robot) }}">← Kembali ke detail robot</a><a class="btn text" href="{{ route('rms.robots.index') }}">Robot monitor</a></div>

@if(session('identity_success'))
  <div class="notice rms-success" role="status">✓ {{ session('identity_success') }}</div>
@endif
@if($errors->any())
  <div class="notice warn rms-form-errors" role="alert"><strong>Data belum disimpan.</strong>
    <ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
  </div>
@endif

<div class="rms-identity-edit-intro">
  <strong>Robot ID #{{ $robot->id }} · {{ $robot->robot_code }} · {{ $robot->name }}</strong>
  Form ini mengubah data master robot yang sama, tanpa menghapus telemetry, operating hours, alarm, atau catatan maintenance. Form tidak mengirim perintah gerak maupun Servo ON/OFF ke controller Yaskawa.
</div>

@if(!$identityWriteAllowed)
  <div class="notice warn" role="alert">Pengeditan identitas tanpa login belum diaktifkan untuk environment ini. Hubungi IT/CIT sebelum mengaktifkan akses perubahan di jaringan produksi.</div>
@endif

<form class="rms-identity-form" method="POST" action="{{ route('rms.robots.identity.update', $robot) }}"
      data-robot-registry-form data-probe-url="{{ route('rms.robots.probe') }}" data-rms-edit-identity>
  @csrf
  @method('PUT')
  <fieldset @disabled(!$identityWriteAllowed)>
    <section class="panel rms-edit-block">
      <div class="panel-header"><div><h2>1. Informasi Controller Yaskawa</h2><p>Data controller yang sebelumnya diinput saat Tambah Robot.</p></div><span class="badge sdk">CONTROLLER</span></div>
      @if($controllerShared)
        <div class="rms-edit-shared"><strong>Controller digunakan oleh beberapa robot.</strong> Kolom controller dikunci pada halaman ini agar perubahan pada satu robot tidak memengaruhi robot lain. Data robot tetap dapat diedit.</div>
      @endif
      <div class="registry-grid">
        <label class="field"><span>Controller Code *</span>
          <input class="input" name="controller_code" maxlength="80" value="{{ old('controller_code', $robot->controller?->controller_code) }}" required @readonly($controllerShared)>
        </label>
        <label class="field"><span>Controller Model *</span>
          <input class="input" name="controller_model" maxlength="80" value="{{ old('controller_model', $robot->controller?->model) }}" required @readonly($controllerShared)>
        </label>
        <label class="field registry-ip-field"><span>IP Address Controller *</span>
          <input class="input" name="ip_address" data-ip-input data-original-value="{{ $robot->controller?->ip_address }}" value="{{ old('ip_address', $robot->controller?->ip_address) }}" maxlength="45" inputmode="numeric" placeholder="192.168.255.1" required @readonly($controllerShared)>
          <small class="rms-edit-helper">Mengubah IP memerlukan uji jaringan dan pembaruan konfigurasi collector. Port Yaskawa {{ implode(' / ', $probePorts) }}.</small>
        </label>
        <div class="field registry-probe-field"><span>Uji koneksi IP</span>
          <div class="registry-probe-row"><button type="button" class="btn" data-probe-button @disabled($controllerShared)>Uji IP</button><span class="registry-probe-status" data-probe-status role="status" aria-live="polite">Belum diuji</span></div>
        </div>
        <label class="field"><span>Lokasi / Area</span>
          <input class="input" name="location" maxlength="150" value="{{ old('location', $robot->controller?->location) }}" placeholder="DOJO / Welding 2" @readonly($controllerShared)>
        </label>
        <label class="field field-wide"><span>Catatan Controller</span>
          <textarea class="input" name="notes" maxlength="2000" rows="3" @readonly($controllerShared)>{{ old('notes', $robot->controller?->notes) }}</textarea>
        </label>
      </div>
    </section>

    <section class="panel rms-edit-block" id="robot-master-data">
      <div class="panel-header"><div><h2>2. Identitas Robot</h2><p>Semua kolom ini akan disimpan ke record robot ID #{{ $robot->id }}.</p></div><span class="badge">ROBOT MASTER</span></div>
      <div class="registry-grid">
        <label class="field"><span>Robot Code *</span><input class="input" name="robot_code" maxlength="80" value="{{ old('robot_code', $robot->robot_code) }}" required></label>
        <label class="field"><span>Nama Robot *</span><input class="input" name="name" maxlength="150" value="{{ old('name', $robot->name) }}" required></label>
        <label class="field"><span>Manipulator Model *</span><input class="input" name="model" maxlength="80" value="{{ old('model', $robot->model) }}" required></label>
        <label class="field"><span>Serial Number *</span><input class="input" name="serial_number" maxlength="120" value="{{ old('serial_number', $robot->serial_number) }}" required autocomplete="off"><small class="rms-edit-helper">Jika serial diganti, data referensi home harus diverifikasi ulang.</small></label>
        <label class="field"><span>Type (Nameplate) *</span><input class="input" name="robot_type" maxlength="120" value="{{ old('robot_type', $robot->robot_type) }}" required></label>
        <label class="field"><span>Date (Nameplate) *</span><input class="input" type="month" name="nameplate_date" value="{{ old('nameplate_date', $robot->nameplate_date) }}" required></label>
        <label class="field"><span>Control Group *</span><input class="input" name="control_group" data-original-value="{{ $robot->control_group }}" maxlength="8" pattern="[Rr][1-9][0-9]*" value="{{ old('control_group', $robot->control_group) }}" required><small class="rms-edit-helper">Control group tidak boleh diubah setelah robot memiliki histori.</small></label>
        <label class="field"><span>Jumlah Axis *</span><input class="input" name="axis_count" data-original-value="{{ $robot->axis_count }}" type="number" min="1" max="16" step="1" value="{{ old('axis_count', $robot->axis_count) }}" required><small class="rms-edit-helper">Mengubah jumlah axis robot berhistori tidak diizinkan.</small></label>
        <label class="field"><span>Line Name</span><input class="input" name="line_name" maxlength="100" value="{{ old('line_name', $robot->line_name) }}" placeholder="Welding 2"></label>
        <label class="field"><span>Station Name</span><input class="input" name="station_name" maxlength="100" value="{{ old('station_name', $robot->station_name) }}" placeholder="DOJO"></label>
      </div>
      <div class="rms-edit-hardware-warning" data-rms-hardware-warning hidden>
        <strong>Perubahan jaringan / konfigurasi fisik terdeteksi</strong>
        Pengubahan IP, control group, atau jumlah axis membutuhkan pemeriksaan teknisi. Pada robot yang sudah mempunyai histori, control group dan jumlah axis tidak boleh diubah melalui form ini. Mengubah IP tidak otomatis mengubah konfigurasi C++ collector.
      </div>
      <label class="rms-edit-confirm">
        <input type="checkbox" name="confirm_hardware_change" value="1" data-rms-confirm-hardware @checked(old('confirm_hardware_change'))>
        Saya memahami dampak perubahan IP / control group / jumlah axis dan sudah melakukan koordinasi dengan teknisi bila data tersebut diubah.
      </label>
      <div class="registry-actions">
        <a href="{{ route('rms.robots.show', $robot) }}" class="btn">Batal</a>
        <button type="submit" class="btn primary">✓ Simpan Perubahan Data Robot</button>
      </div>
    </section>
  </fieldset>
</form>

<section class="panel rms-section-spaced" id="home-position">
  <div class="panel-header"><div><h2>3. Referensi Home Position</h2><p>Fitur yang sudah ada tetap dipertahankan. Referensi ini bukan perintah gerak.</p></div><span class="badge">POSITION REFERENCE</span></div>
  <p class="rms-muted-small">Nilai 0 pulse bukan otomatis home position. Acuan harus cocok dengan {{ $robot->control_group }} dan serial robot ini. Mengubah identitas fisik dapat membatalkan referensi lama.</p>
  <form method="POST" action="{{ route('rms.robots.home.update', $robot) }}" data-rms-home-form data-snapshot-url="{{ route('rms.live.snapshot', ['robot_id' => $robot->id]) }}">
    @csrf @method('PUT')
    <input type="hidden" name="captured_sample_id" value="{{ old('captured_sample_id') }}" data-rms-captured-sample>
    <div class="rms-home-inputs">
      @foreach($axes as $axis)
      <label class="field"><span>{{ $axis }} (pulse) *</span><input class="input mono" type="number" step="1" min="-2147483648" max="2147483647" name="home_axes[{{ $axis }}]" data-rms-home-axis="{{ $axis }}" value="{{ old('home_axes.'.$axis, data_get($home, 'axes.'.$axis)) }}" required></label>
      @endforeach
    </div>
    <div class="registry-actions"><button type="button" class="btn" data-rms-capture-home>Ambil posisi saat ini</button><span class="rms-muted-small" data-rms-capture-status role="status">Atau masukkan referensi pendant secara manual.</span></div>
    <div class="registry-grid">
      <label class="field"><span>Toleransi setiap axis (pulse) *</span><input class="input" type="number" name="tolerance_pulses" min="0" max="1000000" step="1" value="{{ old('tolerance_pulses', data_get($home, 'tolerance_pulses', 0)) }}" required><small>0 berarti kecocokan persis.</small></label>
      <label class="field"><span>Diperiksa oleh *</span><input class="input" name="verified_by" maxlength="120" value="{{ old('verified_by', data_get($home, 'verified_by')) }}" required></label>
      <label class="field field-wide"><span>Sumber referensi *</span><input class="input" name="source_reference" maxlength="255" value="{{ old('source_reference', data_get($home, 'source_reference')) }}" placeholder="Nama job dan titik home pada pendant" required></label>
    </div>
    <label class="rms-home-confirm"><input type="checkbox" name="home_verified" value="1" required> Saya telah memeriksa acuan home dan toleransinya untuk robot ini.</label>
    <div class="registry-actions"><button class="btn primary" type="submit">Simpan Referensi Home</button></div>
  </form>
</section>
<script src="{{ asset('rms/js/robot-registry.js') }}?v=20261022-identity-edit" defer></script>
<script src="{{ asset('rms/js/robot-identity.js') }}?v=20261022-identity-edit" defer></script>
@endsection
