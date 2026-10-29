@extends('rms.layouts.app')
@section('heading','Robot Health · '.$robot->robot_code)
@section('subtitle','Ringkasan kondisi per robot berdasarkan threshold yang disetujui, alarm, maintenance, dan hasil inspeksi.')
@section('auto_refresh','off')
@section('content')
<link rel="stylesheet" href="{{ asset('rms/css/health-intelligence.css') }}">
<div class="hi-detail-top"><a href="{{ route('rms.health.index') }}">← Fleet Health</a><a href="{{ route('rms.robots.cockpit',$robot) }}">Buka Torque Cockpit →</a></div>
<div class="hi-banner"><div><span class="hi-overline">{{ $robot->line_name ?: 'YASKAWA' }} / {{ $robot->station_name ?: 'Station belum diatur' }}</span><h2>{{ $robot->robot_code }} · {{ $robot->name }}</h2><p>{{ $robot->model }} · {{ $robot->control_group }} · Controller {{ $robot->controller?->model ?? 'Belum diisi' }}</p></div><span class="hi-chip hi-{{ $health['status'] }}">{{ strtoupper($health['status']) }}</span></div>
<div class="hi-note"><strong>Health assessment:</strong> {{ $health['summary'] }}<div class="hi-subnote">Sumber: Controller Telemetry, Approved Threshold, Alarm Snapshot, dan PM terkonfigurasi. Tidak ada perintah gerak/stop robot.</div></div>
<div class="hi-stat-grid">
 <div class="hi-stat"><span>Axis NG</span><strong>{{ count($health['indicators']['ng_axes']) }}</strong><small>{{ implode(', ',$health['indicators']['ng_axes']) ?: 'Belum ada NG teridentifikasi' }}</small></div>
 <div class="hi-stat"><span>Servo operating</span><strong>{{ $health['indicators']['servo_operating_hours']!==null?number_format($health['indicators']['servo_operating_hours'],1,',','.').' h':'—' }}</strong><small>Nilai yang sudah dikonversi & diverifikasi</small></div>
 <div class="hi-stat"><span>Active alarm</span><strong>{{ $health['indicators']['alarm_count']??'—' }}</strong><small>{{ strtoupper($health['indicators']['active_alarm_severity']) }} (latest alarm reading: {{ $health['indicators']['alarm_data_current']?'yes':'no' }})</small></div>
 <div class="hi-stat"><span>PM due</span><strong>{{ count($health['indicators']['due_maintenance']) }}</strong><small>Jadwal maintenance terkonfigurasi</small></div>
 </div>
<div class="hi-columns">
 <section class="hi-panel"><div class="hi-panel-head"><h3>Health Indicators — All Axes</h3><span>Motor Torque</span></div>
  <div class="hi-axis-detail">
   @foreach($health['indicators']['axis'] as $axis=>$d)
   <div><b>{{ $axis }}</b><span>{{ $d['value']===null?'—':number_format($d['value'],4,',','.') }}</span><span class="hi-chip hi-{{ $d['state'] }}">{{ strtoupper($d['state']) }}</span></div>
   @endforeach
  </div>
  <p class="hi-subnote">NG berarti ada event warning/critical dari approved threshold. Unknown berarti belum dapat dinilai; tidak disetarakan normal.</p>
 </section>
 <section class="hi-panel"><div class="hi-panel-head"><h3>Maintenance · Lifetime to service</h3><span>PM terverifikasi</span></div>
 @forelse($health['indicators']['maintenance'] as $pm)
  <div class="hi-data-row"><div><strong>{{ $pm['title'] }}</strong><small>{{ $pm['basis'] }}</small></div><strong>{{ $pm['lifetime_to_service_hours']!==null?number_format($pm['lifetime_to_service_hours'],1,',','.').' jam':($pm['due_at']?\Carbon\Carbon::parse($pm['due_at'])->timezone('Asia/Jakarta')->format('d M Y'):'—') }}</strong><span class="hi-chip {{ $pm['due']?'hi-warning':'hi-muted' }}">{{ $pm['due']?'DUE':'PLANNED' }}</span></div>
 @empty <p class="hi-empty">Belum ada maintenance plan aktif. Lifetime tidak dapat dihitung.</p> @endforelse
 </section>
