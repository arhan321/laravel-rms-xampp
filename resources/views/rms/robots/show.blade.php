@extends('rms.layouts.app')
@section('heading', $robot->name)
@section('subtitle', 'Identitas robot, servo monitor, waktu operasi, health, dan home position.')
@section('auto_refresh', 'off')
@section('content')
<div class="back rms-detail-actions"><a class="btn text" href="{{ route('rms.robots.index') }}">← Kembali ke robot monitor</a><a class="btn" href="{{ route('rms.robots.identity', $robot) }}">Edit identitas & home</a><a class="btn" href="{{ route('rms.logs.index', ['robot_id' => $robot->id]) }}">Lihat robot logs</a><a class="btn primary" href="{{ route('rms.robots.cockpit', $robot) }}">Buka Robot Cockpit</a><form method="POST" action="{{ route('rms.robots.destroy', $robot) }}" class="rms-detail-delete-form" onsubmit="return window.confirm('Hapus robot ini beserta seluruh histori terkait secara permanen? Hentikan collector robot ini sebelum menghapus.');">@csrf @method('DELETE')<button class="btn danger" type="submit">Hapus robot</button></form></div>
<section class="panel">
  <div class="between rms-detail-header"><div><span class="eyebrow">{{ $robot->robot_code }} · {{ $robot->model }} · {{ $robot->control_group }}</span><h2>{{ $robot->station_name ?: ($robot->line_name ?: 'Station belum diatur') }}</h2><p class="rms-muted-small">Controller {{ $robot->controller?->model ?? '—' }} · IP {{ $robot->controller?->ip_address ?? '—' }}</p></div>
    <div class="rms-header-status"><span class="badge rms-badge {{ $card['connectivity'] }}" data-rms-connectivity-label>{{ strtoupper($card['connectivity']) }}</span><span class="badge rms-badge {{ $card['health_display']['status'] }}" data-rms-health-label title="{{ $card['health_display']['reason'] }}">HEALTH: {{ $card['health_display']['label'] }}</span><span class="badge rms-badge servo-{{ $card['servo']['state'] }}" data-rms-servo>{{ $card['servo']['label'] }}</span></div>
  </div>
  @include('rms.partials.robot-identity-facts', ['robot' => $robot])
  <div class="detail-facts rms-facts rms-monitor-facts">
    <div class="fact"><label>Telemetry terakhir</label><strong data-rms-last-sampled>{{ $card['last_sampled_at']?->timezone('Asia/Jakarta')->format('d M Y H:i:s') ?? '—' }}</strong></div>
    <div class="fact"><label>Servo Operating Hours</label><strong data-rms-servo-hours title="{{ $card['operating_display']['caption'] }}">{{ $card['operating_display']['text'] }}</strong><div class="rms-muted-small" data-rms-operating-caption>{{ $card['operating_display']['caption'] }}</div></div>
    <div class="fact"><label>Control Power Time</label><strong data-rms-control-hours title="{{ $card['control_power_display']['caption'] }}">{{ $card['control_power_display']['text'] }}</strong><div class="rms-muted-small" data-rms-control-caption>{{ $card['control_power_display']['caption'] }}</div></div>
    <div class="fact"><label>Alarm aktif</label><strong data-rms-alarm-count>{{ $card['active_alarm_count'] ?? 'Belum diketahui' }}</strong></div>
  </div>
</section>
@php($s = $card['telemetry'])
<div class="two-col rms-detail-columns">
  @foreach([['title'=>'Servo Monitor - Torq','kind'=>'torque','ok'=>$s?->torque_ok,'values'=>$s?->torque_axes,'description'=>'Nilai monitor torque controller. Satuan mengikuti verifikasi sumber.'],['title'=>'Servo Monitor','kind'=>'position','ok'=>$s?->position_ok,'values'=>$s?->position_pulses,'description'=>'Posisi enam axis dari controller dalam satuan pulse.']] as $panel)
  <section class="panel"><div class="panel-header"><div><h2>{{ $panel['title'] }}</h2><p>{{ $panel['description'] }}</p></div><span class="badge rms-badge {{ $card['telemetry_fresh'] && $panel['ok'] ? 'connected' : 'unknown' }}">{{ $card['telemetry_fresh'] && $panel['ok'] ? 'READ OK' : 'UNAVAILABLE / STALE' }}</span></div>
    <div class="rms-axis-table">@foreach(['S','L','U','R','B','T'] as $ax)<div class="rms-axis"><span class="axis-name">{{ $ax }}</span><span class="mono">{{ $card['telemetry_fresh'] && $panel['ok'] && isset($panel['values'][$ax]) ? number_format((float)$panel['values'][$ax], $panel['kind'] === 'position' ? 0 : 4, ',', '.') : '—' }}</span></div>@endforeach</div>
  </section>
  @endforeach
