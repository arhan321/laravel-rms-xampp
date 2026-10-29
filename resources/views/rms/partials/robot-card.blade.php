@php
  $r = $item['robot']; $c = $item['controller']; $connectivity = $item['connectivity'];
  $health = $item['health_display']; $servo = $item['servo']; $home = $item['home_position'];
  $deleteMessage = "Hapus robot {$r->name} ({$r->robot_code})? Semua histori terkait dihapus permanen. Pastikan pengiriman data robot ini sudah dihentikan.";
@endphp
<div class="rms-robot-card-shell">
  <a class="robot-card rms-robot-card {{ $connectivity }}" data-rms-robot-id="{{ $r->id }}" href="{{ route('rms.robots.show', $r) }}">
    <div class="between"><div><div class="robot-title">{{ $r->name }}</div><div class="robot-caption">{{ $r->robot_code }} · {{ $r->station_name ?: ($r->line_name ?: 'Station belum diatur') }}</div></div><span class="badge rms-badge {{ $connectivity }}" data-rms-connectivity-label>{{ strtoupper($connectivity) }}</span></div>
    <div class="robot-center"><div class="rms-robot-icon" aria-hidden="true"><svg viewBox="0 0 160 150" role="img" aria-label="Robot industri" xmlns="http://www.w3.org/2000/svg"><ellipse cx="78" cy="135" rx="60" ry="7" fill="#e8eeea"/><path d="M33 130h53l-6-20H40z" fill="#d4e7dc" stroke="#6f9581" stroke-width="2"/><path d="M48 114V91h29v23" fill="#cfdfd4" stroke="#6f9581" stroke-width="3"/><path d="M52 92 40 53l24-8 18 43" fill="#d3e6db" stroke="#6f9581" stroke-width="3"/><path d="M48 48 99 25l12 24-52 23" fill="#d3e6db" stroke="#6f9581" stroke-width="3"/><path d="m104 35 30 30-13 14-28-33" fill="#d3e6db" stroke="#6f9581" stroke-width="3"/><path d="m131 72 13 12-10 13" stroke="#6f9581" stroke-width="6" fill="none"/><circle cx="58" cy="91" r="13" fill="#fff" stroke="#6f9581" stroke-width="5"/><circle cx="51" cy="52" r="12" fill="#fff" stroke="#6f9581" stroke-width="5"/><circle cx="102" cy="37" r="11" fill="#fff" stroke="#6f9581" stroke-width="5"/></svg></div><div class="robot-readout">
      <span class="small">CONTROLLER</span><strong>{{ $c?->model ?? '—' }}</strong>
      <span class="small">IP ADDRESS</span><strong>{{ $c?->ip_address ?? '—' }}</strong>
      <span class="small">HEALTH</span><strong class="badge rms-badge {{ $health['status'] }}" data-rms-health-label title="{{ $health['reason'] }}">{{ $health['label'] }}</strong>
    </div></div>
    <div class="rms-card-status-row"><span class="badge rms-badge servo-{{ $servo['state'] }}" data-rms-servo title="{{ $servo['reason'] }}">{{ $servo['label'] }}</span><span class="badge rms-badge home-{{ $home['status'] }}" data-rms-home title="{{ $home['reason'] }}">{{ $home['label'] }}</span></div>
    @include('rms.partials.robot-identity-facts', ['robot' => $r])
    <div class="rms-counter-pair">
      <div><span>Servo Operating Hours</span><strong data-rms-servo-hours title="{{ $item['operating_display']['caption'] }}">{{ $item['operating_display']['text'] }}</strong></div>
      <div><span>Control Power Time</span><strong data-rms-control-hours title="{{ $item['control_power_display']['caption'] }}">{{ $item['control_power_display']['text'] }}</strong></div>
    </div>
    <div class="robot-bottom"><div class="between"><span>Last telemetry</span><strong>{{ $item['last_sampled_at']?->timezone('Asia/Jakarta')->format('H:i:s') ?? 'Belum ada' }}</strong></div><div class="between"><span data-rms-alarm-count>Alarm aktif: {{ $item['active_alarm_count'] ?? '—' }}</span><span>Home sesuai acuan</span></div></div>
  </a>
  <div class="rms-robot-card-actions" style="display:flex;justify-content:flex-end;align-items:center;gap:9px;flex-wrap:wrap;margin-top:8px;">
    <a class="btn small" href="{{ route('rms.robots.identity', $r) }}">✎ Edit data robot</a>
    <form method="POST" action="{{ route('rms.robots.destroy', $r) }}" class="rms-robot-delete-form" onsubmit="return window.confirm(@js($deleteMessage));">
      @csrf @method('DELETE')<button type="submit" class="btn danger small">Hapus robot</button>
    </form>
  </div>
</div>
