{{-- RMS ALARM PARETO ADDITION — no existing Alarm Center tables are changed. --}}
<section class="panel rms-section-spaced rms-alarm-pareto" id="rms-alarm-pareto" aria-labelledby="rms-alarm-pareto-heading">
  <div class="panel-header rms-pareto-header">
    <div>
      <div class="rms-pareto-eyebrow">ALARM INTELLIGENCE / CONTROLLER HISTORY</div>
      <h2 id="rms-alarm-pareto-heading">Pareto — Alarm Paling Sering Tercatat</h2>
      <p>Top 10 kode/subcode berdasarkan histori unik · {{ $pareto['period_label'] }}@if($pareto['since_wib']) (sejak {{ $pareto['since_wib'] }} WIB)@endif</p>
    </div>
    <span class="rms-pareto-source-pill">YMConnect Alarm History</span>
  </div>

  @if($pareto['total'] === 0)
    <div class="rms-pareto-empty" role="status">
      <strong>Belum ada data histori alarm yang cocok dengan filter.</strong>
      <p>Coba pilih semua robot, kategori All atau periode Seluruh histori. Grafik tidak menggunakan data simulasi.</p>
    </div>
  @else
    <div class="rms-pareto-metrics">
      <div class="rms-pareto-metric">
        <span>Histori unik</span>
        <strong>{{ number_format($pareto['total'], 0, ',', '.') }}</strong>
        <small>Record controller history teridentifikasi</small>
      </div>
      <div class="rms-pareto-metric">
        <span>Alarm teratas</span>
        <strong class="rms-pareto-code">{{ $pareto['top_alarm']['label'] }}</strong>
        <small>{{ \Illuminate\Support\Str::limit($pareto['top_alarm']['name'], 55) }}</small>
      </div>
      <div class="rms-pareto-metric">
        <span>Jumlah alarm teratas</span>
        <strong>{{ number_format($pareto['top_alarm']['count'], 0, ',', '.') }} <em>record</em></strong>
        <small>{{ number_format($pareto['top_alarm']['percent'], 1, ',', '.') }}% dari seluruh histori terfilter</small>
      </div>
      <div class="rms-pareto-metric">
        <span>Cakupan Top 10</span>
        <strong>{{ number_format($pareto['top_share_percent'], 1, ',', '.') }}<em>%</em></strong>
        <small>{{ number_format($pareto['other_total'], 0, ',', '.') }} record di kategori lainnya</small>
      </div>
    </div>

    <div class="rms-pareto-chart-top">
      <strong>Distribusi frekuensi dan persentase kumulatif</strong>
      <div class="rms-pareto-legend" aria-label="Keterangan grafik">
        <span><i class="rms-pareto-legend-bar"></i> Jumlah record</span>
        <span><i class="rms-pareto-legend-line"></i> Kumulatif (%)</span>
        @if($pareto['other_total'] > 0)<span><i class="rms-pareto-legend-other"></i> Lainnya (gabungan)</span>@endif
      </div>
    </div>

    @php
      $paretoRows = $pareto['rows'];
      $paretoPlotLeft = 84;
      $paretoPlotRight = 1458;
      $paretoPlotTop = 46;
      $paretoPlotBottom = 317;
      $paretoPlotWidth = $paretoPlotRight - $paretoPlotLeft;
      $paretoPlotHeight = $paretoPlotBottom - $paretoPlotTop;
      $paretoSlots = max(1, count($paretoRows));
      $paretoSlotWidth = $paretoPlotWidth / $paretoSlots;
      $paretoBarWidth = min(68, $paretoSlotWidth * 0.55);
      // Skala kiri KHUSUS jumlah per kode alarm (bukan total seluruh histori),
      // agar batang berukuran jelas ketika total histori jauh lebih besar.
      // 'Lainnya' merupakan gabungan banyak kode, tidak ikut menentukan skala.
      $paretoIndividualCounts = array_map(
          static fn (array $item): int => (int) $item['count'],
          array_values(array_filter($paretoRows, static fn (array $item): bool => ! $item['other']))
      );
      $paretoMaxIndividual = max(1, ...$paretoIndividualCounts);
      $paretoTickStep = max(1, (int) ceil($paretoMaxIndividual / 4));
      $paretoYMax = $paretoTickStep * 4;
      // Garis Pareto secara visual dimulai di SUDUT KANAN ATAS batang pertama.
      // Dengan skala kiri yang diperbesar, garis kumulatif 0-100% LINEAR
      // tidak mungkin sekaligus menempel di puncak batang pertama. Maka
      // koordinat skala kanan dibuat PIECEWISE NON-LINEAR dan setiap tick
      // persentase kanan ditempatkan memakai fungsi yang SAMA.
      // Nilai persentase tetap ASLI, tidak diubah/diestimasi.
      $paretoFirstPercent = max(0.000001, min(100.0, (float) $paretoRows[0]['cumulative']));
      $paretoFirstBarTop = $paretoPlotBottom
          - $paretoPlotHeight * ((float) $paretoRows[0]['count'] / $paretoYMax);
      $paretoPercentY = static function (float $percent) use (
          $paretoPlotBottom, $paretoPlotTop, $paretoFirstPercent, $paretoFirstBarTop
      ): float {
          $percent = max(0.0, min(100.0, $percent));
          if ($percent <= $paretoFirstPercent || $paretoFirstPercent >= 100.0) {
              // Bagian 0% hingga kontribusi alarm pertama: 0 -> dasar, p1 -> puncak bar.
              return $paretoPlotBottom
                  - ($percent / $paretoFirstPercent) * ($paretoPlotBottom - $paretoFirstBarTop);
          }
          // Bagian p1 hingga 100%: p1 -> puncak bar pertama, 100 -> puncak chart.
          return $paretoFirstBarTop
              - (($percent - $paretoFirstPercent) / (100.0 - $paretoFirstPercent))
              * ($paretoFirstBarTop - $paretoPlotTop);
      };
      $paretoLinePoints = [];
      foreach ($paretoRows as $i => $row) {
          // Tepat di TEPI KANAN batang, termasuk titik awal bar pertama.
          $cx = $paretoPlotLeft + $paretoSlotWidth * ($i + 0.5) + $paretoBarWidth / 2;
          $cy = $paretoPercentY((float) $row['cumulative']);
          $paretoLinePoints[] = number_format($cx, 2, '.', '').','.number_format($cy, 2, '.', '');
      }
      $paretoLinePath = implode(' ', $paretoLinePoints);
    @endphp

    <div class="rms-pareto-chart-scroll" role="region" aria-label="Grafik Pareto Alarm History. Klik batang untuk melihat informasi lengkap error." tabindex="0">
      <svg class="rms-pareto-svg rms-pareto-svg-interactive" viewBox="0 0 1540 493" role="img" aria-labelledby="rms-pareto-svg-title rms-pareto-svg-description" style="min-width:1280px">
        <title id="rms-pareto-svg-title">Grafik Pareto Top 10 Alarm History</title>
        <desc id="rms-pareto-svg-description">Batang hijau memperlihatkan jumlah catatan tiap alarm memakai sumbu kiri. Garis biru menampilkan persentase kumulatif asli, dimulai pada sudut kanan atas batang pertama. Agar batang tetap tinggi sekaligus titik pertama menempel pada batang, skala persentase kanan memakai pemetaan visual nonlinier dengan posisi tick yang sesuai. Semua angka di rincian tetap berasal dari data asli. Klik batang untuk detail.</desc>

        @for($tick = 0; $tick <= 4; $tick++)
          @php
            $tickY = $paretoPlotBottom - $paretoPlotHeight * $tick / 4;
            // Sumbu kiri khusus frekuensi per jenis alarm: 0, step, 2*step, ...
            $tickValue = $paretoTickStep * $tick;
          @endphp
          <line x1="{{ $paretoPlotLeft }}" y1="{{ $tickY }}" x2="{{ $paretoPlotRight }}" y2="{{ $tickY }}" class="rms-pareto-grid-line" />
          <text x="{{ $paretoPlotLeft - 11 }}" y="{{ $tickY + 4 }}" text-anchor="end" class="rms-pareto-axis-tick">{{ number_format($tickValue, 0, ',', '.') }}</text>
        @endfor
        {{-- Angka kumulatif memakai posisi tepat pada skala kanan NON-LINIER. --}}
        @foreach([0, 25, 50, 75, 100] as $percentTick)
          @php $percentTickY = $paretoPercentY((float) $percentTick); @endphp
          <line x1="{{ $paretoPlotRight - 5 }}" y1="{{ $percentTickY }}" x2="{{ $paretoPlotRight + 5 }}" y2="{{ $percentTickY }}" stroke="#93aba2" stroke-width="1" />
          <text x="{{ $paretoPlotRight + 14 }}" y="{{ $percentTickY + 4 }}" text-anchor="start" class="rms-pareto-axis-tick">{{ $percentTick }}%</text>
        @endforeach

        <text x="{{ $paretoPlotLeft }}" y="22" class="rms-pareto-axis-title">Jumlah record unik</text>
        <text x="{{ $paretoPlotRight + 14 }}" y="22" class="rms-pareto-axis-title">Kumulatif*</text>

        @foreach($paretoRows as $i => $row)
          @php
            $centerX = $paretoPlotLeft + $paretoSlotWidth * ($i + 0.5);
            // Kategori Lainnya adalah gabungan banyak kode. Jika melewati batas
            // grafik, tampilkan batang TERPOTONG dengan tanda putus, bukan
            // memperkecil semua batang alarm tunggal agar muat nilai gabungan.
            $paretoOtherTruncated = $row['other'] && (int) $row['count'] > $paretoYMax;
            $paretoDisplayCount = $paretoOtherTruncated ? $paretoYMax * 0.92 : (float) $row['count'];
            $height = $paretoPlotHeight * $paretoDisplayCount / $paretoYMax;
            $rectX = $centerX - $paretoBarWidth / 2;
            $rectY = $paretoPlotBottom - $height;
            // Setiap label menyajikan code/subcode lalu nama error (maksimal 3 baris).
            // Nama lengkap tetap dapat dilihat ketika batang diklik.
            $displayLabel = \Illuminate\Support\Str::limit((string) $row['label'], 18, '…');
            $displayName = \Illuminate\Support\Str::limit((string) $row['name'], 36, '…');
            $displayNameLines = [];
            for ($offset = 0; $offset < \Illuminate\Support\Str::length($displayName); $offset += 12) {
                $displayNameLines[] = \Illuminate\Support\Str::substr($displayName, $offset, 12);
            }
            $historySearchUrl = ! $row['other']
                ? request()->fullUrlWithQuery(['search' => $row['code'], 'history_page' => null, 'event_page' => null]).'#controller-alarm-history'
                : '#rms-pareto-numeric-details';
          @endphp
          <a href="{{ $historySearchUrl }}"
             class="rms-pareto-bar-action"
             data-rms-pareto-action
             data-pareto-code="{{ $row['label'] }}"
             data-pareto-name="{{ $row['name'] }}"
             data-pareto-count="{{ number_format($row['count'], 0, ',', '.') }}"
             data-pareto-percent="{{ number_format($row['percent'], 1, ',', '.') }}%"
             data-pareto-cumulative="{{ number_format($row['cumulative'], 1, ',', '.') }}%"
             data-pareto-rank="{{ $row['rank'] ?? 'Lainnya' }}"
             data-pareto-other="{{ $row['other'] ? '1' : '0' }}"
             aria-label="Lihat detail: {{ $row['label'] }}, {{ $row['name'] }}, {{ $row['count'] }} catatan">
            <rect x="{{ $rectX }}" y="{{ $rectY }}" width="{{ $paretoBarWidth }}" height="{{ max(1, $height) }}" rx="5" class="{{ $row['other'] ? 'rms-pareto-other-bar' : ($i === 0 ? 'rms-pareto-main-bar' : 'rms-pareto-bar') }}">
              <title>Klik untuk detail — {{ $row['label'] }}: {{ $row['name'] }} ({{ $row['count'] }} record, {{ $row['percent'] }}%)</title>
            </rect>
            @if($paretoOtherTruncated)
              {{-- Garis putus diagonal di batang Lainnya: nilainya terpotong, bukan 0.92 * max. --}}
              <path d="M {{ $rectX + 10 }} {{ $rectY + 19 }} l 12 -9 M {{ $rectX + 28 }} {{ $rectY + 19 }} l 12 -9 M {{ $rectX + 46 }} {{ $rectY + 19 }} l 12 -9"
                    fill="none" stroke="#ffffff" stroke-width="3.2" stroke-linecap="round" pointer-events="none" />
              <title>Batang Lainnya dipotong agar frekuensi setiap kode alarm tetap terbaca. Jumlah sebenarnya: {{ $row['count'] }}.</title>
            @endif
            <text x="{{ $centerX }}" y="{{ max(33, $rectY - 8) }}" text-anchor="middle" class="rms-pareto-bar-number">{{ number_format($row['count'], 0, ',', '.') }}</text>
            <text x="{{ $centerX }}" y="{{ $paretoPlotBottom + 26 }}" text-anchor="middle" class="rms-pareto-x-label">{{ $displayLabel }}</text>
            @foreach($displayNameLines as $lineNumber => $line)
              <text x="{{ $centerX }}" y="{{ $paretoPlotBottom + 45 + $lineNumber * 16 }}" text-anchor="middle" class="rms-pareto-x-alarm-name">{{ $line }}</text>
            @endforeach
          </a>
        @endforeach

        <polyline points="{{ $paretoLinePath }}" class="rms-pareto-cumulative-line" />
        {{-- Titik pertama berada TEPAT di sudut kanan atas bar pertama; titik lain mengikuti pemetaan persen kanan. --}}
        @foreach($paretoRows as $i => $row)
          @php
            [$dotX, $dotY] = array_map('floatval', explode(',', $paretoLinePoints[$i]));
          @endphp
          <circle cx="{{ $dotX }}" cy="{{ $dotY }}" r="5.5" class="rms-pareto-line-dot">
            <title>Kumulatif hingga {{ $row['label'] }}: {{ $row['cumulative'] }}%</title>
          </circle>
        @endforeach
        <text x="{{ ($paretoPlotLeft + $paretoPlotRight) / 2 }}" y="476" text-anchor="middle" class="rms-pareto-axis-bottom">Garis mulai di kanan atas batang pertama · *Skala kanan nonlinier · Angka kumulatif tetap asli.</text>
      </svg>
    </div>

    <p class="rms-pareto-click-hint">&#9432; Garis dimulai dari sudut kanan atas batang pertama, seperti referensi visual. Agar batang tetap besar, <strong>skala persentase kanan tidak linier</strong>; angka persentase pada titik/detail/tabel tetap hasil perhitungan asli. Klik batang untuk membuka detail. Batang abu-abu Lainnya yang bertanda miring adalah gabungan yang ditampilkan terpotong.</p>

    {{-- Dialog per alarm: menggunakan event delegation agar berfungsi setelah auto-refresh dashboard. --}}
    <dialog class="rms-pareto-dialog" data-rms-pareto-dialog aria-labelledby="rms-pareto-dialog-title">
      <div class="rms-pareto-dialog-header">
        <div>
          <span class="rms-pareto-dialog-eyebrow">DETAIL ALARM HISTORY</span>
          <h3 id="rms-pareto-dialog-title">Informasi Alarm</h3>
        </div>
        <button type="button" class="rms-pareto-dialog-close" data-rms-pareto-close aria-label="Tutup informasi alarm">&times;</button>
      </div>
      <dl class="rms-pareto-dialog-list">
        <div><dt>Kode / Subcode</dt><dd data-pareto-info="code">—</dd></div>
        <div><dt>Nama / Deskripsi Error</dt><dd data-pareto-info="name">—</dd></div>
        <div><dt>Peringkat</dt><dd data-pareto-info="rank">—</dd></div>
        <div><dt>Jumlah catatan histori unik</dt><dd data-pareto-info="count">—</dd></div>
        <div><dt>Kontribusi terhadap seluruh histori</dt><dd data-pareto-info="percent">—</dd></div>
        <div><dt>Persentase kumulatif</dt><dd data-pareto-info="cumulative">—</dd></div>
      </dl>
      <p class="rms-pareto-dialog-note" data-pareto-info="note">Jumlah menunjukkan catatan histori unik pada filter, bukan frekuensi polling atau durasi downtime.</p>
      <div class="rms-pareto-dialog-actions">
        <a class="btn" data-pareto-info-history href="#controller-alarm-history">Cari kode di Alarm History</a>
        <button class="btn primary" type="button" data-rms-pareto-close>Tutup</button>
      </div>
    </dialog>

    <details class="rms-pareto-details" id="rms-pareto-numeric-details">
      <summary>Lihat rincian angka Pareto ({{ count($paretoRows) }} baris)</summary>
      <div class="table-wrap">
        <table class="data-table rms-pareto-table">
          <thead><tr><th>Ranking</th><th>Alarm code / subcode</th><th>Alarm</th><th>Jumlah</th><th>% Total</th><th>% Kumulatif</th></tr></thead>
          <tbody>
            @foreach($paretoRows as $row)
              <tr>
                <td>{{ $row['rank'] ?? '—' }}</td>
                <td><strong>{{ $row['label'] }}</strong></td>
                <td>{{ $row['name'] }}</td>
                <td>{{ number_format($row['count'], 0, ',', '.') }}</td>
                <td>{{ number_format($row['percent'], 1, ',', '.') }}%</td>
                <td>{{ number_format($row['cumulative'], 1, ',', '.') }}%</td>
              </tr>
            @endforeach
          </tbody>
        </table>
      </div>
    </details>
  @endif

  <p class="rms-pareto-footnote">
    <strong>Dasar perhitungan:</strong> satu record histori per fingerprint unik robot; pembacaan polling yang sama tidak dihitung ulang.
    Periode mengikuti waktu <em>pertama terambil RMS</em> (first_observed_at), bukan waktu asli alarm di controller.
    Filter robot, kategori dan pencarian berlaku; filter <em>Status</em> hanya berlaku untuk tabel Alarm Events, bukan Pareto histori.
    Data Pareto bukan bukti jumlah breakdown fisik atau downtime tanpa verifikasi lapangan.
  </p>