</div>
@include('rms.partials.servo-current-panel', ['servoCurrent' => $servoCurrent, 'servoCurrentExternal' => false])
<section class="panel rms-section-spaced">
  <div class="panel-header"><div><h2>Home Position Robot</h2><p data-rms-home-reason>{{ $card['home_position']['reason'] }}</p></div><span class="badge rms-badge home-{{ $card['home_position']['status'] }}" data-rms-home>{{ $card['home_position']['label'] }}</span></div>
  <div class="table-wrap"><table class="data-table"><thead><tr><th>Axis</th><th>Acuan home (pulse)</th><th>Posisi saat ini (pulse)</th><th>Selisih (pulse)</th><th>Toleransi (pulse)</th></tr></thead><tbody data-rms-home-axes>
    @foreach(['S','L','U','R','B','T'] as $ax)@php($a = $card['home_position']['axes'][$ax] ?? [])
    <tr><td>{{ $ax }}</td><td>{{ $a['target'] ?? '—' }}</td><td>{{ $a['current'] ?? '—' }}</td><td>{{ $a['delta'] ?? '—' }}</td><td>{{ $card['home_position']['tolerance_pulses'] ?? '—' }}</td></tr>
    @endforeach
  </tbody></table></div>
  <a class="btn" href="{{ route('rms.robots.identity', $robot) }}#home-position">Atur referensi home</a>
</section>
@include('rms.partials.health-legend')
<section class="panel rms-section-spaced" data-rms-telemetry-history data-rms-robot-id="{{ $robot->id }}"><div class="panel-header"><div><h2>Telemetry history</h2><p>Maksimal 120 sampel terbaru; tidak ada data simulasi.</p></div><span class="rms-muted-small" data-rms-history-count>{{ $telemetryHistory->count() }} record</span></div>
<div class="table-wrap"><table class="data-table"><thead><tr><th>Time (WIB)</th><th>Position</th><th>Torque</th><th>Reading No.</th><th>Servo Monitor - Torq S</th><th>Servo Monitor - Torq L</th></tr></thead><tbody data-rms-history-rows>
@forelse($telemetryHistory as $t)
<tr data-rms-telemetry-id="{{ $t['id'] }}"><td>{{ $t['time_wib'] }}</td><td>@include('rms.partials.read-status',['ok'=>$t['position_ok']])</td><td>@include('rms.partials.read-status',['ok'=>$t['torque_ok']])</td><td>{{ $t['sequence'] ?? '—' }}</td><td>{{ $t['torque_s_label'] }}</td><td>{{ $t['torque_l_label'] }}</td></tr>
@empty
<tr><td colspan="6">Belum ada data</td></tr>
@endforelse
</tbody></table></div>
</section>
<div class="two-col rms-section-spaced">
 <section class="panel"><h2>Operating Time History</h2><p class="rms-muted-small">Nilai hanya ditampilkan sebagai jam setelah formatnya diverifikasi.</p><div class="table-wrap"><table class="data-table"><thead><tr><th>Time</th><th>Type</th><th>Operating Time Value</th></tr></thead><tbody>@forelse($operatingTimeHistory as $t)<tr><td>{{ $t->sampled_at?->timezone('Asia/Jakarta')->format('d M H:i:s') }}</td><td>{{ $t->time_type }}</td><td>{{ $t->value_seconds !== null ? number_format($t->value_seconds / 3600, 2, ',', '.') . ' h' : ($t->value_raw ?: '—') }}</td></tr>@empty<tr><td colspan="3">Belum ada data</td></tr>@endforelse</tbody></table></div></section>
 <section class="panel"><h2>Active Alarm Snapshots</h2><p class="rms-muted-small">Controller Alarm text yang belum seluruhnya teridentifikasi sebagai event.</p><div class="table-wrap"><table class="data-table"><thead><tr><th>Time</th><th>Read</th><th>Controller Alarm Text</th></tr></thead><tbody>@forelse($alarmSnapshots as $a)<tr><td>{{ $a->sampled_at?->timezone('Asia/Jakarta')->format('d M H:i:s') }}</td><td>@include('rms.partials.read-status',['ok'=>$a->read_ok])</td><td class="rms-raw">{{ \Illuminate\Support\Str::limit($a->sdk_text ?: '—',120) }}</td></tr>@empty<tr><td colspan="3">Belum ada data</td></tr>@endforelse</tbody></table></div></section>
</div>
<div class="two-col rms-section-spaced"><section class="panel"><h2>Connection history</h2><div class="table-wrap"><table class="data-table"><thead><tr><th>Time</th><th>State</th><th>Message</th></tr></thead><tbody>@forelse($connectionEvents as $e)<tr><td>{{ $e->occurred_at?->timezone('Asia/Jakarta')->format('d M H:i:s') }}</td><td><span class="badge rms-badge {{ $e->state }}">{{ $e->state }}</span></td><td>{{ $e->message ?: '—' }}</td></tr>@empty<tr><td colspan="3">Belum ada data</td></tr>@endforelse</tbody></table></div></section>
<section class="panel"><h2>Equipment health assessment</h2><p class="rms-health-summary">{{ $latestHealthAssessment?->summary ?: 'Belum ada assessment tervalidasi. Tidak ada prediksi kesehatan yang dibuat oleh website.' }}</p><div class="rms-note">Job Monitoring dan I/O belum memiliki data yang tersedia. Hardware Life Estimation masih dalam tahap investigasi.</div></section></div>
@endsection