</div>
<section class="hi-panel hi-reducer-lifetime"><div class="hi-panel-head"><h3>OEM-verified Speed Reducer Life — All Axes</h3><span>Hours from verified source, not calculated by RMS</span></div>
 <div class="hi-lifetime-list">
 @foreach($health['indicators']['reducer_verified_life'] as $axis=>$life)
  <div class="hi-lifetime-cell"><strong>{{ $axis }}</strong><span>{{ $life ? number_format($life['hours'],1,',','.').' h':'—' }}</span><small>{{ $life ? (\Carbon\Carbon::parse($life['measured_at'])->timezone('Asia/Jakarta')->format('d M Y')).' · '.($life['reference']??$life['source']) : 'Belum ada bukti OEM/inspeksi terverifikasi' }}</small></div>
 @endforeach
 </div><p class="hi-subnote">Pembacaan lama tidak boleh dianggap sebagai Remaining Life hari ini tanpa verifikasi ulang. Data ini tidak dihitung dari Motor Torque.</p>
</section>
<div class="hi-columns hi-middle">
 <section class="hi-panel"><div class="hi-panel-head"><h3>Reference vs Actual — All Axes</h3><span>RMS trend comparison · non-OEM</span></div>
  <div class="hi-graph-tools"><label for="hi-axis-picker">Axis</label><select id="hi-axis-picker">@foreach(['S','L','U','R','B','T'] as $a)<option value="{{ $a }}">{{ $a }}</option>@endforeach</select><span id="hi-baseline-caption">Loading chart…</span></div>
  <canvas id="hi-trend-chart" role="img" aria-label="Motor Torque history and reference line by axis" width="900" height="370"></canvas>
  <p class="hi-subnote">Garis pembanding hanya muncul jika tersedia minimal {{ config('rms_health.minimum_baseline_samples',40) }} sampel historis pada job yang sama. Data bukan torsi Nm atau %.</p>
 </section>
 <section class="hi-panel"><div class="hi-panel-head"><h3>Predictive insights</h3><span>Experimental inspection trends</span></div>
  @foreach(['S','L','U','R','B','T'] as $axis)
   @php($p=$predictions->get($axis))
   <div class="hi-predict-row"><strong>{{ $axis }}</strong><span>{{ $p && $p->status==='projected' ? round($p->remaining_calendar_days,1).' calendar days' : 'Insufficient verified inspection data' }}</span><small>{{ $p && $p->status==='projected' ? 'Based on verified inspection trend; technician review required' : 'Tambahkan hasil inspeksi terverifikasi untuk menilai trend' }}</small></div>
  @endforeach
  <p class="hi-subnote">Proyeksi menuju threshold kondisi dari inspeksi terverifikasi, bukan perkiraan pasti waktu rusak. Tanpa rekaman inspeksi cukup: tidak ada prediksi.</p>
 </section>
</div>
<div class="hi-columns">
 <section class="hi-panel"><div class="hi-panel-head"><h3>Technician anomaly journal</h3><span>{{ $anomalies->count() }} latest events</span></div>
  <div class="hi-table-scroller"><table class="hi-table"><thead><tr><th>Time WIB</th><th>Axis</th><th>Job</th><th>Score</th><th>Severity</th><th>Review</th></tr></thead><tbody>
  @forelse($anomalies as $a)
  <tr><td>{{ $a->started_at?->timezone('Asia/Jakarta')->format('d M H:i') }}</td><td>{{ $a->axis }}</td><td>{{ $a->job_key==='__unspecified__'?'Job unknown':$a->job_key }}</td><td>{{ number_format((float)$a->score,1) }}</td><td>{{ strtoupper($a->severity) }}</td><td>{{ $a->feedback?:$a->status }}</td></tr>
  @empty<tr><td colspan="6">No deviation event recorded. Lack of events does not prove the absence of damage.</td></tr>@endforelse
  </tbody></table></div>
 </section>
 <section class="hi-panel"><div class="hi-panel-head"><h3>Root-cause candidates & recommendation</h3><span>Technician verification required</span></div>
  <p>Untuk penyimpangan torque, kandidat yang perlu diuji secara manual: perubahan job/beban, kondisi tool/gripper, hambatan mekanik axis, kecocokan nilai dengan pendant, dan kondisi speed reducer. Ini <strong>bukan diagnosis akar masalah</strong>.</p>
  <p>Jika terdapat alarm, prioritaskan kode dan dokumentasi Yaskawa resmi. Jika PM due, tindak lanjuti jadwal. Jika pola anomali dikonfirmasi, catat bukti pada Maintenance Journal.</p>
  <p class="hi-subnote">Hasil review teknisi dipakai untuk memperbaiki penilaian berikutnya dan tidak dianggap sebagai data sensor baru.</p>
 </section>
</div>
<script>window.__RMS_HEALTH_CHART__ = {{ \Illuminate\Support\Js::from(['series'=>$series,'baselines'=>$baselines->map(fn($b)=>['axis'=>$b->axis,'job_key'=>$b->job_key,'median'=>(float)$b->median_value,'mad'=>(float)$b->mad_value])->values()]) }};</script>
<script src="{{ asset('rms/js/health-intelligence.js') }}?v=20261015-2315" defer></script>
@endsection
