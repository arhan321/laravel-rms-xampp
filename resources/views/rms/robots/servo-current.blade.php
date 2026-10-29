@extends('rms.layouts.app')
@section('heading', 'Parameter Estimasi Arus Servo')
@section('subtitle', 'Pengaturan motor per axis untuk perhitungan torque menjadi estimasi Arms.')
@section('auto_refresh', 'off')
@section('content')
<div class="sc-page-actions"><a class="btn" href="{{ route('rms.robots.show', $robot) }}">← Detail robot</a><a class="btn" href="{{ route('rms.robots.cockpit', $robot) }}">Robot Cockpit</a></div>
@include('rms.partials.robot-identity-facts', ['robot' => $robot])
@if(session('servo_current_success'))<div class="sc-notice sc-success" role="status">{{ session('servo_current_success') }}</div>@endif
@if($errors->any())<div class="sc-notice sc-error" role="alert"><strong>Parameter belum disimpan.</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
@if(!$writeAllowed)<div class="sc-notice sc-error">Form saat ini hanya dapat dibaca. Hak perubahan mengikuti pengaturan akses edit identitas robot.</div>@endif
@include('rms.partials.servo-current-panel', ['servoCurrent' => $servoCurrent, 'servoCurrentExternal' => false])
<section class="sc-config-intro">
  <span class="sc-eyebrow">{{ $robot->robot_code }} / {{ $robot->control_group }}</span><h2>Profil motor S · L · U · R · B · T</h2>
  <p>Isi sesuai motor yang benar-benar terpasang dan referensi manual/datasheet. Semua field angka sengaja kosong pada profil baru. Nilai 100% harus mengacu pada torsi nominal poros motor; Kt harus memakai Nm/Arms.</p>
  <p>Simpan satu axis pada satu waktu. Profil berlaku untuk sampel baru sejak disimpan; riwayat sebelumnya tidak dihitung ulang dengan parameter baru. Konfirmasi parameter adalah pencocokan internal, bukan sertifikasi akurasi atau pengesahan Yaskawa.</p>
</section>
<div class="sc-profile-list">
@foreach($axes as $axis)
@php
  $profile = $profiles[$axis] ?? [];
  $submitted = old('axis') === $axis;
  $field = fn (string $key, mixed $default = '') => $submitted ? old($key, $default) : ($profile[$key] ?? $default);
@endphp
<details class="sc-profile" id="motor-{{ $axis }}" @if($submitted || !$profile) open @endif>
  <summary><span class="sc-axis-letter">{{ $axis }}</span><span><strong>Motor axis {{ $axis }}</strong><small>{{ $profile['motor_model'] ?? 'Parameter motor belum diisi' }}</small></span><span class="sc-badge">{{ ($profile['enabled'] ?? false) ? 'AKTIF' : 'NONAKTIF' }}</span><span class="sc-expand" aria-hidden="true">⌄</span></summary>
  <form method="POST" action="{{ route('rms.robots.servo-current.update', $robot) }}" class="sc-profile-form">
    @csrf @method('PUT')
    <input type="hidden" name="axis" value="{{ $axis }}">
    <input type="hidden" name="profile_version" value="{{ $profile['id'] ?? '' }}">
    <fieldset @disabled(!$writeAllowed)>
    <div class="sc-form-grid">
      <label>Kode/model motor<input name="motor_model" maxlength="120" required value="{{ $field('motor_model') }}" placeholder="Kode motor dari parts list / nameplate"></label>
      <label>Torsi nominal motor (Nm)<input type="number" name="nominal_torque_nm" min="0.00000001" max="10000000" step="any" required value="{{ $field('nominal_torque_nm') }}" placeholder="Sesuai datasheet motor"></label>
      <label>Konstanta torsi Kt (Nm/Arms)<input type="number" name="torque_constant_nm_per_arms" min="0.00000001" max="10000000" step="any" required value="{{ $field('torque_constant_nm_per_arms') }}" placeholder="Sesuai datasheet; bukan Nm/Apeak"></label>
      <label>Label satuan input collector<select name="input_unit"><option value="sdk_raw" @selected($field('input_unit', 'sdk_raw') === 'sdk_raw')>sdk_raw (collector saat ini)</option><option value="percent" @selected($field('input_unit') === 'percent')>percent</option></select></label>
      <label>Skala (% per unit input)<input type="number" name="torque_percent_per_unit" min="0.00000001" max="10000" step="any" required value="{{ $field('torque_percent_per_unit') }}" placeholder="Isi setelah dibandingkan dengan pendant"><small>Jika 40 pada collector sama dengan 40% di pendant, skalanya 1. Pastikan terlebih dahulu.</small></label>
      <label>Diperiksa oleh<input name="checked_by" maxlength="120" required value="{{ $field('checked_by') }}" placeholder="Nama pemeriksa parameter"></label>
      <label class="sc-wide">Sumber referensi<input name="source_reference" maxlength="500" required value="{{ $field('source_reference') }}" placeholder="Judul manual / datasheet, revisi, halaman, serta bukti pencocokan torque"></label>
      <label class="sc-wide">Catatan<textarea name="notes" maxlength="2000" rows="2" placeholder="Catatan identifikasi motor atau metode pencocokan">{{ $field('notes') }}</textarea></label>
    </div>
    <label class="sc-check"><input type="checkbox" name="basis_confirmed" value="1" @checked($submitted && old('basis_confirmed'))><span>Saya telah mencocokkan motor, acuan 100% torque, skala input, dan satuan Kt Nm/Arms dengan referensi di atas.</span></label>
    <label class="sc-check"><input type="checkbox" name="enabled" value="1" @checked($submitted ? old('enabled') : ($profile['enabled'] ?? false))><span>Aktifkan perhitungan estimasi axis {{ $axis }}.</span></label>
    <div class="sc-form-footer"><span>Rumus ini menghasilkan estimasi; nilai torque asli tetap disimpan.</span><button class="btn primary" type="submit">Simpan profil axis {{ $axis }}</button></div>
    </fieldset>
    @if($profile)<p class="sc-profile-stamp">Profil tersimpan: {{ \Carbon\CarbonImmutable::parse($profile['effective_from'])->timezone('Asia/Jakarta')->format('d M Y H:i:s') }} WIB · {{ $profile['checked_by'] }} · {{ $profile['source_reference'] }}</p>@endif
  </form>
</details>
@endforeach
</div>
@endsection
