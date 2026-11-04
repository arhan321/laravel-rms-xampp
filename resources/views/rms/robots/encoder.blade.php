@extends('rms.layouts.app')
@section('heading', 'Servo Monitor · Encoder Pulse')
@section('subtitle', 'Posisi tiap axis, riwayat pulse, dan kualitas pembacaan dari controller.')
@section('auto_refresh', 'off')
@push('styles')
<link rel="stylesheet" href="{{ asset('rms/css/encoder-monitor.css') }}?v=20261103-1">
@endpush
@section('content')
<div class="rms-encoder" data-encoder-monitor data-live-url="{{ route('rms.robots.encoder.live', ['robot' => $robot, 'limit' => $encoder['limit']]) }}">
  <div class="rms-encoder-actions">
    <a class="btn" href="{{ route('rms.robots.show', $robot) }}">← Detail robot</a>
    <a class="btn" href="{{ route('rms.robots.identity', $robot) }}#home-position">Referensi home</a>
    <a class="btn" href="{{ route('rms.robots.encoder.export', ['robot' => $robot, 'limit' => $encoder['limit']]) }}">Unduh CSV pulse</a>
  </div>
  <section class="panel rms-encoder-summary">
    <div class="panel-header"><div><div class="eyebrow">{{ $robot->robot_code }} · {{ $robot->control_group }} · {{ $robot->model }}</div><h2>{{ $robot->name }}</h2><p>{{ $robot->controller?->model ?? '—' }} · {{ $robot->station_name ?: ($robot->line_name ?: 'Station belum diatur') }}</p></div><span class="rms-encoder-status" data-encoder-status data-state="{{ $encoder['status'] }}" role="status">{{ $encoder['label'] }}</span></div>
    <div class="rms-encoder-meta">
      <div><span>Pembacaan posisi · WIB</span><strong data-encoder-time>{{ $encoder['sampled_wib'] ?? '—' }}</strong></div>
      <div><span>Usia data</span><strong data-encoder-age>{{ $encoder['age_seconds'] !== null ? $encoder['age_seconds'].' detik' : '—' }}</strong></div>
      <div><span>Kode pembacaan SDK</span><strong data-encoder-code>{{ $encoder['read_code'] ?? '—' }}</strong></div>
      <div><span>Kondisi fisik encoder</span><strong>Belum diverifikasi</strong></div>
    </div>
    <p class="rms-encoder-note">Status di atas menunjukkan kualitas pembacaan posisi. Pulse yang terbaca tidak membuktikan kondisi baterai, kabel, atau komponen encoder.</p>
    <p class="rms-encoder-message" data-encoder-message>{{ $encoder['read_message'] ?: '' }}</p>
    <div class="rms-encoder-controls">
      <form method="GET" action="{{ route('rms.robots.encoder', $robot) }}"><label for="encoder-limit">Jumlah sampel</label><select id="encoder-limit" name="limit">@foreach([120,300,600] as $limit)<option value="{{ $limit }}" @selected($encoder['limit'] === $limit)>{{ $limit }} terbaru</option>@endforeach</select><button class="btn" type="submit">Terapkan</button></form>
      <label class="rms-encoder-auto"><input type="checkbox" data-encoder-auto checked> Pembaruan otomatis · 5 detik</label>
      <span data-encoder-connection role="status">Menampilkan data tersimpan.</span>
    </div>
  </section>

  <div class="rms-encoder-axis-grid">
    @foreach($axisNames as $axis)@php($reading = $encoder['axes'][$axis])
    <section class="panel rms-encoder-axis" data-encoder-axis="{{ $axis }}">
      <span class="rms-encoder-axis-name">AXIS {{ $axis }}</span>
      <strong class="rms-encoder-pulse" data-pulse>{{ $reading['pulse'] !== null ? number_format($reading['pulse'], 0, ',', '.') : '—' }}</strong><span class="rms-encoder-unit">pulse</span>
      <div class="rms-encoder-delta">Δ <strong data-delta>{{ $reading['delta'] !== null ? number_format($reading['delta'], 0, ',', '.') : '—' }}</strong><span>pulse antar sampel</span></div>
      <small data-last-pulse>{{ $encoder['status'] === 'stale' && $reading['last_pulse'] !== null ? 'Terakhir tersimpan: '.number_format($reading['last_pulse'], 0, ',', '.') : '' }}</small>
    </section>
    @endforeach
  </div>

  <section class="panel rms-encoder-chart-panel">
    <div class="panel-header"><div><h2>Trend posisi pulse</h2><p>Perubahan posisi terhadap waktu. Nilai negatif tetap ditampilkan.</p></div><div class="rms-encoder-axis-switch" role="group" aria-label="Pilih axis grafik">@foreach($axisNames as $axis)<button type="button" class="btn" data-chart-axis="{{ $axis }}" aria-pressed="{{ $loop->first ? 'true' : 'false' }}">{{ $axis }}</button>@endforeach</div></div>
    <p class="rms-encoder-chart-label" data-encoder-chart-label>Axis S · pulse</p>
    <div class="rms-encoder-chart"><canvas data-encoder-chart role="img" aria-label="Trend posisi axis S dalam pulse"></canvas><p data-encoder-chart-empty hidden>Belum ada sampel pulse valid untuk grafik.</p></div>
    <p class="rms-encoder-note">Δ adalah selisih dua pembacaan berurutan dalam sesi collector yang sama. Garis terputus ketika ada kegagalan baca, pergantian sesi, atau jeda data. Δ bukan position error / following error.</p>
  </section>

  <div class="rms-encoder-two-col">
    <section class="panel"><div class="panel-header"><div><h2>Ringkasan sampel</h2><p data-encoder-window>{{ $encoder['window_note'] }}</p></div></div>
      <div class="rms-encoder-counts"><span><strong data-count-total>{{ $encoder['statistics']['total'] }}</strong> sampel</span><span><strong data-count-valid>{{ $encoder['statistics']['valid'] }}</strong> valid</span><span><strong data-count-invalid>{{ $encoder['statistics']['invalid'] }}</strong> tidak valid</span></div>
      <div class="table-wrap"><table class="data-table"><thead><tr><th>Axis</th><th>Minimum (pulse)</th><th>Maksimum (pulse)</th></tr></thead><tbody>@foreach($axisNames as $axis)<tr data-stat-axis="{{ $axis }}"><td>{{ $axis }}</td><td data-min>{{ $encoder['axes'][$axis]['min'] !== null ? number_format($encoder['axes'][$axis]['min'], 0, ',', '.') : '—' }}</td><td data-max>{{ $encoder['axes'][$axis]['max'] !== null ? number_format($encoder['axes'][$axis]['max'], 0, ',', '.') : '—' }}</td></tr>@endforeach</tbody></table></div>
    </section>
    <section class="panel"><div class="panel-header"><div><h2>Pembanding posisi home</h2><p data-home-reason>{{ $encoder['home']['reason'] }}</p></div><span class="badge" data-home-status>{{ $encoder['home']['label'] }}</span></div>
      <div class="table-wrap"><table class="data-table"><thead><tr><th>Axis</th><th>Acuan (pulse)</th><th>Selisih dari home</th></tr></thead><tbody>@foreach($axisNames as $axis)<tr data-home-axis="{{ $axis }}"><td>{{ $axis }}</td><td data-home-target>{{ $encoder['home']['axes'][$axis]['target'] ?? '—' }}</td><td data-home-delta>{{ $encoder['home']['axes'][$axis]['delta'] ?? '—' }}</td></tr>@endforeach</tbody></table></div>
      <p class="rms-encoder-note">Acuan harus diverifikasi untuk serial robot dan control group yang sama. Posisi 0 pulse tidak otomatis berarti home.</p>
    </section>
  </div>

  <section class="panel"><div class="panel-header"><div><h2>Alarm berlabel encoder</h2><p>Maksimal 20 event tersimpan dengan kata “encoder” pada nama alarm. Cek Alarm Center untuk seluruh alarm dan kelengkapan pembacaannya.</p></div><a class="btn" href="{{ route('rms.alarms.index', ['robot_id' => $robot->id]) }}">Alarm Center →</a></div>
    <div class="table-wrap"><table class="data-table"><thead><tr><th>Waktu WIB</th><th>Kode / subcode</th><th>Nama alarm</th><th>Status tersimpan</th><th>Penanganan</th></tr></thead><tbody data-encoder-alarms>@forelse($encoder['encoder_alarms'] as $alarm)<tr><td>{{ $alarm['time_wib'] }}</td><td>{{ $alarm['code'] }} / {{ $alarm['subcode'] }}</td><td>{{ $alarm['name'] }}</td><td>{{ $alarm['status'] }}</td><td><a href="{{ $alarm['url'] }}">Lihat penanganan →</a></td></tr>@empty<tr><td colspan="5">Belum ada event berlabel encoder yang tersimpan. Ini bukan konfirmasi bahwa encoder bebas gangguan.</td></tr>@endforelse</tbody></table></div>
  </section>
  <section class="panel"><div class="panel-header"><div><h2>Riwayat pulse enam axis</h2><p>Pembacaan terbaru di atas. Sampel gagal atau tidak valid ditampilkan tanpa angka pengganti.</p></div></div>
    <div class="table-wrap rms-encoder-history"><table class="data-table"><thead><tr><th>Waktu WIB</th><th>No.</th><th>Pembacaan</th>@foreach($axisNames as $axis)<th>{{ $axis }} (pulse)</th>@endforeach<th>Kode SDK</th></tr></thead><tbody data-encoder-history>@forelse(array_reverse($encoder['rows']) as $row)<tr><td>{{ $row['time_wib'] }}</td><td>{{ $row['sequence'] ?? '—' }}</td><td>{{ $row['label'] }}</td>@foreach($axisNames as $axis)<td>{{ $row['axes'][$axis] !== null ? number_format($row['axes'][$axis], 0, ',', '.') : '—' }}</td>@endforeach<td>{{ $row['read_code'] ?? '—' }}</td></tr>@empty<tr><td colspan="10">Belum ada sampel dalam 24 jam terakhir.</td></tr>@endforelse</tbody></table></div>
  </section>
  <details class="panel rms-encoder-help"><summary>Yang dapat diperiksa dari halaman ini</summary><p>Pulse menunjukkan posisi yang dilaporkan controller. Riwayat dan selisih antar sampel membantu melihat perubahan posisi serta kualitas pengiriman data. Nilai yang tidak berubah dapat terjadi saat robot diam atau kembali ke posisi yang sama; perubahan besar dapat berasal dari gerakan normal.</p><p>Kesehatan fisik, tegangan baterai, suhu encoder, dan gangguan kabel tidak disimpulkan dari pulse. Gunakan alarm controller, manual sesuai model, dan hasil inspeksi teknisi sebagai bukti tambahan.</p></details>
</div>
<script type="application/json" id="rms-encoder-data">{!! json_encode($encoder, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR) !!}</script>
<script src="{{ asset('rms/js/encoder-monitor.js') }}?v=20261103-1" defer></script>
@endsection
