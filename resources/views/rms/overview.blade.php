@extends('rms.layouts.app')
@section('heading', 'Line overview')
@section('subtitle', 'Monitoring peralatan robot Yaskawa — telemetry, health, alarm, dan maintenance.')
{{-- Realtime pages are synchronized by Reverb + JSON reconciliation; no full HTML replacement. --}}
@section('auto_refresh', 'off')
@section('content')
<div class="notice rms-status-notice"><span>◉</span><span><strong>{{ $summary['connected_collectors'] }} dari {{ $summary['total_robots'] }} robot menerima telemetry segar</strong> · {{ $summary['stale_or_unknown'] }} stale/unknown · {{ $summary['offline_collectors'] }} offline</span><span class="right">Sample segar ≠ RUN</span></div>
<div class="stats">
 @include('rms.partials.metric',['label'=>'Robot terdaftar','value'=>$summary['total_robots'],'unit'=>'unit','description'=>$summary['active_robots'].' aktif di registry'])
 @include('rms.partials.metric',['label'=>'Telemetry connected','value'=>$summary['connected_collectors'],'unit'=>'robot','description'=>'Pembacaan valid & terbaru'])
 @include('rms.partials.metric',['label'=>'Alarm aktif teridentifikasi','value'=>$summary['identified_active_alarms'],'unit'=>'event','description'=>'Hanya structured alarm'])
 @include('rms.partials.metric',['label'=>'Maintenance recommendation','value'=>$summary['open_recommendations'],'unit'=>'open','description'=>'Rekomendasi tersimpan'])
</div>
@include('rms.partials.health-legend')
<section><div class="section-heading"><h2>Robot overview <span class="count">{{ $robots->count() }}</span></h2><div class="flex gap"><a class="btn" href="{{ route('rms.robots.index') }}">Seluruh robot →</a><a class="btn primary" href="{{ route('rms.robots.create') }}">＋ Tambah robot</a></div></div>
 @forelse($robots->chunk(4) as $group)
 <div class="robot-grid rms-robot-grid">@foreach($group as $item) @include('rms.partials.robot-card',['item'=>$item]) @endforeach</div>
 @empty @include('rms.partials.no-data',['title'=>'Belum ada robot terdaftar','message'=>'Tambahkan controller dan robot terlebih dahulu sebelum monitoring data diterima.']) @endforelse
</section>
<div class="two-col overview-lower">
 <section class="panel"><div class="panel-header"><div><h2>Perlu perhatian</h2><p>Alarm aktif dan gangguan pembacaan yang diketahui</p></div><a href="{{ route('rms.alarms.index') }}" class="btn text">Alarm center →</a></div>
 @forelse($attention['alarms'] as $a)
 <div class="event"><div class="event-icon">!</div><div class="event-content"><strong>{{ $a->robot?->robot_code ?? 'Unknown robot' }} · {{ $a->alarm_name ?: ($a->alarm_code ?: 'Alarm teridentifikasi') }}</strong><p>{{ strtoupper($a->severity ?: 'unknown') }} · {{ $a->started_at?->timezone('Asia/Jakarta')->format('d M Y H:i:s') }}</p></div></div>
 @empty <p class="rms-muted-small">Belum ada structured active alarm. Ini bukan bukti bahwa robot bebas alarm jika pembacaan belum tersedia.</p> @endforelse
 @foreach($attention['connections'] as $item)
 <div class="event"><div class="event-icon blue">↯</div><div class="event-content"><strong>{{ $item['robot']->robot_code }} · Koneksi {{ $item['connectivity'] }}</strong><p>Last telemetry: {{ $item['last_sampled_at']?->timezone('Asia/Jakarta')->format('d M H:i:s') ?? 'Tidak tersedia' }}</p></div></div>
 @endforeach
 </section>
 <section class="panel"><div class="panel-header"><div><h2>Jadwal maintenance</h2><p>Upcoming berdasarkan perencanaan yang dicatat</p></div><a href="{{ route('rms.maintenance.index') }}" class="btn text">Buka jadwal →</a></div>
 @forelse($attention['upcoming_maintenance'] as $plan)
 <div class="event"><div class="event-icon green">▦</div><div class="event-content"><strong>{{ $plan->robot?->robot_code }} · {{ $plan->title }}</strong><p>Due: {{ $plan->next_due_at?->timezone('Asia/Jakarta')->format('d M Y H:i') ?? 'Belum ditentukan' }} WIB</p></div></div>
 @empty @include('rms.partials.no-data',['message'=>'Belum ada maintenance plan aktif dengan tanggal due.']) @endforelse
 </section>
</div>
<div class="rms-note">Status servo berasal dari pembacaan controller. Health NORMAL memerlukan assessment lengkap dan segar. Home position mengikuti acuan yang diperiksa teknisi.</div>
@endsection
