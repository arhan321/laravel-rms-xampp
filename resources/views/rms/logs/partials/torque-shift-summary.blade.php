@php
  $absolute = $torqueSummary['mode'] === 'absolute';
  $periodLabel = match ($filters['preset']) {
    'shift_pagi' => 'Shift 1',
    'shift_2' => 'Shift 2 · lintas hari',
    'full_day' => 'Satu hari',
    default => 'Rentang manual',
  };
@endphp
<section class="rms-torque-summary" aria-labelledby="rms-torque-summary-title" data-torque-summary data-torque-mode="{{ $torqueSummary['mode'] }}">
  <div class="rms-torque-summary-heading">
    <div><h3 id="rms-torque-summary-title">Ringkasan torque per {{ in_array($filters['preset'], ['shift_pagi', 'shift_2'], true) ? 'shift' : 'rentang waktu' }}</h3>
      <p>{{ $periodLabel }} · {{ $filters['from'] }} hingga {{ $filters['to'] }} WIB</p></div>
    <span class="rms-torque-summary-badge">{{ $absolute ? 'NILAI ABSOLUT' : 'NILAI BERTANDA' }}</span>
  </div>
  <p class="rms-torque-summary-note">Dihitung dari seluruh sampel torque valid pada robot dan rentang terpilih, termasuk data di halaman lain. Pembacaan gagal, simulasi, serta nilai axis yang kosong/tidak valid tidak dihitung. Nilai nol tetap dihitung.</p>
  <p class="rms-torque-summary-note">{{ $absolute ? 'Rata-rata dan maksimum memakai besar torque |nilai|, sehingga arah positif/negatif tidak saling menghapus.' : 'Rata-rata mempertahankan tanda +/−. Maksimum adalah angka tertinggi; pada nilai negatif seluruhnya, maksimum adalah yang paling dekat nol.' }} Rata-rata dihitung per sampel, bukan berbobot waktu. Nilai mengikuti controller dan belum dikonversi ke N·m; cocokkan skalanya dengan pendant.</p>
  @unless($torqueSummary['has_data'])
    <div class="rms-torque-summary-empty" role="status">Belum ada sampel torque valid pada pilihan ini. Nilai yang belum tersedia ditampilkan sebagai —.</div>
  @endunless
  <div class="rms-torque-summary-grid">
    @foreach($torqueSummary['groups'] as $group)
      <details class="rms-torque-robot" data-torque-robot="{{ $group['robot_id'] }}" @if(count($torqueSummary['groups']) === 1) open @endif>
        <summary><span><strong>{{ $group['robot_code'] }}</strong><small>{{ $group['robot_name'] }}</small></span><span class="rms-torque-robot-state">{{ $group['has_data'] ? 'Lihat 6 axis' : 'Belum ada data' }}</span></summary>
        <div class="table-wrap">
          <table class="data-table rms-torque-summary-table">
            <caption class="rms-torque-summary-note">{{ $group['robot_code'] }} · {{ $absolute ? 'Besar torque' : 'Torque bertanda' }} · nilai controller</caption>
            <thead><tr><th scope="col">Axis</th><th scope="col">Sampel valid</th><th scope="col">{{ $absolute ? 'Rata-rata |torque|' : 'Rata-rata torque' }}</th><th scope="col">{{ $absolute ? 'Maksimum |torque|' : 'Nilai torque tertinggi' }}</th></tr></thead>
            <tbody>
              @foreach($group['axes'] as $axis => $reading)
                <tr data-torque-axis="{{ $axis }}"><th scope="row">{{ $axis }}</th><td>{{ number_format($reading['count'], 0, ',', '.') }}</td><td>{{ $reading['average'] === null ? '—' : number_format($reading['average'], 4, ',', '.') }}</td><td>{{ $reading['maximum'] === null ? '—' : number_format($reading['maximum'], 4, ',', '.') }}</td></tr>
              @endforeach
            </tbody>
          </table>
        </div>
      </details>
    @endforeach
  </div>
  <p class="rms-torque-summary-note rms-torque-summary-footer">Ringkasan diperbarui ketika filter diterapkan atau halaman dimuat ulang. Download Excel di atas mengekspor detail log; ringkasan ini dihitung dari seluruh sampel pada rentang yang dipilih.</p>
</section>
