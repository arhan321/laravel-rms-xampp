@php
  $guideId = $guideId ?? 'rms-technician-guide';
  $guideOpen = $guideOpen ?? false;
@endphp

<details class="rms-tech-guide" id="{{ $guideId }}" {{ $guideOpen ? 'open' : '' }}>
  <summary>
    <span class="rms-tech-guide-icon" aria-hidden="true">?</span>
    <span class="rms-tech-guide-heading">
      <strong>Technician reading guide</strong>
      <small>Penjelasan istilah yang tampil pada Robot Cockpit dan cara membaca statusnya.</small>
    </span>
    <span class="rms-tech-guide-toggle"><span class="when-closed">Open guide</span><span class="when-open">Close guide</span></span>
  </summary>

  <div class="rms-tech-guide-body">
    <div class="rms-tech-term-grid">
      <article>
        <strong>Motor Torque / Torque Value</strong>
        <p>Nilai torsi yang diterima dari controller untuk setiap axis. Sebelum dicocokkan dengan tampilan pendant, perlakukan angka ini sebagai nilai monitor controller dan jangan memberinya satuan Nm atau persen.</p>
      </article>
      <article>
        <strong>Measurement</strong>
        <p>Menampilkan nilai torsi aktual pada rentang waktu yang dipilih. Garis Warning dan Critical hanya muncul jika threshold axis tersebut sudah diverifikasi.</p>
      </article>
      <article>
        <strong>Variation Δ</strong>
        <p>Selisih nilai terhadap sampel pertama pada rentang waktu yang dipilih. Mode ini membantu melihat perubahan pola, bukan menentukan kerusakan secara otomatis.</p>
      </article>
      <article>
        <strong>Torque Magnitude <span>(|value|)</span></strong>
        <p>Besarnya nilai tanpa memperhitungkan tanda plus atau minus. Contoh: nilai <b>-120</b> mempunyai magnitude <b>120</b> untuk dibandingkan dengan threshold.</p>
      </article>
      <article>
        <strong>Threshold</strong>
        <p>Batas Warning dan Critical yang dipakai RMS untuk monitoring. Threshold tidak mengubah parameter controller, tidak menghentikan robot, dan bukan alarm bawaan YRC1000.</p>
      </article>
      <article>
        <strong>Hysteresis</strong>
        <p>Jarak pemulihan di bawah Warning threshold sebelum event ditutup. Fungsinya mencegah status berubah bolak-balik saat nilai berada di sekitar batas.</p>
      </article>
      <article>
        <strong>Fresh / Stale / Unknown</strong>
        <p>Fresh berarti data terbaru masih diterima. Stale berarti data terlambat diperbarui dan harus dianggap Unknown; status ini tidak otomatis berarti robot rusak.</p>
      </article>
      <article>
        <strong>Position Pulse</strong>
        <p>Posisi axis dalam hitungan controller/encoder. Pulse bukan derajat dan bukan milimeter, kecuali sudah dikonversi menggunakan parameter robot yang sesuai.</p>
      </article>
      <article>
        <strong>Verified / Approved</strong>
        <p>Data memiliki sumber dan nama pemeriksa yang dapat ditelusuri di RMS. Status ini merupakan verifikasi internal, bukan sertifikasi resmi dari Yaskawa.</p>
      </article>
      <article>
        <strong>Speed Reducer Evidence</strong>
        <p>Data kondisi atau remaining life yang disalin dari laporan OEM atau hasil inspeksi teknisi. RMS tidak menghitung umur reducer hanya dari Motor Torque.</p>
      </article>
      <article>
        <strong>Threshold Event vs Controller Alarm</strong>
        <p>Threshold Event dibuat oleh RMS saat batas monitoring terlewati. Controller Alarm berasal dari YRC1000. Keduanya harus dibaca sebagai informasi yang berbeda.</p>
      </article>
      <article>
        <strong>Axis S · L · U · R · B · T</strong>
        <p>Penamaan axis Yaskawa: S (base/swivel), L (lower arm), U (upper arm), R (wrist roll), B (wrist bend), dan T (wrist twist/tool).</p>
      </article>
    </div>

    <div class="rms-tech-example" aria-label="Contoh penilaian threshold">
      <div>
        <span>READING EXAMPLE</span>
        <strong>Torque Value −120 → Magnitude |value| = 120</strong>
      </div>
      <div class="rms-tech-example-flow">
        <span>Warning 100</span>
        <span class="is-active">Result: WARNING</span>
        <span>Critical 150</span>
      </div>
      <p>Dengan Hysteresis 5%, Warning Event dapat pulih setelah <b>3 valid samples berturut-turut</b> berada di bawah 95. Instant Axis Status dapat berbeda karena hanya menunjukkan pembacaan terbaru.</p>
    </div>

    <div class="rms-tech-safety">
      <strong>Maintenance decision</strong>
      <p>Status pada RMS adalah indikator awal. Konfirmasi melalui pendant/controller, Alarm History, job yang sedang berjalan, dan inspeksi fisik sebelum mengambil tindakan maintenance.</p>
    </div>
  </div>
</details>
