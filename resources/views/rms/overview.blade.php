@extends('rms.layouts.app')
@section('body_class', 'rms-overview-page')
@section('heading', 'Robot Maintenance System')
@section('subtitle', 'Plant overview · Condition based maintenance')
@section('auto_refresh', 'off')
@push('styles')
<link rel="stylesheet" href="{{ asset('rms/css/plant-overview.css') }}?v=20261101-5">
@endpush
@section('content')
<div class="rms-plant-overview" data-rms-plant-overview>
 <div class="rms-p-board-heading">
  <div><span class="rms-p-eyebrow">CENTRALIZED ROBOT MONITORING</span><h2>General condition <span>/ seluruh area</span></h2></div>
  <div class="rms-p-actions"><a class="rms-p-button" href="{{ route('rms.robot-groups.index') }}">Kelola grup</a><a class="rms-p-button" href="{{ route('rms.robots.index', ['group'=>'all']) }}">Seluruh robot ↗</a></div>
 </div>
 <div class="rms-p-top">
  <section class="rms-p-fleet" aria-label="Ringkasan seluruh robot">
   <img class="rms-yaskawa-image" src="{{ asset('rms/images/yaskawa-robot.png') }}" alt="" width="105" height="130">
   <div class="stats rms-p-kpis">
    <div><strong class="stat-value">{{ $summary['total_robots'] }} <small>robot</small></strong><span>Terdaftar di database</span></div>
    <div><strong class="stat-value">{{ $summary['connected_collectors'] }} <small>fresh</small></strong><span>Telemetry terbaru</span></div>
    <div><strong class="stat-value">{{ $summary['identified_active_alarms'] }} <small>alarm</small></strong><span>Event aktif teridentifikasi</span></div>
    <div><strong class="stat-value">{{ $summary['open_recommendations'] }} <small>open</small></strong><span>Rekomendasi maintenance</span></div>
   </div>
  </section>
  <section class="rms-p-condition" aria-labelledby="rms-p-condition-heading">
   <h3 id="rms-p-condition-heading">Condition based maintenance <span>· per grup</span></h3>
   <div class="rms-p-area-grid">
   @forelse($plantOverview['groups'] as $group)
    <a class="rms-p-area" data-rms-plant-group-summary="{{ $group['key'] }}" href="{{ route('rms.robots.index', ['group'=>$group['key']]) }}">
     <div class="rms-p-area-title"><span>{{ $group['label'] }}</span><b aria-hidden="true">↗</b></div>
     <div class="rms-p-area-metrics"><div><span>Maintenance</span><strong data-rms-plant-stat="maintenance">{{ $group['maintenance'] }}</strong><small>robot / rekomendasi open</small></div><div><span>Health alert</span><strong data-rms-plant-stat="attention">{{ $group['attention'] }}</strong><small>warning / upnormal</small></div></div>
     <div class="rms-p-area-bottom"><span><b data-rms-plant-stat="registered">{{ $group['registered'] }}</b> terdaftar</span><span><b data-rms-plant-stat="fresh">{{ $group['fresh'] }}</b> fresh</span></div>
    </a>
   @empty
    <div class="rms-p-empty">Belum ada grup robot. Buat grup melalui <a href="{{ route('rms.robot-groups.index') }}">Kelola grup</a>.</div>
   @endforelse
   </div>
  </section>
  <section class="rms-p-alarms" aria-labelledby="rms-p-alarm-heading">
   <div class="rms-p-panel-heading"><h3 id="rms-p-alarm-heading">Latest alarm</h3><a href="{{ route('rms.alarms.index') }}">Alarm center ↗</a></div>
   <p class="rms-p-muted">Histori event teridentifikasi · WIB</p>
   <div class="rms-p-alarm-list" data-rms-plant-alarms>
    @forelse($plantOverview['latest_alarms'] as $alarm)
     <a class="rms-p-alarm-row" href="{{ $alarm['url'] }}"><strong>{{ $alarm['robot_code'] }}</strong><span>{{ $alarm['name'] }}<small>{{ $alarm['time'] }} · {{ strtoupper($alarm['status']) }}</small></span></a>
    @empty
     <p class="rms-p-empty">Belum ada event alarm tersimpan.</p>
    @endforelse
   </div>
  </section>
 </div>
 <div class="rms-p-stream rms-status-notice"><span class="rms-p-stream-dot" aria-hidden="true">●</span><span><strong>{{ $summary['connected_collectors'] }} dari {{ $summary['total_robots'] }} robot menerima telemetry segar</strong> · {{ $summary['stale_or_unknown'] }} stale/unknown · {{ $summary['offline_collectors'] }} offline</span><span class="right">Telemetry fresh ≠ robot RUN</span></div>
 <div class="rms-p-board-heading rms-p-performance-heading"><div><span class="rms-p-eyebrow">ROBOT PERFORMANCE OVERVIEW</span><h2>Kondisi robot per grup</h2></div><div class="rms-p-legend" aria-label="Legenda indikator axis"><span><i data-axis-state="normal"></i>Normal</span><span><i data-axis-state="warning"></i>Warning</span><span><i data-axis-state="critical"></i>Critical</span><span><i data-axis-state="unavailable"></i>Unknown</span><span><i data-axis-state="not_configured"></i>Belum diatur</span></div></div>
 <div class="rms-p-lines">
 @forelse($plantOverview['groups'] as $group)
  <section class="rms-p-line" data-rms-plant-group="{{ $group['key'] }}">
   <div class="rms-p-line-heading"><div><span>{{ $group['area_label'] ?: 'GRUP ROBOT' }}</span><h3>{{ $group['label'] }} @if($group['pilot'])<small>PILOT</small>@endif</h3></div><a href="{{ route('rms.robots.index', ['group'=>$group['key']]) }}" aria-label="Buka robot {{ $group['label'] }}">↗</a></div>
   @if($group['description'])<p class="rms-p-line-description">{{ $group['description'] }}</p>@endif
   <div class="rms-p-workcell">
    @foreach($group['robots'] as $robot)
     <a class="rms-p-robot" data-rms-plant-robot="{{ $robot['id'] }}" data-group-key="{{ $group['key'] }}" data-health="{{ $robot['health'] }}" href="{{ $robot['url'] }}">
      <img class="rms-yaskawa-image" src="{{ asset('rms/images/yaskawa-robot.png') }}" alt="" width="67" height="85" loading="lazy">
      <div class="rms-p-robot-info"><div class="rms-p-robot-title"><strong>{{ $robot['code'] }}</strong><span data-rms-p-health>{{ strtoupper($robot['health']) }}</span></div><span class="rms-p-robot-name" title="{{ $robot['name'] }}">{{ $robot['name'] }}</span><div class="rms-p-axes" aria-label="Status torque per axis">@foreach($robot['axes'] as $axis=>$state)<span data-rms-plant-axis="{{ $axis }}" data-axis-state="{{ $state }}" title="{{ $axis }}: {{ $state }}">{{ $axis }}</span>@endforeach</div><div class="rms-p-robot-state"><span data-rms-p-connection>{{ strtoupper($robot['connectivity']) }}</span><span data-rms-p-servo>{{ $robot['servo'] }}</span></div><div class="rms-p-robot-pm"><span data-rms-p-pm>{{ $robot['open_recommendations'] }} rekomendasi open</span><span data-rms-p-hours>{{ $robot['operating_hours'] }}</span></div></div>
     </a>
    @endforeach
    <div class="rms-p-cell"><img class="rms-yaskawa-image" src="{{ asset('rms/images/yaskawa-robot.png') }}" alt="Ilustrasi robot Yaskawa" width="420" height="180" loading="lazy"><span>ILUSTRASI ROBOT · {{ $group['label'] }}</span></div>
    @if(count($group['robots']) === 0)<p class="rms-p-empty">Belum ada robot terdaftar di kelompok ini.</p>@endif
   </div>
   <div class="rms-p-line-footer"><span>{{ $group['registered'] }} robot terdaftar @if($group['planned_robots'] !== null) / {{ $group['planned_robots'] }} rencana @endif</span><a href="{{ route('rms.robots.index', ['group'=>$group['key']]) }}">Lihat grup →</a></div>
  </section>
 @empty
  <div class="rms-p-empty rms-p-line">Belum ada grup robot. Atur kelompok dan anggota melalui Kelola grup.</div>
 @endforelse
 </div>
 <div class="rms-p-data-notice" data-rms-plant-reload hidden>Daftar atau penempatan robot berubah. <a href="{{ route('rms.overview') }}">Muat ulang overview</a> untuk menampilkan susunan terbaru.</div>
 <div class="rms-p-bottom">
  <section class="rms-p-bottom-panel"><div class="rms-p-panel-heading"><h3>Jadwal maintenance</h3><a href="{{ route('rms.maintenance.index') }}">Buka jadwal ↗</a></div>@forelse($attention['upcoming_maintenance'] as $plan)<div class="rms-p-schedule"><strong>{{ $plan->robot?->robot_code }} · {{ $plan->title }}</strong><span>{{ $plan->next_due_at?->timezone('Asia/Jakarta')->format('d M Y H:i') }} WIB</span></div>@empty<p class="rms-p-empty">Belum ada maintenance plan aktif dengan tanggal due.</p>@endforelse</section>
  <section class="rms-p-bottom-panel rms-p-reading-guide"><h3>Dasar pembacaan</h3><p>Maintenance = robot dengan rekomendasi berstatus open. Health alert = WARNING / UPNORMAL dari assessment yang segar.</p><p>Axis berwarna hanya jika pembacaan torque tersedia dan threshold disetujui. Nilai belum tersedia tetap UNKNOWN; belum ada perhitungan lifetime to service.</p><p>Gambar robot adalah ilustrasi; jumlah robot dan keanggotaan mengikuti database.</p></section>
 </div>
</div>
@endsection
