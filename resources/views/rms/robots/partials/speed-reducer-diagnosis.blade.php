<section class="cp-panel sr-panel" id="speed-reducer-diagnosis"
         data-speed-reducer-url="{{ route('rms.robots.cockpit.speed-reducer', $robot, false) }}">
  <header>
    <div>
      <span class="cp-kicker">PREVENTIVE MAINTENANCE</span>
      <h3>Speed Reducer Diagnosis</h3>
      <p class="sr-note">Tren torque R1 terisi otomatis dari telemetry RMS. Sisa umur ditampilkan berdasarkan hasil diagnosis atau inspeksi terverifikasi.</p>
    </div>
    <a class="cp-action" href="{{ route('rms.robots.configuration', $robot) }}#reducer-config">Input bukti inspeksi</a>
  </header>

  @if(session('reducer_oem_success'))
    <p class="sr-status" role="status">{{ session('reducer_oem_success') }}</p>
  @endif

  <div class="sr-controls">
    <label>Control Group <select data-sr-group disabled><option>—</option></select></label>
    <label>Axis <select data-sr-axis disabled><option value="">Semua axis</option></select></label>
    <label>Sumber tren <select data-sr-source disabled>
      <option value="telemetry">Telemetry RMS</option>
      <option value="oem">Diagnosis Yaskawa</option>
    </select></label>
    <span class="sr-status" data-sr-status role="status">Menyiapkan grafik reducer…</span>
    <noscript><p>Aktifkan JavaScript untuk memuat grafik reducer.</p></noscript>
  </div>
  <div class="sr-grid">
    <section class="sr-chart-card" aria-labelledby="sr-trend-heading">
      <h4 id="sr-trend-heading">Speed Reducer</h4>
      <div class="cp-segment sr-modes" role="group" aria-label="Mode grafik Speed Reducer">
        <button class="selected" type="button" data-sr-mode="measurement_pct" aria-pressed="true">Measurement</button>
        <button type="button" data-sr-mode="variation_pp" aria-pressed="false">Variation</button>
      </div>
      <p class="sr-life-caption" data-sr-trend-caption>Telemetry: rata-rata magnitudo dan rentang torque harian dalam satuan SDK.</p>
      <div class="sr-plot">
        <svg data-sr-trend viewBox="0 0 560 275" role="img" aria-label="Tren Speed Reducer per tanggal" hidden></svg>
        <p data-sr-trend-empty>Menunggu data torque yang valid.</p>
      </div>
    </section>
    <section class="sr-chart-card" aria-labelledby="sr-life-heading">
      <h4 id="sr-life-heading">Speed Reducer Life</h4>
      <p class="sr-life-caption" data-sr-life-caption>Sisa jam sampai penggantian dari bukti terverifikasi.</p>
      <div class="sr-plot">
        <svg data-sr-life viewBox="0 0 560 275" role="img" aria-label="Histori sisa umur Speed Reducer" hidden></svg>
        <p data-sr-life-empty>Belum ada data sisa umur terverifikasi.</p>
      </div>
    </section>
  </div>
  <div class="sr-legend" data-sr-legend aria-label="Warna per axis"></div>
  <p class="sr-note">Pilih satu axis untuk melihat batas dari catatan terakhir, jika diberikan pada sumber.</p>
  <details class="sr-evidence" data-sr-evidence hidden>
    <summary>Lihat data terakhir dan sumber per axis</summary>
    <div class="cp-table-wrap">
      <table class="cp-table">
        <thead><tr><th>Axis</th><th>Tanggal WIB</th><th data-sr-measurement-heading>Measurement</th><th data-sr-variation-heading>Variation</th><th data-sr-count-heading>Sampel</th><th data-sr-operating-heading hidden>Jam operasi</th><th data-sr-life-heading hidden>Sisa umur (jam)</th><th>Rujukan</th></tr></thead>
        <tbody data-sr-evidence-rows></tbody>
      </table>
    </div>
  </details>
</section>
