@extends('rms.layouts.app')
@section('heading', 'Alarm center')
@section('subtitle', 'Controller Alarm Events dan riwayat pembacaan alarm — status pembacaan ditampilkan terpisah.')
@section('auto_refresh', '30')
@section('content')

{{-- Styling lokal: tidak perlu mengubah layout dashboard / CSS Robot Logs. --}}
<style>
  .rms-alarm-robot-field{position:relative;min-width:250px;max-width:360px;flex:1 1 280px;display:grid;gap:6px}
  .rms-alarm-robot-label{color:var(--muted,#66776c);font-size:11px}
  .rms-alarm-robot-select{position:relative;width:100%}
  .rms-alarm-robot-trigger{display:flex;align-items:center;justify-content:space-between;gap:12px;min-height:36px;padding:8px 11px;border:1px solid var(--line,#e4eae6);border-radius:7px;background:#fff;cursor:pointer;list-style:none;color:#4a6957;font-size:12px}
  .rms-alarm-robot-trigger::-webkit-details-marker{display:none}
  .rms-alarm-robot-trigger:focus-visible{outline:2px solid #98cdb8;outline-offset:2px}
  .rms-alarm-robot-trigger span:first-child{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
  .rms-alarm-robot-select[open] .rms-alarm-robot-trigger{border-color:#81b699;box-shadow:0 0 0 2px #81b69930}
  .rms-alarm-robot-menu{position:absolute;top:calc(100% + 5px);left:0;right:0;z-index:60;min-width:265px;padding:11px;background:#fff;border:1px solid #cbded2;border-radius:10px;box-shadow:0 14px 30px #20372c26}
  .rms-alarm-robot-actions{display:flex;gap:6px;margin-bottom:8px;flex-wrap:wrap}
  .rms-alarm-robot-actions button{background:#edf6f0;border:0;border-radius:6px;color:#247b60;padding:6px 9px;font-size:11px;cursor:pointer}
  .rms-alarm-robot-actions button:hover{background:#d9ecdf}
  .rms-alarm-robot-search{width:100%;min-width:0;height:35px;margin-bottom:7px}
  .rms-alarm-robot-options{max-height:225px;overflow:auto;display:grid;gap:2px}
  .rms-alarm-robot-option{display:flex;align-items:center;gap:10px;padding:8px;border-radius:6px;font-size:11px;cursor:pointer;color:#344f3c}
  .rms-alarm-robot-option:hover{background:#f0f7f2}
  .rms-alarm-robot-option[hidden]{display:none}
  .rms-alarm-robot-option input{accent-color:#247b60;width:16px;height:16px;flex:none}
  .rms-alarm-robot-option span{display:grid;gap:2px}
  .rms-alarm-robot-option small{color:#83968a;font-size:10px}
  .rms-alarm-robot-hint{font-size:10px;color:#778a7c;line-height:1.5;margin:9px 0}
  .rms-alarm-robot-done{display:block;width:100%}
  .rms-alarm-robot-chips{display:flex;flex-wrap:wrap;gap:4px;margin-top:2px}
  .rms-alarm-robot-chips[hidden]{display:none}
  .rms-alarm-robot-chip{font-size:10px;color:#247b60;background:#e9f5ec;border:1px solid #c5e2d0;border-radius:5px;padding:3px 6px}
  @media(max-width:600px){.rms-alarm-robot-field{max-width:none;width:100%}.rms-alarm-robot-menu{min-width:0}}
</style>

<div class="stats rms-stats-compact">
  @include('rms.partials.metric', [
    'label' => 'Alarm aktif teridentifikasi',
    'value' => $activeEventCount,
    'unit' => 'event',
    'description' => count($filter['robot_ids']) ? 'Dari robot yang dipilih' : 'Dari seluruh robot'
  ])
  @include('rms.partials.metric', [
    'label' => 'Alarm reading history',
    'value' => $snapshots->count(),
    'unit' => 'terbaru',
    'description' => 'Maks. 30 pembacaan controller sesuai robot terpilih'
  ])
</div>

<div class="panel">
  @if($errors->any())
    <div class="rms-note" role="alert">{{ $errors->first() }}</div>
  @endif
  <form method="GET" action="{{ route('rms.alarms.index') }}" class="rms-filter-form" id="rms-alarm-filter-form">
    <div class="rms-alarm-robot-field">
      <span class="rms-alarm-robot-label" id="rms-alarm-robot-label">Robot (bisa lebih dari satu)</span>
      <details class="rms-alarm-robot-select" id="rms-alarm-robot-select">
        <summary class="rms-alarm-robot-trigger" aria-labelledby="rms-alarm-robot-label rms-alarm-robot-summary">
          <span id="rms-alarm-robot-summary">
            @if(count($filter['robot_ids']) === 0)
              Semua robot
            @elseif(count($filter['robot_ids']) === 1)
              {{ $robots->firstWhere('id', $filter['robot_ids'][0])?->robot_code ?? '1 robot dipilih' }}
            @else
              {{ count($filter['robot_ids']) }} robot dipilih
            @endif
          </span>
          <span aria-hidden="true">⌄</span>
        </summary>
        <div class="rms-alarm-robot-menu">
          <div class="rms-alarm-robot-actions">
            <button type="button" data-alarm-picker-action="all">Semua robot</button>
            <button type="button" data-alarm-picker-action="select-all">Pilih seluruh robot</button>
          </div>
          <input type="search" class="input rms-alarm-robot-search" id="rms-alarm-robot-search" placeholder="Cari kode/nama robot..." aria-label="Cari robot">
          <div class="rms-alarm-robot-options" role="group" aria-labelledby="rms-alarm-robot-label">
            @foreach($robots as $robot)
              <label class="rms-alarm-robot-option" data-search="{{ $robot->robot_code }} {{ $robot->name }}">
                <input type="checkbox" name="robot_ids[]" value="{{ $robot->id }}"
                       data-code="{{ $robot->robot_code }}"
                       @checked(in_array((int) $robot->id, $filter['robot_ids'], true))>
                <span><strong>{{ $robot->robot_code }}</strong><small>{{ $robot->name }}</small></span>
              </label>
            @endforeach
          </div>
          <p class="rms-alarm-robot-hint">Tidak mencentang robot = tampilkan semua. Pilih beberapa robot untuk membatasi event dan riwayat pembacaan.</p>
          <button type="button" class="btn rms-alarm-robot-done" data-alarm-picker-action="done">Selesai memilih</button>
        </div>
      </details>
      <div class="rms-alarm-robot-chips" id="rms-alarm-robot-chips" aria-live="polite" @if(count($filter['robot_ids']) === 0) hidden @endif>
        @foreach($robots as $robot)
          @if(in_array((int) $robot->id, $filter['robot_ids'], true))
            <span class="rms-alarm-robot-chip">{{ $robot->robot_code }}</span>
          @endif
        @endforeach
      </div>
    </div>

    <label class="field">Status
      <select name="status" class="input">
        @foreach(['all','active','cleared','unknown'] as $val)
          <option value="{{ $val }}" @selected(($filter['status'] ?? 'all') === $val)>{{ strtoupper($val) }}</option>
        @endforeach
      </select>
    </label>
    <label class="field">Kode/nama
      <input type="search" name="search" class="input" value="{{ $filter['search'] ?? '' }}" maxlength="100" placeholder="Cari alarm">
    </label>
    <label class="field">Kategori histori
      <select name="category" class="input">
        @foreach(['all','Major','Minor','UserSystem','User','Offline'] as $category)
          <option value="{{ $category }}" @selected(($filter['category'] ?? 'all') === $category)>{{ $category }}</option>
        @endforeach
      </select>
    </label>
    <button type="submit" class="btn primary">Cari</button>
    <a class="btn" href="{{ route('rms.alarms.index') }}">Reset</a>
  </form>
</div>

<section class="panel rms-section-spaced">
  <div class="panel-header"><h2>Identified Controller Alarm Events</h2><span class="rms-muted-small">{{ $events->total() }} record</span></div>
  @if($events->isEmpty())
    @include('rms.partials.no-data', ['message'=>'Belum ada Alarm Event pada filter yang dipilih. Tidak adanya event teridentifikasi tidak otomatis berarti controller bebas alarm.'])
  @else
    <div class="table-wrap">
      <table class="data-table">
        <thead><tr><th>Robot</th><th>Started at (WIB)</th><th>Code</th><th>Alarm</th><th>Severity</th><th>Status</th></tr></thead>
        <tbody>
          @foreach($events as $e)
            <tr>
              <td>{{ $e->robot?->robot_code ?? '—' }}</td>
              <td>{{ $e->started_at?->timezone('Asia/Jakarta')->format('d M Y H:i:s') ?? '—' }}</td>
              <td>{{ $e->alarm_code ?: '—' }}</td>
              <td>{{ $e->alarm_name ?: 'Belum diketahui' }}</td>
              <td>{{ $e->severity ?: '—' }}</td>
              <td><span class="badge rms-badge {{ $e->status }}">{{ strtoupper($e->status) }}</span></td>
            </tr>
          @endforeach
        </tbody>
      </table>
    </div>
    <div class="rms-pagination">{{ $events->links() }}</div>
  @endif
</section>

<section class="panel rms-section-spaced" id="controller-alarm-history">
  <div class="panel-header"><div><h2>YMConnect Controller Alarm History</h2><p>Kategori dibaca langsung dari histori controller; waktu controller disimpan sebagai teks asli.</p></div><span class="rms-muted-small">{{ $historyEntries->total() }} record unik</span></div>
  <div class="table-wrap"><table class="data-table"><thead><tr><th>Robot</th><th>Kategori</th><th>Kode / subcode</th><th>Nama</th><th>Waktu controller (raw)</th><th>Pertama terambil (WIB)</th></tr></thead><tbody>
    @forelse($historyEntries as $entry)
      <tr><td>{{ $entry->robot?->robot_code ?? '—' }}</td><td>{{ $entry->category }}</td><td>{{ $entry->alarm_code }} / {{ $entry->subcode ?? '—' }}</td><td>{{ $entry->alarm_name }}</td><td>{{ $entry->controller_time_raw }}</td><td>{{ $entry->first_observed_at?->timezone('Asia/Jakarta')->format('d M Y H:i:s') }}</td></tr>
    @empty<tr><td colspan="6">Belum ada entri histori yang dapat diidentifikasi. Cek status pembacaan kategori pada snapshot raw.</td></tr>@endforelse
  </tbody></table></div>
  <div class="rms-pagination">{{ $historyEntries->links() }}</div>
  <p class="rms-muted-small">Waktu controller belum disinkronkan dengan waktu server. Job, line, step, dan mode saat kejadian tidak disediakan dalam payload histori YMConnect saat ini; jangan menyamakan data terbaru dengan konteks kejadian lama.</p>
</section>

<section class="panel rms-section-spaced">
  <div class="panel-header"><div><h2>Active Alarm Reading History</h2><p>Untuk mengidentifikasi Controller Alarm dan gangguan pembacaan</p></div></div>
  <div class="table-wrap">
    <table class="data-table">
      <thead><tr><th>Robot</th><th>Sample (WIB)</th><th>Read Status</th><th>Active Count</th><th>Controller Alarm Text</th></tr></thead>
      <tbody>
        @forelse($snapshots as $s)
          <tr>
            <td>{{ $s->robot?->robot_code ?? '—' }}</td>
            <td>{{ $s->sampled_at?->timezone('Asia/Jakarta')->format('d M H:i:s') ?? '—' }}</td>
            <td>@include('rms.partials.read-status', ['ok'=>$s->read_ok])</td>
            <td>{{ $s->active_alarm_count ?? 'unknown' }}</td>
            <td class="rms-raw">{{ \Illuminate\Support\Str::limit($s->sdk_text ?: '—', 150) }}</td>
          </tr>
        @empty
          <tr><td colspan="5">Belum ada riwayat pembacaan alarm untuk pilihan robot tersebut</td></tr>
        @endforelse
      </tbody>
    </table>
  </div>
</section>
<div class="rms-note">{{ $parsingNote }}</div>

{{-- Delegated events survive dashboard.js replacing #rms-dynamic-content on auto refresh. --}}
<script>
(() => {
  if (window.__rmsAlarmRobotPickerBound) return;
  window.__rmsAlarmRobotPickerBound = true;

  function root() { return document.getElementById('rms-alarm-robot-select'); }
  function options() { return [...document.querySelectorAll('#rms-alarm-robot-select input[name="robot_ids[]"]')]; }
  function paint() {
    const picker = root();
    if (!picker) return;
    const chosen = options().filter(el => el.checked);
    const summary = document.getElementById('rms-alarm-robot-summary');
    const chips = document.getElementById('rms-alarm-robot-chips');
    if (summary) summary.textContent = chosen.length === 0 ? 'Semua robot' : chosen.length === 1 ? chosen[0].dataset.code : `${chosen.length} robot dipilih`;
    if (chips) {
      chips.replaceChildren();
      chips.hidden = chosen.length === 0;
      chosen.forEach(el => {
        const tag = document.createElement('span');
        tag.className = 'rms-alarm-robot-chip';
        tag.textContent = el.dataset.code || el.value;
        chips.appendChild(tag);
      });
    }
  }

  document.addEventListener('change', event => {
    if (event.target?.matches('#rms-alarm-robot-select input[name="robot_ids[]"]')) paint();
  });
  document.addEventListener('input', event => {
    if (event.target?.id !== 'rms-alarm-robot-search') return;
    const term = event.target.value.trim().toLocaleLowerCase('id-ID');
    root()?.querySelectorAll('.rms-alarm-robot-option').forEach(row => {
      row.hidden = !(row.dataset.search || '').toLocaleLowerCase('id-ID').includes(term);
    });
  });
  document.addEventListener('click', event => {
    const picker = root();
    if (!picker) return;
    const button = event.target.closest?.('[data-alarm-picker-action]');
    if (button && picker.contains(button)) {
      const action = button.dataset.alarmPickerAction;
      if (action === 'all' || action === 'select-all') {
        options().forEach(el => { el.checked = action === 'select-all'; });
        paint();
      }
      if (action === 'done') picker.open = false;
      return;
    }
    if (picker.open && !picker.contains(event.target)) picker.open = false;
  });
})();
</script>
@endsection
