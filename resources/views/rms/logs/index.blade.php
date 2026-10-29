@extends('rms.layouts.app')

@section('heading', 'Robot log history')
@section('subtitle', 'Robot history dalam waktu WIB, dilengkapi filter shift dan Excel export.')
@section('content')

<section class="rms-logs-intro panel" aria-label="Ringkasan rentang log">
  <div class="rms-logs-intro-icon" aria-hidden="true">≡</div>
  <div>
    <strong>Log operasi seluruh robot</strong>
    <p>Termasuk telemetry PULSE/torque, alarm, connection, operating time, equipment health, dan jurnal maintenance. Ini histori pembacaan, bukan perintah robot.</p>
  </div>
  <span class="rms-logs-tz">WIB · UTC+7</span>
</section>

<section class="panel rms-logs-filter-panel" aria-labelledby="rms-logs-filter-heading">
  <div class="panel-header">
    <div>
      <h2 id="rms-logs-filter-heading">Filter log robot</h2>
      <p>Tentukan robot, jenis data, dan rentang jam yang ingin diperiksa.</p>
    </div>
  </div>

  @if($errors->any())
    <div class="rms-logs-errors" role="alert">
      @foreach($errors->all() as $error)<div>{{ $error }}</div>@endforeach
    </div>
  @endif

  <form method="GET" action="{{ route('rms.logs.index') }}" class="rms-logs-form" id="rms-logs-filter-form">
    {{-- Multi-robot filter. Empty robot_ids[] means ALL robots (no filter). --}}
    <div class="rms-logs-field rms-logs-robot-field">
      <span id="rms-logs-robot-label">Robot (boleh lebih dari satu)</span>
      <details class="rms-logs-robot-select" id="rms-logs-robot-select">
        <summary class="rms-logs-robot-trigger" aria-labelledby="rms-logs-robot-label rms-logs-robot-summary">
          <span id="rms-logs-robot-summary">
            @if(count($filters['robot_ids']) === 0)
              Semua robot
            @elseif(count($filters['robot_ids']) === 1)
              {{ $robots->firstWhere('id', $filters['robot_ids'][0])?->robot_code ?? '1 robot dipilih' }}
            @else
              {{ count($filters['robot_ids']) }} robot dipilih
            @endif
          </span>
          <span class="rms-logs-robot-caret" aria-hidden="true">⌄</span>
        </summary>
        <div class="rms-logs-robot-menu">
          <div class="rms-logs-robot-tools">
            <button type="button" class="rms-logs-robot-tool" id="rms-logs-all">Semua robot</button>
            <button type="button" class="rms-logs-robot-tool" id="rms-logs-select-all">Pilih seluruh robot</button>
          </div>
          <input type="search" id="rms-logs-robot-search" class="input rms-logs-robot-search"
                 placeholder="Cari kode atau nama robot..." aria-label="Cari robot">
          <div class="rms-logs-robot-options" role="group" aria-labelledby="rms-logs-robot-label">
            @foreach($robots as $robot)
              <label class="rms-logs-robot-option" data-search="{{ $robot->robot_code }} {{ $robot->name }}">
                <input type="checkbox" name="robot_ids[]" value="{{ $robot->id }}"
                       data-robot-code="{{ $robot->robot_code }}"
                       @checked(in_array((int)$robot->id, $filters['robot_ids'], true))>
                <span><strong>{{ $robot->robot_code }}</strong><small>{{ $robot->name }}</small></span>
              </label>
            @endforeach
          </div>
          <p class="rms-logs-robot-help">Tanpa centang = semua robot. Centang beberapa robot untuk membatasi log.</p>
          <button type="button" class="btn rms-logs-robot-done" id="rms-logs-robot-done">Selesai memilih</button>
        </div>
      </details>
      <div class="rms-logs-selected-robots" id="rms-logs-selected-robots" aria-live="polite"
           @if(count($filters['robot_ids']) === 0) hidden @endif>
        @foreach($robots as $robot)
          @if(in_array((int)$robot->id, $filters['robot_ids'], true))
            <span class="rms-logs-robot-chip">{{ $robot->robot_code }}</span>
          @endif
        @endforeach
      </div>
    </div>

    {{-- Multi-select types: no types[] means ALL sources; submitted IDs are validated in controller. --}}
    <div class="rms-logs-field rms-logs-type-field">
      <span id="rms-logs-type-label">Jenis log (boleh lebih dari satu)</span>
      <details class="rms-logs-type-select" id="rms-logs-type-select">
        <summary class="rms-logs-type-trigger" aria-labelledby="rms-logs-type-label rms-logs-type-summary">
          <span id="rms-logs-type-summary">
            @if(count($filters['types']) === 0)
              Semua jenis log
            @elseif(count($filters['types']) === 1)
              {{ $types[$filters['types'][0]] ?? '1 jenis log dipilih' }}
            @else
              {{ count($filters['types']) }} jenis log dipilih
            @endif
          </span>
          <span class="rms-logs-type-caret" aria-hidden="true">⌄</span>
        </summary>
        <div class="rms-logs-type-menu">
          <div class="rms-logs-type-tools">
            <button type="button" class="rms-logs-type-tool" id="rms-logs-type-all">Semua jenis</button>
            <button type="button" class="rms-logs-type-tool" id="rms-logs-type-events">Event penting</button>
            <button type="button" class="rms-logs-type-tool" id="rms-logs-type-select-all">Pilih semua</button>
          </div>
          <div class="rms-logs-type-options" role="group" aria-labelledby="rms-logs-type-label">
            @foreach($types as $value => $label)
              <label class="rms-logs-type-option">
                <input type="checkbox" name="types[]" value="{{ $value }}"
                       data-type-label="{{ $label }}"
                       data-important="{{ in_array($value, ['alarm_event', 'connection', 'health', 'maintenance', 'recommendation'], true) ? '1' : '0' }}"
                       @checked(in_array($value, $filters['types'], true))>
                <span>{{ $label }}</span>
              </label>
            @endforeach
          </div>
          <p class="rms-logs-type-help">Tanpa centang = semua jenis. Pilih beberapa untuk membatasi log.</p>
          <button type="button" class="btn rms-logs-type-done" id="rms-logs-type-done">Selesai memilih</button>
        </div>
      </details>
      <div class="rms-logs-selected-types" id="rms-logs-selected-types" aria-live="polite"
           @if(count($filters['types']) === 0) hidden @endif>
        @foreach($filters['types'] as $type)
          <span class="rms-logs-type-chip">{{ $types[$type] ?? $type }}</span>
        @endforeach
      </div>
    </div>

    <label class="rms-logs-field">
      <span>Filter waktu</span>
      <select name="preset" class="input" id="rms-logs-preset">
        <option value="shift_pagi" @selected($filters['preset'] === 'shift_pagi')>Shift 1 · 07:15–16:00</option>
        <option value="shift_2" @selected($filters['preset'] === 'shift_2')>Shift 2 (malam) · 21:00–05:45</option>
        <option value="full_day" @selected($filters['preset'] === 'full_day')>Satu hari · 00:00–23:59</option>
        <option value="custom" @selected($filters['preset'] === 'custom')>Rentang tanggal dan jam manual</option>
      </select>
    </label>

    <label class="rms-logs-field" id="rms-logs-date-field">
      <span>Tanggal shift / hari</span>
      <input type="date" name="date" value="{{ $filters['date'] }}" class="input" id="rms-logs-date">
    </label>

    <div class="rms-logs-custom-range" id="rms-logs-custom-range" @if($filters['preset'] !== 'custom') hidden @endif>
      <label class="rms-logs-field"><span>Dari tanggal & jam (WIB)</span>
        <input type="datetime-local" name="from" value="{{ $filters['from'] }}" class="input" id="rms-logs-from" @if($filters['preset'] !== 'custom') disabled @endif>
      </label>
      <label class="rms-logs-field"><span>Sampai tanggal & jam (WIB)</span>
        <input type="datetime-local" name="to" value="{{ $filters['to'] }}" class="input" id="rms-logs-to" @if($filters['preset'] !== 'custom') disabled @endif>
      </label>
    </div>

    <label class="rms-logs-field rms-logs-small-field">
      <span>Baris/halaman</span>
      <select name="per_page" class="input">
        @foreach([25, 50, 100] as $count)
          <option value="{{ $count }}" @selected($filters['per_page'] === $count)>{{ $count }}</option>
        @endforeach
      </select>
    </label>
    <div class="rms-logs-actions">
      <button type="submit" class="btn primary">Tampilkan log</button>
      <a class="btn" href="{{ route('rms.logs.index') }}">Reset</a>
    </div>
  </form>
  <div class="rms-logs-note">Tanggal Shift 2 adalah tanggal mulai: pukul 21:00 pada tanggal terpilih sampai 05:45 pagi hari berikutnya. Rentang maksimal 7 hari. Batas jam akhir mencakup detik terakhir pada menit tersebut. Pengolahan waktu menggunakan Asia/Jakarta (WIB).</div>
