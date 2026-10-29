@extends('rms.layouts.app')
@section('heading','Robot Health Intelligence')
@section('subtitle','Condition monitoring / Anomaly detection / Experimental predictive maintenance — data aktual, tanpa angka simulasi.')
@section('auto_refresh','15')
@section('content')
<link rel="stylesheet" href="{{ asset('rms/css/health-intelligence.css') }}">
<div class="hi-banner"><div><span class="hi-overline">YASKAWA · EQUIPMENT HEALTH</span><h2>Robot Fleet Condition Center</h2><p>Perspektif maintenance: enam axis, alarm teridentifikasi, PM, penyimpangan statistik, dan proyeksi hasil inspeksi.</p></div><span class="hi-label">READ-ONLY DASHBOARD</span></div>
<div class="hi-stat-grid">
  @foreach(['healthy'=>'Healthy','warning'=>'Warning','critical'=>'Critical','unknown'=>'Unknown'] as $k=>$v)
   <div class="hi-stat hi-{{ $k }}"><span>{{ $v }}</span><strong>{{ $counts[$k] }}</strong><small>{{ $k==='unknown'?'Data tidak cukup/stale':'Assessment berbasis evidence' }}</small></div>
  @endforeach
  <div class="hi-stat"><span>Open Anomalies</span><strong>{{ $openAnomalies }}</strong><small>Butuh review teknisi</small></div>
  <div class="hi-stat"><span>Experimental Models</span><strong>{{ $verifiedModels }}</strong><small>Bukan model OEM</small></div>
</div>
<div class="hi-section-title"><h2>Fleet health overview <span>{{ $total }} robots</span></h2><p>Klik robot untuk membuka detail kondisi dan grafik Reference vs Actual.</p></div>
<div class="hi-robot-grid">
@forelse($rows as $row)
 @php($s=$row['state'])
 <a class="hi-robot-card" href="{{ route('rms.health.show',$row['robot']) }}">
  <div class="hi-robot-top"><span class="hi-chip hi-{{ $s['status'] }}">{{ strtoupper($s['status']) }}</span><span class="hi-code">{{ $row['robot']->robot_code }}</span></div>
  <h3>{{ $row['robot']->name }}</h3>
  <p>{{ $row['robot']->line_name ?: 'Line belum diatur' }} · {{ $row['robot']->station_name ?: 'Station belum diatur' }}</p>
  <div class="hi-axis-pills">
   @foreach($s['indicators']['axis'] as $axis=>$metric)
   <span class="hi-axis hi-{{ $metric['state'] }}">{{ $axis }}</span>
   @endforeach
  </div>
  <div class="hi-robot-kpi"><span>Alarm severity</span><strong>{{ strtoupper($s['indicators']['active_alarm_severity']) }}</strong></div>
  <div class="hi-robot-kpi"><span>Torque anomalies</span><strong>{{ $row['anomaly_count'] }} open</strong></div>
  <div class="hi-robot-kpi"><span>Servo operating</span><strong>{{ $s['indicators']['servo_operating_hours']!==null ? number_format($s['indicators']['servo_operating_hours'],1,',','.').' h':'—' }}</strong></div>
  <div class="hi-robot-kpi"><span>Prediksi inspeksi</span><strong>{{ $row['prediction']?->status==='projected' ? number_format($row['prediction']->remaining_calendar_days,1,',','.').' hari*':'—' }}</strong></div>
  <div class="hi-open">Lihat detail robot <span>→</span></div>
 </a>
@empty <div class="hi-empty">Belum ada robot terdaftar. Data akan muncul setelah robot didaftarkan dan mulai mengirim monitoring data.</div> @endforelse
</div>
<div class="hi-disclaimer">* Proyeksi adalah estimasi waktu kalender menuju batas inspeksi berdasarkan trend terverifikasi, bukan prediksi tanggal pasti kerusakan, bukan OEM speed reducer RUL, dan tidak boleh dijadikan perintah otomatis mengoperasikan/stop robot. UNKNOWN tidak berarti sehat.</div>
@endsection
