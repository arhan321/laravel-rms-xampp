@extends('rms.layouts.app')
@section('heading', 'Tambah Robot')
@section('subtitle', 'Daftarkan controller Yaskawa dan robot control group dari dashboard.')
@section('content')
<div class="back"><a class="btn text" href="{{ route('rms.robots.index') }}">← Kembali ke robot monitor</a></div>

@if($errors->any())
  <div class="notice warn rms-form-errors" role="alert">
    <strong>Robot belum disimpan.</strong>
    <ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
  </div>
@endif

<div class="notice blue rms-registry-note">
  <strong>Pemeriksaan wajib:</strong> RMS akan menguji IP dari komputer monitoring sebelum menyimpan. Uji ini tidak mengirim perintah ke robot dan hanya memastikan alamat dapat dijangkau. Identitas controller Yaskawa dikonfirmasi saat data controller pertama diterima.
</div>

<form method="POST" action="{{ route('rms.robots.store') }}" class="rms-registry-form" data-robot-registry-form data-probe-url="{{ route('rms.robots.probe') }}">
  @csrf
  <section class="panel">
    <div class="panel-header"><div><h2>Controller Yaskawa</h2><p>Alamat controller yang akan digunakan oleh RMS.</p></div><span class="badge sdk">READ-ONLY</span></div>
    <div class="registry-grid">
      <label class="field"><span>Controller code *</span><input class="input" name="controller_code" value="{{ old('controller_code') }}" placeholder="YRC1000_DOJO_R1" required></label>
      <label class="field"><span>Controller model *</span><input class="input" name="controller_model" value="{{ old('controller_model', 'YRC1000') }}" required></label>
      <label class="field registry-ip-field"><span>IP address *</span><input class="input" name="ip_address" data-ip-input value="{{ old('ip_address', '192.168.255.1') }}" inputmode="numeric" placeholder="192.168.255.1" required><small>Port komunikasi controller: {{ implode(' dan ', $probePorts) }}. Tombol Uji IP hanya memeriksa jangkauan jaringan.</small></label>
      <div class="field registry-probe-field"><span>Pemeriksaan jaringan</span><div class="registry-probe-row"><button type="button" class="btn" data-probe-button>Uji IP</button><span class="registry-probe-status" data-probe-status role="status" aria-live="polite">Belum diuji</span></div></div>
      <label class="field"><span>Lokasi / line</span><input class="input" name="location" value="{{ old('location') }}" placeholder="Welding 2 / DOJO"></label>
    </div>
  </section>

  <section class="panel">
    <div class="panel-header"><div><h2>Identitas robot</h2><p>Data ini digunakan sebagai identitas robot pada RMS.</p></div></div>
    <div class="registry-grid">
      <label class="field"><span>Robot code *</span><input class="input" name="robot_code" value="{{ old('robot_code') }}" placeholder="DOJO_SP210_R1" required></label>
      <label class="field"><span>Nama robot *</span><input class="input" name="name" value="{{ old('name') }}" placeholder="Yaskawa SP210 - DOJO" required></label>
      <label class="field"><span>Manipulator model *</span><input class="input" name="model" value="{{ old('model', 'SP210') }}" required></label>
      <label class="field"><span>Serial Number *</span><input class="input" name="serial_number" value="{{ old('serial_number') }}" maxlength="120" required autocomplete="off"></label>
      <label class="field"><span>Type (nameplate) *</span><input class="input" name="robot_type" value="{{ old('robot_type') }}" maxlength="120" required></label>
      <label class="field"><span>Date (nameplate) *</span><input class="input" type="month" name="nameplate_date" value="{{ old('nameplate_date') }}" required></label>
      <label class="field"><span>Control group *</span><input class="input" name="control_group" value="{{ old('control_group', 'R1') }}" pattern="R[1-9][0-9]*" required><small>Contoh: R1, R2, R3.</small></label>
      <label class="field"><span>Jumlah axis *</span><input class="input" type="number" name="axis_count" value="{{ old('axis_count', 6) }}" min="1" max="16" required></label>
      <label class="field"><span>Line name</span><input class="input" name="line_name" value="{{ old('line_name') }}" placeholder="Welding Line 1"></label>
      <label class="field"><span>Station name</span><input class="input" name="station_name" value="{{ old('station_name') }}" placeholder="DOJO"></label>
      <label class="field field-wide"><span>Catatan</span><textarea class="input" name="notes" rows="3" placeholder="Keterangan tambahan controller atau jaringan">{{ old('notes') }}</textarea></label>
    </div>
  </section>

  <div class="registry-actions"><a href="{{ route('rms.robots.index') }}" class="btn">Batal</a><button type="submit" class="btn primary">Simpan robot</button></div>
</form>
<script src="{{ asset('rms/js/robot-registry.js') }}?v=20261015-2315" defer></script>
@endsection