</section>

<section id="rms-logs-results" class="panel rms-logs-results" aria-label="Hasil log robot">
  <div class="rms-logs-results-top">
    <div>
      <h2>Hasil log</h2>
      <p>
        {{ $filters['from'] }} hingga {{ $filters['to'] }} WIB
        · @if(count($filters['types']) === 0)
            Semua jenis log
          @elseif(count($filters['types']) === 1)
            {{ $types[$filters['types'][0]] ?? '1 jenis log dipilih' }}
          @else
            {{ count($filters['types']) }} jenis log dipilih
          @endif
        · {{ count($filters['robot_ids']) === 0 ? 'Seluruh robot' : count($filters['robot_ids']) . ' robot terpilih' }}
      </p>
    </div>
    <a class="btn rms-logs-export" href="{{ $exportUrl }}">↓ Download Excel (.xlsx)</a>
  </div>
  <div class="rms-logs-legend">Data diurutkan dari waktu terbaru. Jenis <strong>Semua</strong> termasuk telemetry 1 detik; klik <strong>Event penting</strong> di filter Jenis log untuk melihat alarm dan maintenance tanpa tenggelam oleh sampel berkala.</div>

  <div class="table-wrap rms-logs-table-wrap">
    <table class="data-table rms-logs-table">
      <thead><tr>
        <th>Waktu (WIB)</th><th>Robot</th><th>Jenis</th><th>Status pembacaan / event</th><th>Ringkasan</th><th>Detail</th>
      </tr></thead>
      <tbody>
        @forelse($entries as $entry)
          <tr>
            <td class="rms-logs-date-cell"><time>{{ $entry['time_wib'] }}</time></td>
            <td><a class="rms-link" href="{{ route('rms.robots.show', $entry['robot_id']) }}">{{ $entry['robot_code'] }}</a>
              <small class="rms-logs-sub">{{ $entry['robot_name'] }}</small></td>
            <td><span class="rms-logs-type">{{ $entry['type_label'] }}</span></td>
            <td><span class="rms-logs-status rms-logs-status--{{ $entry['tone'] }}">{{ $entry['status'] }}</span></td>
            <td class="rms-logs-summary">{{ $entry['summary'] }}</td>
            <td>
              <details class="rms-logs-details">
                <summary>Lihat detail</summary>
                <p>Detail lengkap belum dapat ditampilkan pada mode browser ini.</p>
                <pre hidden aria-hidden="true">{{ json_encode($entry['details'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) }}</pre>
              </details>
            </td>
          </tr>
        @empty
          <tr><td colspan="6" class="rms-logs-empty">Tidak ada log untuk kombinasi robot dan waktu ini. Coba ubah rentang waktu atau jenis log. Ini bukan bukti bahwa robot sehat.</td></tr>
        @endforelse
      </tbody>
    </table>
  </div>
  @php
    $lastPage = $page->lastPage();
    $windowStart = max(1, min($page->currentPage() - 2, $lastPage - 4));
    $windowEnd = min($lastPage, $windowStart + 4);
  @endphp
  <div class="rms-logs-pagination">
    <span>
      Menampilkan {{ number_format($page->firstItem() ?? 0, 0, ',', '.') }}–{{ number_format($page->lastItem() ?? 0, 0, ',', '.') }}
      dari {{ number_format($page->total(), 0, ',', '.') }} log
      · Halaman {{ number_format($page->currentPage(), 0, ',', '.') }} dari {{ number_format($lastPage, 0, ',', '.') }}
    </span>
    @if($page->hasPages())
      <nav class="rms-logs-pagination-actions" aria-label="Navigasi halaman log">
        @if($page->onFirstPage())
          <span class="rms-logs-page is-disabled" aria-disabled="true">← Sebelumnya</span>
        @else
          <a class="rms-logs-page" href="{{ $page->previousPageUrl() }}" rel="prev">← Sebelumnya</a>
        @endif

        @if($windowStart > 1)
          <a class="rms-logs-page" href="{{ $page->url(1) }}" aria-label="Halaman 1">1</a>
          @if($windowStart > 2)<span class="rms-logs-page-gap" aria-hidden="true">…</span>@endif
        @endif
        @for($number = $windowStart; $number <= $windowEnd; $number++)
          @if($number === $page->currentPage())
            <span class="rms-logs-page is-current" aria-current="page" aria-label="Halaman {{ $number }}">{{ $number }}</span>
          @else
            <a class="rms-logs-page" href="{{ $page->url($number) }}" aria-label="Halaman {{ $number }}">{{ $number }}</a>
          @endif
        @endfor
        @if($windowEnd < $lastPage)
          @if($windowEnd < $lastPage - 1)<span class="rms-logs-page-gap" aria-hidden="true">…</span>@endif
          <a class="rms-logs-page" href="{{ $page->url($lastPage) }}" aria-label="Halaman {{ $lastPage }}">{{ $lastPage }}</a>
        @endif

        @if($page->onLastPage())
          <span class="rms-logs-page is-disabled" aria-disabled="true">Berikutnya →</span>
        @else
          <a class="rms-logs-page" href="{{ $page->nextPageUrl() }}" rel="next">Berikutnya →</a>
        @endif
      </nav>
    @endif
  </div>
  <div class="rms-logs-note">Ekspor Excel dibatasi maksimal 5.000 baris per permintaan agar dashboard tetap ringan. Jika data lebih banyak, laporan memberi penanda DIPOTONG. Sempitkan waktu atau pilih robot tertentu untuk mengekspor sisanya.</div>
</section>
@endsection
