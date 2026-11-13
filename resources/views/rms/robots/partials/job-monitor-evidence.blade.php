{{-- Optional read-only C# job evidence card. Does not mutate the existing Job → Torque analysis. --}}
<style>
.jobtorque .jt-evidence{margin:16px 0 22px}
.jobtorque .jt-evidence .jt-read-status{font-size:11px;font-weight:750;padding:8px 11px;border-radius:50px;background:#edf2ef;color:#53665b}
.jobtorque .jt-read-status.fresh{background:#e5f4e9;color:#206e46}
.jobtorque .jt-read-status.stale,.jobtorque .jt-read-status.not_polled,.jobtorque .jt-read-status.legacy_tag_only{background:#fff3db;color:#8a631c}
.jobtorque .jt-read-status.read_failed,.jobtorque .jt-read-status.invalid_job{background:#ffeded;color:#a03939}
.jobtorque .jt-read-status.simulated{background:#fff1df;color:#885a1f}
.jobtorque .jt-evidence-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:11px;margin:14px 0}
.jobtorque .jt-evidence-cell{background:#f7faf8;border:1px solid #e6eee9;border-radius:10px;padding:14px;min-width:0}
.jobtorque .jt-evidence-cell small{color:#5e7566;display:block;font-size:11px;margin-bottom:6px}
.jobtorque .jt-evidence-cell strong{font-size:16px;overflow-wrap:anywhere;color:#244d37}
.jobtorque .jt-param-note{font-size:11px;color:#64776b;white-space:normal}
.jobtorque .jt-param-value{white-space:normal}
.jobtorque .jt-param-label{font-weight:700;color:#2d5c41}
.jobtorque .jt-evidence-note{font-size:12px;color:#627769;line-height:1.7;margin:8px 0}
.jobtorque .jt-parameter-caption{font-size:11px;color:#75887b}
@media(max-width:1050px){.jobtorque .jt-evidence-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(max-width:560px){.jobtorque .jt-evidence-grid{grid-template-columns:1fr}}
</style>
<section class="jt-section jt-evidence" aria-label="Job read evidence">
    <div class="jt-header">
        <div>
            <h2>Job Monitoring — Pembacaan C# Collector</h2>
            <p class="jt-muted">Snapshot pembacaan paling akhir, terpisah dari filter histori torque di bawah.</p>
        </div>
        <span class="jt-read-status {{ $evidence['status'] }}" title="{{ $evidence['source'] }}">{{ $evidence['label'] }}</span>
    </div>
    <div class="jt-evidence-grid">
        <div class="jt-evidence-cell"><small>Nama Job {{ $evidence['status'] === 'fresh' ? '(fresh)' : '(last read)' }}</small><strong>{{ $evidence['job_name'] ?: '—' }}</strong></div>
        <div class="jt-evidence-cell"><small>Job Line</small><strong>{{ $evidence['line'] ?? '—' }}</strong></div>
        <div class="jt-evidence-cell"><small>Job Step</small><strong>{{ $evidence['step'] ?? '—' }}</strong></div>
        <div class="jt-evidence-cell"><small>Speed Override</small><strong>{{ $evidence['speed_override_percent'] === null ? '—' : number_format((float) $evidence['speed_override_percent'], 1, ',', '.') . '%' }}</strong></div>
        <div class="jt-evidence-cell"><small>Controller Running (state SDK)</small><strong>{{ $evidence['controller_running'] === null ? 'UNKNOWN' : ($evidence['controller_running'] ? 'RUN' : 'NOT RUNNING') }}</strong></div>
        <div class="jt-evidence-cell"><small>Sampled At (WIB)</small><strong style="font-size:13px">{{ $evidence['sampled_wib'] ?? '—' }}</strong></div>
        <div class="jt-evidence-cell"><small>Received At (WIB)</small><strong style="font-size:13px">{{ $evidence['received_wib'] ?? '—' }}</strong></div>
        <div class="jt-evidence-cell"><small>Umur Data</small><strong>{{ $evidence['received_age_seconds'] === null ? '—' : number_format($evidence['received_age_seconds'], 0, ',', '.') . ' detik' }}</strong></div>
    </div>
    <p class="jt-evidence-note"><strong>Last known job (historis):</strong> {{ $evidence['last_known_job']['name'] ?? 'Belum ada' }}{{ !empty($evidence['last_known_job']['sampled_wib']) ? ' · ' . $evidence['last_known_job']['sampled_wib'] : '' }}. Status RUN bukan bukti hitungan eksekusi job. Data STALE tidak boleh ditafsirkan sebagai posisi job saat ini.</p>
    @if(! $evidence['envelope_present'])
        <p class="jt-evidence-note">Metadata <code>raw_payload.job_monitor</code> belum ada pada sampel terakhir. Ini normal sebelum mode <code>RMS_JOB_MONITOR_EVERY_SAMPLE=1</code> diaktifkan atau ketika menggunakan collector lama.</p>
    @endif
    @if($evidence['is_simulated'])
        <p class="jt-evidence-note">Data terakhir bersumber dari simulasi. Jangan disamakan dengan pembacaan robot DOJO sebenarnya.</p>
    @endif
    <div class="jt-scroll">
        <table>
            <thead><tr><th>No.</th><th>Parameter YRC1000</th><th>Nilai yang tersedia di RMS</th><th>Keterangan / sumber</th></tr></thead>
            <tbody>
            @foreach($evidence['parameters'] as $index => $parameter)
                <tr>
                    <td>{{ $index + 1 }}</td>
                    <td class="jt-param-label">{{ $parameter['label'] }}</td>
                    <td class="jt-param-value">{{ $parameter['value'] }}</td>
                    <td class="jt-param-note">{{ $parameter['note'] }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
    <p class="jt-parameter-caption">Kolom kosong ditampilkan dengan simbol —. Playback/Moving Time di atas adalah total robot; AVG/MAX torque adalah statistik sampel RMS di tabel bawah, bukan data OEM JOB DIAGNOSIS langsung.</p>
</section>
