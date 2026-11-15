<section class="cp-panel sr-panel" id="speed-reducer-diagnosis"
         data-speed-reducer-url="{{ route('rms.robots.cockpit.speed-reducer', $robot) }}">
  <header>
    <div>
      <span class="cp-kicker">PREVENTIVE MAINTENANCE</span>
      <h3>Speed Reducer Diagnosis</h3>
      <p class="sr-note">Tren harian dan sisa umur berdasarkan hasil diagnosis Yaskawa yang diimpor untuk robot ini.</p>
    </div>
    <a class="cp-action" href="{{ route('rms.robots.configuration', $robot) }}#speed-reducer-oem">Impor hasil Yaskawa</a>
  </header>

  @if(session('reducer_oem_success'))
    <p class="sr-status" role="status">{{ session('reducer_oem_success') }}</p>
  @endif

  <div class="sr-controls">
    <label>Control Group <select data-sr-group disabled><option>—</option></select></label>
    <label>Axis <select data-sr-axis disabled><option value="">Semua axis</option></select></label>
    <span class="sr-status" data-sr-status role="status">Memuat hasil diagnosis…</span>
  </div>
  <div class="sr-grid">
    <section class="sr-chart-card" aria-labelledby="sr-trend-heading">
      <h4 id="sr-trend-heading">Speed Reducer</h4>
      <div class="cp-segment sr-modes" role="group" aria-label="Mode grafik Speed Reducer">
        <button class="selected" type="button" data-sr-mode="measurement_pct" aria-pressed="true">Measurement</button>
        <button type="button" data-sr-mode="variation_pp" aria-pressed="false">Variation</button>
      </div>
      <div class="sr-plot">
        <svg data-sr-trend viewBox="0 0 560 275" role="img" aria-label="Tren Speed Reducer per tanggal" hidden></svg>
        <p data-sr-trend-empty>Belum ada hasil diagnosis yang diimpor.</p>
      </div>
    </section>
    <section class="sr-chart-card" aria-labelledby="sr-life-heading">
      <h4 id="sr-life-heading">Speed Reducer Life</h4>
      <p class="sr-life-caption">Jam operasi dibanding sisa jam sampai penggantian.</p>
      <div class="sr-plot">
        <svg data-sr-life viewBox="0 0 560 275" role="img" aria-label="Sisa umur Speed Reducer terhadap jam operasi" hidden></svg>
        <p data-sr-life-empty>Belum ada hasil diagnosis yang diimpor.</p>
      </div>
    </section>
  </div>
  <div class="sr-legend" data-sr-legend aria-label="Warna per axis"></div>
  <p class="sr-note">Pilih satu axis untuk melihat batas dari catatan terakhir, jika diberikan pada sumber.</p>
  <details class="sr-evidence" data-sr-evidence hidden>
    <summary>Lihat data terakhir dan sumber per axis</summary>
    <div class="cp-table-wrap">
      <table class="cp-table">
        <thead><tr><th>Axis</th><th>Tanggal WIB</th><th>Measurement (%)</th><th>Variation (poin %)</th><th>Jam operasi</th><th>Sisa umur (jam)</th><th>Rujukan laporan</th></tr></thead>
        <tbody data-sr-evidence-rows></tbody>
      </table>
    </div>
  </details>
</section>
