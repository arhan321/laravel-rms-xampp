@extends('rms.layouts.app')
@section('heading', 'Data & connections')
@section('subtitle', 'Monitoring kualitas komunikasi antara controller Yaskawa dan RMS.')
@section('auto_refresh', '30')
@section('content')
<div class="stats">
@include('rms.partials.metric',['label'=>'Connected','value'=>$connectedCount,'description'=>'Pembacaan segar tersedia'])
@include('rms.partials.metric',['label'=>'Offline','value'=>$offlineCount,'description'=>'Disconnect tercatat'])
@include('rms.partials.metric',['label'=>'Stale','value'=>$staleCount,'description'=>'Telemetry tidak segar'])
@include('rms.partials.metric',['label'=>'Unknown','value'=>$unknownCount,'description'=>'Tidak cukup bukti koneksi'])
</div>
<section class="panel"><div class="panel-header"><h2>Monitoring data flow</h2><span class="rms-muted-small">Alur data menuju Yaskawa Cockpit</span></div><div class="rms-pipeline">@foreach($architecture as $step)<span class="rms-pipeline-step">{{ $step }}</span>@if(!$loop->last)<span class="rms-pipe-arrow">→</span>@endif @endforeach</div><div class="rms-note">Halaman ini hanya menampilkan status komunikasi dan riwayat koneksi. Bukan kontrol start/stop robot.</div></section>
<section class="panel rms-section-spaced"><div class="panel-header"><h2>Robot connection matrix</h2><span>{{ $robots->count() }} registered</span></div><div class="table-wrap"><table class="data-table"><thead><tr><th>Robot</th><th>Controller IP</th><th>Connectivity</th><th>Last sample</th><th>Event</th></tr></thead><tbody>@forelse($robots as $item)<tr><td><a class="rms-link" href="{{ route('rms.robots.show',$item['robot']) }}">{{ $item['robot']->robot_code }}</a></td><td>{{ $item['controller']?->ip_address ?? '—' }}</td><td><span class="badge rms-badge {{ $item['connectivity'] }}">{{ strtoupper($item['connectivity']) }}</span></td><td>{{ $item['last_sampled_at']?->timezone('Asia/Jakarta')->format('d M H:i:s') ?? '—' }}</td><td>{{ $item['connection_event']?->state ?? '—' }}</td></tr>@empty<tr><td colspan="5">Belum ada robot</td></tr>@endforelse</tbody></table></div></section>
<section class="panel rms-section-spaced"><div class="panel-header"><h2>Connection event log</h2><span class="rms-muted-small">{{ $events->total() }} event</span></div><div class="table-wrap"><table class="data-table"><thead><tr><th>Time (WIB)</th><th>Robot</th><th>Status</th><th>Message</th></tr></thead><tbody>@forelse($events as $e)<tr><td>{{ $e->occurred_at?->timezone('Asia/Jakarta')->format('d M Y H:i:s') }}</td><td>{{ $e->robot?->robot_code ?? '—' }}</td><td><span class="badge rms-badge {{ $e->state }}">{{ strtoupper($e->state) }}</span></td><td>{{ $e->message ?: '—' }}</td></tr>@empty<tr><td colspan="4">Belum ada connection events</td></tr>@endforelse</tbody></table></div><div class="rms-pagination">{{ $events->links() }}</div></section>
@endsection
