@once
@push('styles')
<link rel="stylesheet" href="{{ asset('rms/css/servo-current.css') }}?v=20261028-1">
@endpush
@endonce
<section class="sc-panel" data-servo-current data-servo-current-external="{{ ($servoCurrentExternal ?? false) ? '1' : '0' }}" data-servo-current-url="{{ route('rms.robots.servo-current.live', $robot) }}" aria-label="Estimasi arus enam motor servo">
  <header class="sc-header">
    <div><span class="sc-eyebrow">SERVO MONITOR - CURRENT</span><h2>Estimasi arus motor servo <span class="sc-badge">ESTIMASI</span></h2><p>Dihitung dari torque dan parameter motor masing-masing axis. Satuan Arms; bukan pembacaan arus aktual.</p></div>
    <a class="btn" href="{{ route('rms.robots.servo-current', $robot) }}">Atur parameter motor</a>
  </header>
  <div class="sc-meta"><span data-sc-sampled>Torque terakhir: {{ $servoCurrent['sampled_wib'] ?? 'Belum tersedia' }} WIB</span><span data-sc-live>Menunggu pembaruan</span></div>
  <div class="sc-axis-grid">
    @foreach(\App\Services\Rms\ServoCurrentEstimator::AXES as $axis)
    @php($reading = $servoCurrent['axes'][$axis])
    <article class="sc-axis-card" data-sc-axis="{{ $axis }}" data-state="{{ $reading['status'] }}">
      <div class="sc-axis-top"><span class="sc-axis-letter">{{ $axis }}</span><span class="sc-axis-state" data-sc-state>{{ $reading['status'] === 'estimated' ? 'ESTIMASI' : 'BELUM TERSEDIA' }}</span></div>
      <div class="sc-reading"><strong data-sc-amps>{{ $reading['amps'] === null ? '—' : number_format($reading['amps'], 2, ',', '.') }}</strong><span>Arms</span></div>
      <div class="sc-torque" data-sc-torque>Torque: {{ $reading['torque_percent'] === null ? '—' : number_format($reading['torque_percent'], 2, ',', '.').'%' }}</div>
      <p data-sc-reason>{{ $reading['reason'] }}</p>
    </article>
    @endforeach
  </div>
  <div class="sc-chart-header"><div><h3>Tren estimasi arus per axis</h3><p>{{ $servoCurrentHistoryLabel ?? 'Maksimal 300 sampel terbaru dalam 24 jam terakhir.' }}</p></div>
    <div class="sc-legend" role="group" aria-label="Axis pada grafik arus">
      @foreach(\App\Services\Rms\ServoCurrentEstimator::AXES as $axis)<button type="button" data-sc-toggle="{{ $axis }}" aria-pressed="true"><span class="sc-dot sc-dot-{{ $axis }}"></span>{{ $axis }}</button>@endforeach
    </div>
  </div>
  <div class="sc-chart-frame"><canvas data-sc-chart role="img" aria-label="Grafik estimasi arus servo enam axis dalam Arms"></canvas><p class="sc-empty" data-sc-empty>Grafik mulai terisi setelah profil motor aktif dan sampel torque baru diterima.</p></div>
  <p class="sc-footnote">Rumus: |torque × skala| / 100 × torsi nominal / Kt. Nilai disimpan di database bersama sampel torque dan salinan parameter motor. Grafik membaca nilai tersimpan; perubahan profil tidak menghitung ulang histori. Jeda data dan pergantian profil tidak disambung menjadi garis. Akurasi belum diverifikasi dengan alat ukur.</p>
</section>
<script>window.__RMS_SERVO_CURRENT__ = {{ \Illuminate\Support\Js::from($servoCurrent) }};</script>
<script src="{{ asset('rms/js/servo-current.js') }}?v=20261028-1" defer></script>