</section>

{{-- Registrasi hanya sekali: event tetap bekerja sesudah #rms-dynamic-content diganti dashboard.js. --}}
<script>
(() => {
  if (window.__rmsParetoClickBound) return;
  window.__rmsParetoClickBound = true;

  document.addEventListener('click', (event) => {
    const trigger = event.target.closest?.('[data-rms-pareto-action]');
    if (trigger) {
      const panel = trigger.closest('.rms-alarm-pareto');
      const dialog = panel?.querySelector('[data-rms-pareto-dialog]');
      if (!dialog || typeof dialog.showModal !== 'function') return; // fallback: href to history
      event.preventDefault();
      const values = {
        code: trigger.dataset.paretoCode || '—',
        name: trigger.dataset.paretoName || '—',
        rank: trigger.dataset.paretoRank || '—',
        count: trigger.dataset.paretoCount || '0',
        percent: trigger.dataset.paretoPercent || '0%',
        cumulative: trigger.dataset.paretoCumulative || '0%',
      };
      Object.entries(values).forEach(([key, value]) => {
        const node = dialog.querySelector(`[data-pareto-info="${key}"]`);
        if (node) node.textContent = value; // never inject alarm text as HTML
      });
      const isOther = trigger.dataset.paretoOther === '1';
      const note = dialog.querySelector('[data-pareto-info="note"]');
      if (note) note.textContent = isOther
        ? 'Lainnya adalah gabungan semua kode alarm di luar Top 10; bukan satu error tertentu.'
        : 'Jumlah adalah record histori unik (fingerprint) pada filter, bukan jumlah polling atau durasi downtime.';
      const historyLink = dialog.querySelector('[data-pareto-info-history]');
      if (historyLink) {
        historyLink.hidden = isOther;
        if (!isOther) historyLink.href = trigger.getAttribute('href') || '#controller-alarm-history';
      }
      if (!dialog.open) dialog.showModal();
      return;
    }
    const closer = event.target.closest?.('[data-rms-pareto-close]');
    if (closer) {
      closer.closest('dialog')?.close();
      return;
    }
    if (event.target.matches?.('dialog[data-rms-pareto-dialog]')) event.target.close();
  });
})();
</script>

<style>
/* Tambahan interaksi Pareto V2 — hanya scoped untuk panel Alarm Center. CSS lama tetap dipakai. */
.rms-alarm-pareto .rms-pareto-bar-action{cursor:pointer;outline:none}
.rms-alarm-pareto .rms-pareto-bar-action:focus .rms-pareto-bar,
.rms-alarm-pareto .rms-pareto-bar-action:focus .rms-pareto-main-bar,
.rms-alarm-pareto .rms-pareto-bar-action:focus .rms-pareto-other-bar{stroke:#172f28;stroke-width:3}
.rms-alarm-pareto .rms-pareto-bar-action:hover .rms-pareto-bar,
.rms-alarm-pareto .rms-pareto-bar-action:hover .rms-pareto-main-bar{fill:#0b6147}
.rms-alarm-pareto .rms-pareto-bar-action:hover .rms-pareto-other-bar{fill:#8c9b93}
.rms-alarm-pareto .rms-pareto-x-alarm-name{font-size:13px;fill:#647b70;font-weight:500}
.rms-alarm-pareto .rms-pareto-bar-action:hover .rms-pareto-x-alarm-name{text-decoration:underline}
.rms-alarm-pareto .rms-pareto-click-hint{color:#33735b;background:#f4faf6;border:1px solid #e0eee4;border-radius:8px;padding:10px 12px;font-size:12px;margin:12px 0 5px}
.rms-pareto-dialog{border:1px solid #d6e6dc;background:#fff;color:#193a2f;border-radius:16px;box-shadow:0 22px 65px #102d2760;width:min(550px,calc(100vw - 28px));max-height:86vh;padding:0;overflow:auto}
.rms-pareto-dialog::backdrop{background:#102c287d;backdrop-filter:blur(2px)}
.rms-pareto-dialog-header{display:flex;align-items:center;justify-content:space-between;gap:15px;padding:21px 24px 17px;background:#f5faf7;border-bottom:1px solid #e2eee6}
.rms-pareto-dialog-header h3{margin:4px 0 0;font-size:20px;color:#17362d}
.rms-pareto-dialog-eyebrow{font-size:10px;color:#298362;font-weight:800;letter-spacing:.12em}
.rms-pareto-dialog-close{appearance:none;border:1px solid #d5e6da;border-radius:8px;background:white;font-size:25px;line-height:1;width:35px;height:35px;color:#355e4b;cursor:pointer}
.rms-pareto-dialog-list{padding:8px 24px 0;margin:0}
.rms-pareto-dialog-list>div{padding:11px 0;border-bottom:1px solid #edf2ee;display:grid;grid-template-columns:180px minmax(0,1fr);gap:9px}
.rms-pareto-dialog-list dt{font-size:11px;color:#74887c;font-weight:650}
.rms-pareto-dialog-list dd{margin:0;font-size:13px;color:#204534;font-weight:700;overflow-wrap:anywhere}
.rms-pareto-dialog-note{margin:12px 24px;color:#667e70;font-size:11px;line-height:1.6}
.rms-pareto-dialog-actions{display:flex;justify-content:flex-end;gap:8px;align-items:center;flex-wrap:wrap;padding:0 24px 22px}
.rms-pareto-dialog-actions [hidden]{display:none!important}
@media(max-width:520px){.rms-pareto-dialog-list>div{grid-template-columns:1fr;gap:3px}.rms-pareto-dialog-header,.rms-pareto-dialog-list,.rms-pareto-dialog-actions{padding-left:16px;padding-right:16px}.rms-pareto-dialog-note{margin-left:16px;margin-right:16px}}
</style>
