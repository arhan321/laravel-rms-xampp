@extends('rms.layouts.app')
@section('heading', 'Robot Cockpit')
@section('subtitle', 'Servo Monitor - Torq, Position, Threshold, dan Speed Reducer monitoring untuk kebutuhan teknisi.')
@section('auto_refresh', 'off')
@push('styles')
<link rel="stylesheet" href="{{ asset('rms/css/cockpit.css') }}?v=20261020-cockpit-evidence">
<link rel="stylesheet" href="{{ asset('rms/css/technician-guide.css') }}?v=20261015-2255">
@endpush
@section('content')
@php
$axes = ['S','L','U','R','B','T'];
$latestTime = $latest?->sampled_at?->timezone('Asia/Jakarta')->format('d M Y H:i:s');
$isDemo = \Illuminate\Support\Str::startsWith($robot->robot_code, 'DEMO_') || \Illuminate\Support\Str::contains($robot->name, '[DEMO]');
$axisStateLabels = [
  'normal' => 'NORMAL',
  'warning' => 'WARNING',
  'critical' => 'CRITICAL',
  'not_configured' => 'NOT CONFIGURED',
  'unavailable' => 'UNAVAILABLE',
];
@endphp
<div class="cp-root" data-cockpit>
  <div class="cp-toolbar">
    <div class="cp-links"><a href="{{ route('rms.robots.show',$robot) }}">← Robot detail</a><a href="{{ route('rms.robots.index') }}">Robot monitor</a><a href="{{ route('rms.robots.configuration', $robot) }}">⚙ Konfigurasi</a><a href="{{ route('rms.alarms.index', ['robot_id' => $robot->id]) }}#controller-alarm-history">Alarm history</a><a href="{{ route('rms.analytics.index') }}">RUN teramati</a><a href="{{ route('rms.maintenance.index', ['robot_id' => $robot->id]) }}">PM komponen</a>@if(Route::has('rms.logs.index'))<a href="{{ route('rms.logs.index', ['robot_id'=>$robot->id]) }}">Log robot</a>@endif</div>
    <div class="cp-tags">
      @if($isDemo)<span class="cp-pill cp-demo">DATA DEMO</span>@endif
      <span class="cp-pill {{ $fresh?'cp-ok':'cp-muted' }}">{{ $fresh?'TELEMETRY FRESH':'TELEMETRY STALE / UNKNOWN' }}</span>
    </div>
  </div>
  <div class="cp-head">
    <div><div class="cp-eyebrow">YASKAWA EQUIPMENT HEALTH / READ-ONLY</div><h2>{{ $robot->name }}</h2><p>{{ $robot->robot_code }} · {{ $robot->model }} · {{ $robot->control_group }} · {{ $robot->station_name ?: 'Station belum diatur' }}</p></div>
    <div class="cp-controller"><div>CONTROLLER</div><strong>{{ $robot->controller?->model ?? '—' }}</strong><small>{{ $robot->controller?->ip_address ?? 'IP belum didaftarkan' }}</small></div>
  </div>
  @include('rms.partials.robot-identity-facts', ['robot' => $robot])
  <a class="btn" href="{{ route('rms.robots.identity', $robot) }}">Edit identitas & home position</a>
  <div class="cp-metrics">
    <div><span>Last Telemetry</span><strong>{{ $latestTime ?? 'Belum tersedia' }}</strong><small>Waktu Indonesia Barat (WIB)</small></div>
    <div><span>Servo Operating Hours</span><strong data-rms-operating-status="{{ $operatingDisplay['status'] }}" title="{{ $operatingDisplay['caption'] }}">{{ $operatingDisplay['text'] }}</strong><small data-rms-operating-caption>{{ $operatingDisplay['caption'] }}</small></div>
    <div><span>Open Threshold Events</span><strong>{{ $openEvents->count() }}</strong><small>RMS monitoring event; bukan Controller Alarm</small></div>
    <div><span>Identified Active Alarms</span><strong>{{ $alarms->count() }}</strong><small>Alarm controller yang kode dan statusnya berhasil diidentifikasi</small></div>
  </div>

  <div class="cp-evidence-grid">
    <section class="cp-panel cp-evidence-panel"><header><div><span class="cp-kicker">BASIC INFORMATION</span><h3>Controller & system structure</h3></div></header>
      <div class="cp-evidence-kv">
        <div><span>Master equipment / lokasi</span><strong>{{ $robot->controller?->controller_code ?? '—' }} · {{ $robot->line_name ?? '—' }} / {{ $robot->station_name ?? '—' }}</strong></div>
        <div><span>R1 model dari SDK</span><strong data-cockpit-system="robot_r1">{{ $controllerIdentity['robot_r1']['model_name'] ?? 'Belum terbaca' }}</strong></div>
        <div><span>R1 software version</span><strong data-cockpit-version="robot_r1">{{ $controllerIdentity['robot_r1']['software_version'] ?? 'Belum terbaca' }}</strong></div>
        <div><span>S1 station terdeteksi</span><strong data-cockpit-system="station_s1">{{ $controllerIdentity['station_s1']['model_name'] ?? 'Belum terbaca / tidak tersedia' }}</strong></div>
        <div><span>A1 application</span><strong data-cockpit-system="application_a1">{{ $controllerIdentity['application_a1']['model_name'] ?? 'Belum terbaca' }}</strong></div>
      </div>
      <p class="cp-smallnote">Informasi SDK terakhir: <span data-cockpit-info-time>{{ $controllerIdentity['sampled_at'] ?? '—' }}</span> WIB. Serial controller belum tersedia pada pembacaan ini. S1 di sini adalah identitas station, belum monitoring axis gun.</p>
    </section>
    <section class="cp-panel cp-evidence-panel"><header><div><span class="cp-kicker">CURRENT STATUS</span><h3>State & executing job</h3></div><span class="cp-panel-sub">YMConnect read-only · bukan safety signal</span></header>
      <div class="cp-status-grid">
        @foreach(['running'=>'RUN','servo_on'=>'Servo ON','alarming'=>'ALARM','hold'=>'HOLD','error'=>'ERROR'] as $key=>$label)
          @php($value = $controllerState[$key] ?? null)
          <div><span>{{ $label }}</span><strong data-cockpit-state="{{ $key }}" class="cp-state {{ $value === null ? 'cp-unavailable' : ($value ? 'cp-warning' : 'cp-normal') }}">{{ $value === null ? 'UNKNOWN' : ($value ? 'ON' : 'OFF') }}</strong></div>
        @endforeach
      </div>
      <div class="cp-evidence-kv"><div><span>Control mode / cycle</span><strong><span data-cockpit-mode="control">{{ $controllerState['control_mode'] ?? '—' }}</span> / <span data-cockpit-mode="cycle">{{ $controllerState['cycle_mode'] ?? '—' }}</span></strong></div>
        <div><span>Job / line / step / speed override</span><strong><span data-cockpit-job="name">{{ $jobDetail['name'] ?? '—' }}</span> · L<span data-cockpit-job="line">{{ $jobDetail['line'] ?? '—' }}</span> · S<span data-cockpit-job="step">{{ $jobDetail['step'] ?? '—' }}</span> · <span data-cockpit-job="speed_override_percent">{{ $jobDetail['speed_override_percent'] ?? '—' }}</span>%</strong></div></div>
      <p class="cp-smallnote">Status menjadi UNKNOWN saat pembacaan state tidak segar. HOLD tidak dibedakan menjadi pendant / external / command oleh SDK ini. Emergency stop dan energy-saving tidak diklaim dari field yang tidak dibaca.</p>
    </section>
  </div>

  <section class="cp-panel cp-evidence-panel"><header><div><span class="cp-kicker">OPERATING COUNTERS</span><h3>Jam controller dan R1</h3></div><span class="cp-panel-sub">Diagnostic reads · interval collector 60 detik</span></header>
    <div class="cp-counter-grid">@foreach($operatingCounters as $type=>$counter)
      <div><span>{{ $counter['label'] }}</span><strong data-cockpit-counter="{{ $type }}">{{ $counter['hours'] === null ? '—' : number_format($counter['hours'], 2, ',', '.').' h' }}</strong><small data-cockpit-counter-time="{{ $type }}">{{ $counter['sampled_at'] ?? 'Belum terbaca' }} WIB</small></div>
    @endforeach</div>
    <p class="cp-smallnote">Counter ini hanya tampil bila pembacaan SDK valid dan format durasi terbaca. Servo power R1 baru dapat menjadi dasar PM setelah diverifikasi terhadap pendant; energy-saving counter belum ada di SDK yang dipakai.</p>
  </section>

  @include('rms.partials.technician-guide', ['guideId' => 'cockpit-technical-guide', 'guideOpen' => false])

  <div class="cp-columns">
    <section class="cp-panel cp-axis-panel">
      <header><div><span class="cp-kicker">CONTROL GROUP {{ $robot->control_group }}</span><h3>Axis Monitoring</h3></div><span class="cp-panel-sub">S · L · U · R · B · T</span></header>
      <div class="cp-robot-illustration" aria-hidden="true">
        <img class="rms-yaskawa-image rms-yaskawa-cockpit-image" src="{{ asset('rms/images/yaskawa-robot.png') }}" alt="" width="260" height="145" loading="lazy">
        <div class="cp-robot-caption">Ilustrasi robot Yaskawa · bukan digital twin</div>
      </div>
      <div class="cp-axis-list">
        @foreach($axes as $axis)
          @php($d=$axisStatus[$axis])
          <div class="cp-axis-item">
            <span class="cp-axis-letter">{{ $axis }}</span>
            <div class="cp-axis-measure"><strong>{{ $d['value'] !== null ? number_format($d['value'],4,',','.') : '—' }}</strong><small>Servo Monitor - Torq · Servo Monitor {{ is_numeric($d['pulse']) ? number_format((float)$d['pulse'],0,',','.') : '—' }} pulse</small></div>
            <span class="cp-state cp-{{ $d['status'] }}">{{ $axisStateLabels[$d['status']] ?? 'STATUS TIDAK DIKETAHUI' }}</span>
          </div>
        @endforeach
      </div>
      <div class="cp-smallnote"><strong>How to read:</strong> angka besar adalah Servo Monitor - Torq Value dari controller. Instant Axis Status memakai satu pembacaan terbaru, sedangkan Threshold Event memerlukan 3 valid samples berturut-turut. Tanpa approved threshold, status ditampilkan sebagai <strong>NOT CONFIGURED</strong> dan tidak dianggap Normal.</div>
    </section>

    <section class="cp-panel cp-torque-panel">
      <header><div><span class="cp-kicker">MEASUREMENT HISTORY</span><h3>Servo Monitor - Torq — All Axes</h3></div><span class="cp-panel-sub">Controller monitor value · unit pending verification</span></header>
      <div class="cp-chart-controls">
        <div class="cp-segment" role="group" aria-label="Mode grafik"><button type="button" data-mode="measurement" class="selected">Measurement</button><button type="button" data-mode="variation">Variation Δ</button></div>
        <div class="cp-periods" aria-label="Rentang historis">
          @foreach(['1h','8h','24h'] as $p)<a href="{{ route('rms.robots.cockpit', ['robot'=>$robot->id,'period'=>$p]) }}" class="{{ $period===$p?'selected':'' }}">{{ $p }}</a>@endforeach
        </div>
      </div>
      <div class="cp-chart-frame"><canvas id="cp-torque-chart" width="940" height="420" role="img" aria-label="Servo Monitor - Torq history for six robot axes"></canvas><div id="cp-torque-empty" class="cp-empty" hidden>Belum tersedia sampel Servo Monitor - Torq yang valid pada rentang ini.</div></div>
      <div class="cp-chart-legend" id="cp-torque-legend">@foreach($axes as $axis)<button type="button" data-axis="{{ $axis }}" class="is-selected"><span class="cp-dot cp-dot-{{ $axis }}"></span>{{ $axis }}</button>@endforeach</div>
      <div class="cp-chart-bottom"><div>Pilih axis untuk show/hide garis. Threshold line hanya muncul untuk axis dengan approved threshold pada mode Measurement.</div><div><label for="cp-focus-axis">Threshold Axis</label><select id="cp-focus-axis">@foreach($axes as $axis)<option value="{{ $axis }}">{{ $axis }}</option>@endforeach</select></div></div>
      <div class="cp-smallnote"><strong>Variation Δ</strong> adalah selisih terhadap valid sample pertama pada time range yang dipilih. Grafik ini menunjukkan perubahan sederhana dan <strong>bukan algoritma OEM Yaskawa Cockpit</strong>.</div>
    </section>
  </div>

  @include('rms.partials.servo-current-panel', ['servoCurrent' => $servoCurrent, 'servoCurrentExternal' => true, 'servoCurrentHistoryLabel' => 'Maksimal 300 sampel representatif pada rentang cockpit yang dipilih.'])

  <div class="cp-columns cp-second-row">
    <section class="cp-panel" id="threshold-config"><header><div><span class="cp-kicker">CONFIGURED LIMITS</span><h3>Torque Threshold per Axis</h3></div><div class="cp-panel-tools"><span class="cp-panel-sub">Approved thresholds only</span><a class="cp-action" href="{{ route('rms.robots.configuration', $robot) }}#threshold-config">⚙ Threshold Configuration</a></div></header>
      <div class="cp-table-wrap"><table class="cp-table"><thead><tr><th>Axis</th><th>Warning Threshold</th><th>Critical Threshold</th><th>Hysteresis</th><th>Status</th></tr></thead><tbody>
      @foreach($axes as $axis)
        @php($rule=$rules->get($axis))
        <tr><td><strong>{{ $axis }}</strong></td><td>{{ $rule && $rule->isApproved()?number_format((float)$rule->warning_magnitude,3,',','.'):'—' }}</td><td>{{ $rule && $rule->isApproved()?number_format((float)$rule->critical_magnitude,3,',','.'):'—' }}</td><td>{{ $rule && $rule->isApproved()?$rule->clear_hysteresis_percent.'%':'—' }}</td><td><span class="cp-state {{ $rule && $rule->isApproved()?'cp-normal':'cp-not_configured' }}">{{ $rule && $rule->isApproved()?'APPROVED':'NOT CONFIGURED' }}</span></td></tr>
      @endforeach
      </tbody></table></div>
      <div class="cp-smallnote">Threshold membandingkan <strong>Torque Magnitude |value|</strong>. Threshold hanya berstatus APPROVED setelah nilainya dicocokkan, Source Reference dicatat, dan pemeriksa diisi. Pengaturan ini tidak mengubah Controller Alarm.</div>
    </section>
    <section class="cp-panel" id="reducer-config"><header><div><span class="cp-kicker">COMPONENT CONDITION</span><h3>Speed Reducer Life</h3></div><div class="cp-panel-tools"><span class="cp-panel-sub">Verified OEM / inspection evidence</span><a class="cp-action" href="{{ route('rms.robots.configuration', $robot) }}#reducer-config">＋ Input Evidence</a></div></header>
      <div class="cp-chart-controls"><label for="cp-reducer-axis">Reducer / Axis</label><select id="cp-reducer-axis">@foreach($axes as $axis)<option value="{{ $axis }}">Axis {{ $axis }}</option>@endforeach</select></div>
      <div class="cp-chart-frame cp-reducer-chart"><canvas id="cp-reducer-chart" width="940" height="360" role="img" aria-label="Histori hasil inspeksi speed reducer"></canvas><div id="cp-reducer-empty" class="cp-empty" hidden>Belum ada pengukuran speed reducer terverifikasi dari sumber OEM.</div></div>
      <div class="cp-reducer-meta" id="cp-reducer-meta"></div>
      <div class="cp-smallnote"><strong>Remaining Life</strong> hanya ditampilkan jika nilainya tersedia dan terverifikasi pada OEM/inspection evidence. RMS tidak mengubah Servo Monitor - Torq menjadi prediksi umur reducer.</div>
    </section>
  </div>

  <div class="cp-columns cp-last-row">
    <section class="cp-panel"><header><div><span class="cp-kicker">THRESHOLD EVENT HISTORY</span><h3>Warning & Critical Events</h3></div><span class="cp-panel-sub">{{ $eventHistory->count() }} latest records</span></header>
      <div class="cp-table-wrap"><table class="cp-table"><thead><tr><th>Time WIB</th><th>Axis</th><th>Severity</th><th>Event Status</th><th>Peak Magnitude</th></tr></thead><tbody>
        @forelse($eventHistory as $event)<tr><td>{{ $event->started_at?->timezone('Asia/Jakarta')->format('d M H:i:s') }}</td><td>{{ $event->axis }}</td><td><span class="cp-state cp-{{ $event->severity }}">{{ strtoupper($event->severity) }}</span></td><td>{{ strtoupper($event->status) }}</td><td>{{ number_format((float)$event->peak_magnitude,4,',','.') }}</td></tr>
        @empty<tr><td colspan="5">Belum ada Threshold Event yang tercatat. Kondisi ini tidak otomatis membuktikan robot sehat; pastikan threshold sudah dikonfigurasi dan telemetry masih Fresh.</td></tr>@endforelse
      </tbody></table></div>
    </section>
    <section class="cp-panel"><header><div><span class="cp-kicker">MAINTENANCE REVIEW</span><h3>Data Quality & Action</h3></div></header>
      <div class="cp-quality"><div><strong>Controller Telemetry</strong><span>{{ $fresh?'Fresh data':'Stale or unavailable' }}</span></div><div><strong>Approved Torque Thresholds</strong><span>{{ $rules->filter(fn($r)=>$r->isApproved())->count() }} / 6 axis</span></div><div><strong>Verified Reducer Measurements</strong><span>{{ $reducerByAxis->filter()->count() }} / 6 axis</span></div><div><strong>Controller RUN/IDLE</strong><span>{{ $controllerState ? ('Mode '.($controllerState['control_mode'] ?? '?').' · RUN '.($controllerState['running'] ? 'ON' : 'OFF')) : 'Belum tersedia / STALE' }}</span></div><div><strong>Job & I/O</strong><span>{{ $jobDetail['name'] ? 'Job: '.$jobDetail['name'].' · I/O belum dinormalisasi' : 'Job belum terbaca · I/O belum dinormalisasi' }}</span></div></div>
      <div class="cp-smallnote">Saat telemetry <strong>STALE</strong>, kondisi robot harus diperlakukan <strong>UNKNOWN</strong>, bukan Normal dan bukan otomatis rusak. Keputusan maintenance tetap memerlukan pengecekan pendant, Controller Alarm, dan inspeksi teknisi.</div>
    </section>
  </div>
</div>
<script>
window.__RMS_COCKPIT__ = {{ \Illuminate\Support\Js::from($cockpitPayload) }};
</script>
<script src="{{ asset('rms/js/cockpit.js') }}?v=20261028-servo-current" defer></script>
@endsection

