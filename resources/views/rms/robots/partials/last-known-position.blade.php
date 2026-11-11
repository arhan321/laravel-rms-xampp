{{-- Read-only panel. Does not change robot pose or technician's verified home reference. --}}
<section class="panel rms-section-spaced rms-last-position-panel"
         data-rms-last-position
         data-rms-last-position-timestamp="{{ $position['sampled_at'] ?? '' }}"
         aria-label="Current and Last Known Position">
  <div class="panel-header">
    <div>
      <h2>Current &amp; Last Known Position</h2>
      <p>Pulse keenam axis dari pembacaan YMConnect. Tidak memerlukan referensi Home Position.</p>
    </div>
    <span class="badge rms-badge {{ ($position['is_fresh'] ?? false) ? 'connected' : 'unknown' }}"
          data-rms-position-status>{{ $position['label'] }}</span>
  </div>

  <p class="rms-last-position-message" data-rms-position-reason>{{ $position['reason'] }}</p>
  <div class="rms-last-position-meta">
    <div><span>Terakhir berhasil dibaca (WIB)</span><strong data-rms-position-last-sampled>{{ $position['sampled_wib'] ?? 'Belum ada' }}</strong></div>
    <div><span>Data diterima RMS (WIB)</span><strong data-rms-position-last-received>{{ $position['received_wib'] ?? 'Belum ada' }}</strong></div>
    <div><span>Umur catatan saat halaman dibuka</span><strong data-rms-position-age>{{ isset($position['age_seconds']) ? number_format($position['age_seconds'], 0, ',', '.') . ' detik' : '—' }}</strong></div>
  </div>

  <div class="table-wrap">
    <table class="data-table rms-last-position-table">
      <thead>
        <tr>
          <th>Axis</th>
          <th>Posisi saat ini (pulse)</th>
          <th>Posisi terakhir valid (pulse)</th>
        </tr>
      </thead>
      <tbody>
        @foreach(['S', 'L', 'U', 'R', 'B', 'T'] as $axis)
          <tr>
            <td><strong>{{ $axis }}</strong></td>
            <td class="mono" data-rms-position-current="{{ $axis }}">{{ isset($position['current_axes'][$axis]) ? number_format($position['current_axes'][$axis], 0, ',', '.') : '—' }}</td>
            <td class="mono" data-rms-position-last="{{ $axis }}">{{ isset($position['last_axes'][$axis]) ? number_format($position['last_axes'][$axis], 0, ',', '.') : '—' }}</td>
          </tr>
        @endforeach
      </tbody>
    </table>
  </div>
  <p class="rms-last-position-warning">
    <strong>Perhatian:</strong> Last Known Position adalah pembacaan valid terakhir, bukan posisi fisik robot yang dapat dijamin ketika koneksi terputus.
    Status <strong>AT HOME</strong> tetap dihitung secara terpisah menggunakan referensi home yang disetujui teknisi.
  </p>
  <a class="btn" href="{{ route('rms.robots.encoder', $robot) }}">Lihat histori posisi, perubahan pulse, dan export CSV</a>
</section>
<style>
  /* Scoped only to Current & Last Known Position; existing RMS styles remain unchanged. */
  .rms-last-position-panel .rms-last-position-message{color:#517065;font-size:12px;line-height:1.6;margin:10px 0 15px}
  .rms-last-position-panel .rms-last-position-meta{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px;margin:12px 0 18px}
  .rms-last-position-panel .rms-last-position-meta>div{border:1px solid #e0e9e3;background:#f8faf8;border-radius:10px;padding:11px 13px;min-width:0}
  .rms-last-position-panel .rms-last-position-meta span{display:block;color:#788b7f;font-size:11px;margin-bottom:7px}
  .rms-last-position-panel .rms-last-position-meta strong{font-size:13px;color:#214d39;overflow-wrap:anywhere}
  .rms-last-position-panel .rms-last-position-table td[data-rms-position-last]{font-weight:650;color:#286348}
  .rms-last-position-panel .rms-last-position-warning{font-size:11px;line-height:1.6;color:#587065;background:#f3f8f4;border:1px solid #dceade;padding:12px;border-radius:9px;margin:12px 0}
  @media(max-width:840px){.rms-last-position-panel .rms-last-position-meta{grid-template-columns:1fr}}
</style>
