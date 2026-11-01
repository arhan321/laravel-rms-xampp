<article class="rms-cm-card {{ !$item->is_active ? 'rms-cm-inactive' : '' }}" id="countermeasure-{{ $item->id }}">
  <div class="rms-cm-heading"><h3>{{ $item->title }}</h3><span class="badge rms-badge {{ $item->is_active ? 'connected' : 'unknown' }}">{{ $item->is_active ? 'AKTIF' : 'TIDAK BERLAKU' }}</span></div>
  <p class="rms-cm-meta">{{ $item->source_type === 'yaskawa_manual' ? 'Referensi Yaskawa — input manual' : 'Pengalaman jalur — laporan pengguna' }} · Versi {{ $item->version }}</p>
  @unless($item->is_active)<div class="rms-note">Catatan ini dinonaktifkan dan tidak digunakan sebagai rekomendasi.</div>@endunless
  <dl class="rms-cm-text">
    <dt>{{ $item->source_type === 'yaskawa_manual' ? 'Alarm Content' : 'Gejala / kondisi' }}</dt><dd>{{ $item->alarm_content ?: 'Belum dicatat' }}</dd>
    <dt>{{ $item->source_type === 'yaskawa_manual' ? 'Cause' : 'Temuan / dugaan penyebab' }}</dt><dd>{{ $item->cause ?: 'Belum dicatat' }}</dd>
    <dt>Measure / countermeasure</dt><dd class="rms-cm-measure">{{ $item->measure }}</dd>
    @if($item->source_type === 'field_experience')<dt>Hasil yang dilaporkan</dt><dd>{{ $resultLabels[$item->result] ?? $item->result }}@if($item->result_notes)<br>{{ $item->result_notes }}@endif</dd>@endif
    @if($item->source_reference)<dt>Referensi</dt><dd>{{ $item->source_reference }}@if($item->source_revision)<br>Revisi: {{ $item->source_revision }}@endif</dd>@endif
  </dl>
  @if($item->source_url)<a href="{{ $item->source_url }}" target="_blank" rel="noopener noreferrer">Buka sumber ↗</a>@endif
  <div class="rms-cm-meta rms-cm-scope-note">{{ $item->scope_robot_id === null ? 'Model controller yang sama' : 'Khusus robot ini' }} · {{ $item->all_subcodes ? 'Semua subcode' : 'Subcode '.($item->subcode ?? 'tidak tersedia') }} · Firmware: {{ $item->firmware_version ?? 'semua' }}</div>
  <p class="rms-cm-meta">Terakhir dicatat {{ $item->reported_by }}{{ $item->work_area ? ' · '.$item->work_area : '' }}<br>{{ $item->updated_at?->timezone('Asia/Jakarta')->format('d M Y H:i:s') }} WIB</p>
  <p class="rms-muted-small">Sumber kejadian: {{ data_get($item->origin, 'kind') }} #{{ data_get($item->origin, 'record') }} · Robot #{{ data_get($item->origin, 'robot_id') }} · Waktu controller: {{ data_get($item->origin, 'controller_time_raw') ?? 'tidak tersedia' }}</p>
  @if($writesEnabled)<a class="btn" href="{{ route('rms.alarms.countermeasures.edit', [...$routeParams, 'countermeasure' => $item->id]) }}#countermeasure-form">Edit / riwayat perubahan</a>@endif
</article>
