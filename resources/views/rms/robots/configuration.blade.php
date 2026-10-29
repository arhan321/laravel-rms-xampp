@extends('rms.layouts.app')
@section('heading', 'Konfigurasi Robot')
@section('subtitle', 'Configure Torque Threshold dan Speed Reducer Evidence untuk kebutuhan monitoring teknisi.')
@section('auto_refresh', 'off')

@push('styles')
<link rel="stylesheet" href="{{ asset('rms/css/configuration.css') }}?v=20261015-2255">
<link rel="stylesheet" href="{{ asset('rms/css/technician-guide.css') }}?v=20261015-2255">
@endpush

@section('content')
@php
  $selectedRobot = $robot;
  $approvedCount = $rules->filter(fn ($rule) => $rule->isApproved())->count();
  $latestReducerByAxis = $reducerMeasurements->groupBy('axis')->map(fn ($rows) => $rows->first());
  $reducerAxisCount = $latestReducerByAxis->filter()->count();
  $reducerSubmitted = old('_form') === 'reducer';
@endphp

<div class="rms-config-page">
  <section class="config-hero">
    <div class="config-hero-copy">
      <span class="config-kicker">ROBOT CONFIGURATION</span>
      <h2>{{ $selectedRobot->name }}</h2>
      <div class="config-robot-meta">
        <span>{{ $selectedRobot->robot_code }}</span>
        <span>{{ $selectedRobot->model }}</span>
        <span>{{ $selectedRobot->control_group }}</span>
        <span>IP {{ $selectedRobot->controller?->ip_address ?? 'belum didaftarkan' }}</span>
      </div>
      <p>Pengaturan ini hanya menentukan cara RMS menilai data. Halaman ini tidak mengirim perintah, tidak mengubah parameter, dan tidak menghentikan controller Yaskawa.</p>
    </div>
    <div class="config-hero-actions">
      <a class="config-button config-button-light" href="{{ route('rms.robots.cockpit', $selectedRobot) }}">← Kembali ke cockpit</a>
      <a class="config-button config-button-ghost" href="{{ route('rms.robots.show', $selectedRobot) }}">Robot detail</a>
    </div>
  </section>

  @if(session('config_success'))
    <div class="config-alert config-alert-success" role="status">
      <span class="config-alert-icon">✓</span>
      <div><strong>Berhasil disimpan</strong><p>{{ session('config_success') }}</p></div>
    </div>
  @endif

  @if($errors->any())
    <div class="config-alert config-alert-error" role="alert">
      <span class="config-alert-icon">!</span>
      <div><strong>Data belum dapat disimpan</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
    </div>
  @endif

  <section class="config-overview" aria-label="Ringkasan konfigurasi">
    <div class="config-stat">
      <span class="config-stat-icon">T</span>
      <div><small>Approved Threshold</small><strong>{{ $approvedCount }} <em>/ 6 axis</em></strong><p>{{ $approvedCount === 6 ? 'Semua axis siap dimonitor' : (6 - $approvedCount).' axis belum memiliki threshold aktif' }}</p></div>
    </div>
    <div class="config-stat">
      <span class="config-stat-icon reducer">R</span>
      <div><small>Reducer Evidence</small><strong>{{ $reducerAxisCount }} <em>/ 6 axis</em></strong><p>Axis dengan evidence pemeriksaan terverifikasi</p></div>
    </div>
    <div class="config-guide">
      <strong>Motor Torque Value</strong>
      <p>Nilai torsi dibaca dari controller Yaskawa untuk setiap axis. Tampilan angkanya dapat berbeda dari pendant karena skala atau pembulatan. Sebelum diverifikasi, jangan menganggap nilainya sebagai Nm atau persen.</p>
    </div>
  </section>

  <nav class="config-jump" aria-label="Bagian konfigurasi">
    <a href="#technical-guide"><span>01</span> Technician Guide</a>
    <a href="#threshold-config"><span>02</span> Torque Threshold</a>
    <a href="#reducer-config"><span>03</span> Speed Reducer Evidence</a>
  </nav>

  @include('rms.partials.technician-guide', ['guideId' => 'technical-guide', 'guideOpen' => true])

  <section class="config-section" id="threshold-config">
    <header class="config-section-head">
      <div>
        <span class="config-kicker">TORQUE HEALTH RULE</span>
        <h2>Torque Threshold per Axis</h2>
        <p>RMS membandingkan Torque Magnitude |value|. Warning harus lebih kecil daripada Critical.</p>
      </div>
      <span class="config-count">{{ $approvedCount }} / 6 APPROVED</span>
    </header>

    <div class="config-explainer">
      <div><span class="config-dot normal"></span><strong>Normal</strong><small>Torque Magnitude masih di bawah Warning</small></div>
      <div><span class="config-dot warning"></span><strong>Warning</strong><small>Threshold awal terlewati; teknisi perlu memeriksa</small></div>
      <div><span class="config-dot critical"></span><strong>Critical</strong><small>Critical Threshold RMS terlewati; bukan alarm YRC</small></div>
      <div><span class="config-dot hysteresis"></span><strong>Hysteresis</strong><small>Recovery margin agar status tidak berubah bolak-balik</small></div>
    </div>

    <div class="config-axis-grid">
      @foreach($axes as $axis)
        @php
          $rule = $rules->get($axis);
          $submittedAxis = old('_form') === 'threshold' && old('axis') === $axis;
          $enabled = $submittedAxis ? (bool) old('enabled') : (bool) ($rule?->isApproved());
          $warningValue = $submittedAxis ? old('warning_magnitude') : ($rule?->warning_magnitude ?? '');
          $criticalValue = $submittedAxis ? old('critical_magnitude') : ($rule?->critical_magnitude ?? '');
          $hysteresisValue = $submittedAxis ? old('clear_hysteresis_percent', 5) : ($rule?->clear_hysteresis_percent ?? 5);
          $sourceValue = $submittedAxis ? old('source_reference') : ($rule?->source_reference ?? '');
          $approverValue = $submittedAxis ? old('approved_by') : ($rule?->approved_by ?? '');
        @endphp
        <details class="config-axis-card {{ $rule?->isApproved() ? 'is-approved' : 'needs-config' }}" {{ $submittedAxis ? 'open' : '' }}>
          <summary>
            <div class="config-axis-title">
              <span class="config-axis-letter">{{ $axis }}</span>
              <div><strong>Axis {{ $axis }}</strong><small>Torque Magnitude · controller monitor value</small></div>
            </div>
            <div class="config-axis-values">
              <span><small>Warning</small><b>{{ $rule?->isApproved() ? number_format((float) $rule->warning_magnitude, 3, ',', '.') : '—' }}</b></span>
              <span><small>Critical</small><b>{{ $rule?->isApproved() ? number_format((float) $rule->critical_magnitude, 3, ',', '.') : '—' }}</b></span>
              <span class="config-status {{ $rule?->isApproved() ? 'approved' : 'pending' }}">{{ $rule?->isApproved() ? 'APPROVED' : 'SETUP' }}</span>
              <span class="config-chevron" aria-hidden="true">⌄</span>
            </div>
          </summary>

          <form method="POST" action="{{ route('rms.robots.configuration.threshold', $selectedRobot) }}" class="config-axis-form">
            @csrf
            @method('PUT')
            <input type="hidden" name="_form" value="threshold">
            <input type="hidden" name="axis" value="{{ $axis }}">

            <div class="config-form-group config-form-group-thresholds">
              <label class="config-field">
                <span>Warning Threshold (|value|)</span>
                <input type="number" name="warning_magnitude" step="0.000001" min="0.000001" required value="{{ $warningValue }}" placeholder="Contoh: 100">
                <small>Jika 3 pembacaan berturut-turut mencapai nilai ini, RMS membuka Warning Event.</small>
              </label>
              <label class="config-field">
                <span>Critical Threshold (|value|)</span>
                <input type="number" name="critical_magnitude" step="0.000001" min="0.000001" required value="{{ $criticalValue }}" placeholder="Contoh: 150">
                <small>Wajib lebih besar daripada Warning. Ini threshold RMS, bukan alarm controller.</small>
              </label>
              <label class="config-field">
                <span>Hysteresis</span>
                <div class="config-input-suffix"><input type="number" name="clear_hysteresis_percent" step="0.01" min="0" max="30" required value="{{ $hysteresisValue }}"><span>%</span></div>
                <small>Contoh: Warning 100 dan 5% berarti event baru pulih setelah 3 sampel berada di bawah 95.</small>
              </label>
            </div>

            <div class="config-form-group config-form-group-evidence">
              <label class="config-field config-field-wide">
                <span>Source Reference</span>
                <input type="text" name="source_reference" maxlength="250" value="{{ $sourceValue }}" placeholder="Contoh: baseline DOJO 15/10/2026 atau dokumen YRC1000 halaman ...">
                <small>Tuliskan baseline, hasil pembandingan pendant, laporan inspeksi, atau referensi OEM. Wajib jika batas diaktifkan.</small>
              </label>
              <label class="config-field">
                <span>Approved By</span>
                <input type="text" name="approved_by" maxlength="120" value="{{ $approverValue }}" placeholder="Nama teknisi atau pemeriksa">
              </label>
              <div class="config-checks">
                <label><input type="checkbox" name="confirm_unit" value="1" {{ $submittedAxis && old('confirm_unit') ? 'checked' : '' }}><span><b>Verify Torque Value</b><small>Nilai sudah dibandingkan dengan pendant/referensi dan layak digunakan untuk robot serta axis ini.</small></span></label>
                <label><input type="checkbox" name="enabled" value="1" {{ $enabled ? 'checked' : '' }}><span><b>Enable Threshold</b><small>RMS mulai memakai threshold ini untuk menentukan Normal, Warning, dan Critical.</small></span></label>
              </div>
            </div>

            <div class="config-form-actions">
              <p>Pengaturan ini hanya tersimpan pada RMS; program dan parameter controller tidak berubah.</p>
              <button type="submit" class="config-button config-button-primary">Save Threshold · Axis {{ $axis }}</button>
            </div>
          </form>
        </details>
      @endforeach
    </div>
  </section>

  <section class="config-section" id="reducer-config">
    <header class="config-section-head">
      <div>
        <span class="config-kicker">VERIFIED COMPONENT EVIDENCE</span>
        <h2>Speed Reducer Evidence</h2>
        <p>Catat hasil inspeksi teknisi atau data OEM. RMS tidak menghitung Remaining Life secara otomatis dari Motor Torque.</p>
      </div>
      <span class="config-count">{{ $reducerAxisCount }} / 6 AXIS</span>
    </header>

    <div class="config-reducer-layout">
      <form method="POST" action="{{ route('rms.robots.configuration.reducer', $selectedRobot) }}" class="config-reducer-form">
        @csrf
        <input type="hidden" name="_form" value="reducer">

        <div class="config-form-block">
          <div class="config-form-block-head"><span>1</span><div><strong>Measurement Data</strong><small>Salin hanya nilai yang tersedia dari laporan atau hasil ukur yang dapat ditelusuri.</small></div></div>
          <div class="config-form-grid">
            <label class="config-field"><span>Axis</span><select name="axis" required>@foreach($axes as $axis)<option value="{{ $axis }}" @selected(($reducerSubmitted ? old('axis', 'S') : 'S') === $axis)>Axis {{ $axis }}</option>@endforeach</select></label>
            <label class="config-field"><span>Measured At (WIB)</span><input type="datetime-local" name="measured_at" value="{{ $reducerSubmitted ? old('measured_at') : '' }}"><small>Kosong berarti waktu sekarang.</small></label>
            <label class="config-field"><span>Condition Value</span><input type="number" name="condition_value" step="0.000001" value="{{ $reducerSubmitted ? old('condition_value') : '' }}" placeholder="Contoh: 0,12"><small>Isi sesuai angka pada alat ukur atau laporan sumber.</small></label>
            <label class="config-field"><span>Condition Unit</span><input type="text" name="condition_unit" maxlength="50" value="{{ $reducerSubmitted ? old('condition_unit') : '' }}" placeholder="Contoh: mm, °C, hour"><small>Wajib jika Condition Value diisi.</small></label>
            <label class="config-field"><span>Remaining Life</span><div class="config-input-suffix"><input type="number" name="remaining_life_hours" step="0.01" min="0" value="{{ $reducerSubmitted ? old('remaining_life_hours') : '' }}" placeholder="Jika tertulis di sumber"><span>hour</span></div><small>Jangan mengisi perkiraan sendiri; gunakan angka dari OEM/laporan terverifikasi.</small></label>
            <label class="config-field"><span>Source Type</span><select name="source_type" required><option value="oem_report" @selected(!$reducerSubmitted || old('source_type') === 'oem_report')>OEM Report</option><option value="oem_cockpit" @selected($reducerSubmitted && old('source_type') === 'oem_cockpit')>Yaskawa/OEM Cockpit</option><option value="technician_measurement" @selected($reducerSubmitted && old('source_type') === 'technician_measurement')>Technician Measurement</option></select></label>
          </div>
        </div>

        <div class="config-form-block">
          <div class="config-form-block-head"><span>2</span><div><strong>Threshold & Verification</strong><small>Pastikan angka, satuan, dokumen, serta pemeriksanya dapat ditelusuri kembali.</small></div></div>
          <div class="config-form-grid">
            <label class="config-field"><span>Lower Threshold</span><input type="number" name="lower_threshold" step="0.000001" value="{{ $reducerSubmitted ? old('lower_threshold') : '' }}" placeholder="Optional"><small>Isi hanya jika batas bawah tercantum pada sumber.</small></label>
            <label class="config-field"><span>Upper Threshold</span><input type="number" name="upper_threshold" step="0.000001" value="{{ $reducerSubmitted ? old('upper_threshold') : '' }}" placeholder="Optional"><small>Harus lebih besar daripada Lower Threshold.</small></label>
            <label class="config-field config-field-wide"><span>Evidence Reference</span><input type="text" name="source_reference" maxlength="255" required value="{{ $reducerSubmitted ? old('source_reference') : '' }}" placeholder="Contoh: laporan inspeksi SR-001 atau dokumen OEM halaman 12"></label>
            <label class="config-field"><span>Verified By</span><input type="text" name="verified_by" maxlength="120" required value="{{ $reducerSubmitted ? old('verified_by') : '' }}" placeholder="Nama teknisi atau pemeriksa"></label>
            <label class="config-field config-field-wide"><span>Inspection Notes</span><textarea name="notes" rows="3" maxlength="2000" placeholder="Temuan inspeksi, kondisi reducer, atau catatan sumber...">{{ $reducerSubmitted ? old('notes') : '' }}</textarea></label>
          </div>
          <label class="config-verify"><input type="checkbox" name="verified" value="1" required {{ $reducerSubmitted && old('verified') ? 'checked' : '' }}><span><b>Saya memastikan data ini sama dengan bukti pemeriksaan/OEM yang disebutkan.</b><small>RMS akan menandainya sebagai data terverifikasi internal, bukan sertifikasi dari Yaskawa.</small></span></label>
        </div>

        <div class="config-form-actions">
          <p>Minimal isi nilai hasil pemeriksaan atau sisa umur yang tertulis pada sumber.</p>
          <button type="submit" class="config-button config-button-primary">Save Reducer Evidence</button>
        </div>
      </form>

      <aside class="config-latest">
        <div class="config-latest-head"><div><span class="config-kicker">LATEST RECORD</span><h3>Evidence per Axis</h3></div><span>{{ $reducerAxisCount }}/6</span></div>
        <div class="config-latest-list">
          @foreach($axes as $axis)
            @php($measurement = $latestReducerByAxis->get($axis))
            <div class="config-latest-item {{ $measurement ? 'has-data' : '' }}">
              <span class="config-axis-letter">{{ $axis }}</span>
              <div>
                <strong>{{ $measurement ? ($measurement->condition_value !== null ? $measurement->condition_value.' '.$measurement->condition_unit : 'Remaining Life recorded') : 'Belum ada evidence' }}</strong>
                <small>{{ $measurement?->measured_at?->timezone('Asia/Jakarta')->format('d M Y H:i') ?? 'Menunggu hasil inspeksi' }}</small>
              </div>
              @if($measurement)<span class="config-status approved">VERIFIED</span>@else<span class="config-status pending">EMPTY</span>@endif
            </div>
          @endforeach
        </div>
      </aside>
    </div>
  </section>
</div>
@endsection
