@extends('rms.layouts.app')
@section('heading', 'Job Monitoring → Torque')
@section('subtitle', 'Torque enam axis berdasarkan sampel yang memiliki identitas job pada controller.')
@section('auto_refresh', 'off')
@section('content')
<style>
.jobtorque{--jt-green:#2e7959;--jt-muted:#65766d;max-width:1500px;margin:0 auto;color:#21362c}
.jobtorque *{box-sizing:border-box}.jobtorque .jt-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin:16px 0 22px}
.jobtorque .jt-card,.jobtorque .jt-section{background:#fff;border:1px solid #dfe9e3;border-radius:14px;padding:19px;box-shadow:0 3px 12px rgba(20,60,35,.03)}
.jobtorque .jt-card small{display:block;color:var(--jt-muted);font-size:12px}.jobtorque .jt-card strong{display:block;font-size:26px;margin:6px 0 2px;color:#18583d}
.jobtorque .jt-header{display:flex;justify-content:space-between;align-items:flex-start;gap:12px;flex-wrap:wrap}
.jobtorque h2{font-size:19px;margin:3px 0 8px}.jobtorque .jt-muted{color:var(--jt-muted);font-size:12px;line-height:1.65}
.jobtorque .jt-filters{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;margin:15px 0}
.jobtorque .jt-field{display:flex;flex-direction:column;gap:6px;font-size:12px;font-weight:600;color:#375446}
.jobtorque .jt-field input,.jobtorque .jt-field select{width:100%;min-width:0;border:1px solid #d8e2dc;border-radius:9px;padding:11px 12px;background:#fff;color:#183527}
.jobtorque .jt-actions{display:flex;gap:9px;align-items:center;flex-wrap:wrap;margin:12px 0}.jobtorque .jt-link{display:inline-flex;text-decoration:none;border:1px solid #d4e1d9;border-radius:9px;padding:10px 15px;font-size:12px;font-weight:700;color:#234a35;background:white;cursor:pointer}
.jobtorque .jt-link.primary{background:var(--jt-green);color:#fff;border-color:var(--jt-green)}
.jobtorque .jt-note{border-left:4px solid #6caa87;border-radius:8px;background:#f4faf6;padding:12px 15px;margin:18px 0;font-size:12px;color:#426150;line-height:1.65}
.jobtorque .jt-warning{border-left-color:#cd972c;background:#fff8ea;color:#7f5916}
.jobtorque .jt-job{margin:16px 0;background:#fff;border:1px solid #dee9e1;border-radius:14px;overflow:hidden}
.jobtorque .jt-jobhead{padding:17px 19px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;background:#f7faf7}
.jobtorque .jt-jobname{font-size:17px;font-weight:800;overflow-wrap:anywhere}.jobtorque .jt-jobmeta{font-size:12px;color:#62776c;margin-top:5px}
.jobtorque .jt-pill{display:inline-flex;border-radius:40px;padding:6px 10px;background:#e7f2eb;color:#1e6645;font-weight:750;font-size:11px}
.jobtorque .jt-scroll{overflow-x:auto}.jobtorque table{width:100%;border-collapse:collapse;min-width:750px;font-size:12px}
.jobtorque th,.jobtorque td{padding:12px 15px;text-align:right;border-bottom:1px solid #edf1ee;white-space:nowrap}
.jobtorque th:first-child,.jobtorque td:first-child{text-align:left}.jobtorque th{background:#f9fbf9;text-transform:uppercase;letter-spacing:.04em;color:#5c7667;font-size:10px}
.jobtorque .jt-axis{font-weight:800;color:#23543b}.jobtorque .jt-meter{min-width:100px;height:6px;background:#ecf0ed;border-radius:6px;overflow:hidden}
.jobtorque .jt-meter span{display:block;height:100%;background:#5ba480;border-radius:6px}.jobtorque .jt-empty{padding:28px;text-align:center;color:#708074}
.jobtorque .jt-errors{color:#a12b2b;font-size:12px;margin:10px 0}.jobtorque .jt-checkbox{display:inline-flex;gap:8px;align-items:center;font-size:12px;color:#315640}
@media(max-width:1000px){.jobtorque .jt-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.jobtorque .jt-filters{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(max-width:590px){.jobtorque .jt-grid,.jobtorque .jt-filters{grid-template-columns:1fr}}
</style>
<div class="jobtorque">
    <div class="jt-header">
        <div>
            <span class="eyebrow">RMS / ROBOT MONITOR / JOB ANALYTICS</span>
            <h2>Job Monitoring → Servo Monitor – Torq</h2>
            <p class="jt-muted">{{ $robot->robot_code }} · {{ $robot->name }} · {{ $robot->control_group }} · sumber: YMConnect job name & torque SDK raw.</p>
        </div>
        <div class="jt-actions">
            <a class="jt-link" href="{{ route('rms.robots.show', $robot) }}">← Robot Detail</a>
            <a class="jt-link" href="{{ route('rms.robots.cockpit', $robot) }}">Buka Cockpit</a>
            <a class="jt-link" href="{{ route('rms.logs.index', ['robot_id' => $robot->id]) }}">Robot Logs</a>
        </div>
    </div>

    <section class="jt-section">
        <h2>Filter Job & Shift</h2>
        <form method="GET" action="{{ route('rms.robots.job-torque', $robot) }}" id="job-torque-filters">
            <div class="jt-filters">
                <label class="jt-field">Periode / Shift
                    <select name="preset" id="jt-preset">
                        <option value="shift_pagi" @selected($filters['preset']==='shift_pagi')>Shift 1 — 07:15–16:00 WIB</option>
                        <option value="shift_2" @selected($filters['preset']==='shift_2')>Shift 2 — 21:00–05:45 WIB (+1 hari)</option>
                        <option value="full_day" @selected($filters['preset']==='full_day')>Satu hari — 00:00–23:59 WIB</option>
                        <option value="custom" @selected($filters['preset']==='custom')>Rentang manual (maks. 7 hari)</option>
                    </select>
                </label>
                <label class="jt-field">Tanggal shift
                    <input type="date" name="date" value="{{ $filters['date'] }}">
                </label>
                <label class="jt-field">Job
                    <select name="job">
                        <option value="">Semua job yang teridentifikasi</option>
                        @foreach($jobOptions as $jobName)
                            <option value="{{ $jobName }}" @selected($filters['job']===$jobName)>{{ $jobName }}</option>
                        @endforeach
                        @if($filters['job']!==null && !in_array($filters['job'], $jobOptions, true))
                            <option value="{{ $filters['job'] }}" selected>{{ $filters['job'] }} (tidak ditemukan pada periode)</option>
                        @endif
                    </select>
                </label>
                <label class="jt-field">Mode perhitungan
                    <select name="mode">
                        <option value="absolute" @selected($filters['mode']==='absolute')>Magnitude — AVG/MAX |torque|</option>
                        <option value="signed" @selected($filters['mode']==='signed')>Signed — AVG/MAX torque asli</option>
                    </select>
                </label>
                <label class="jt-field">Dari (WIB, hanya rentang manual)
                    <input type="datetime-local" name="from" value="{{ $filters['from'] }}">
                </label>
                <label class="jt-field">Sampai (WIB, hanya rentang manual)
                    <input type="datetime-local" name="to" value="{{ $filters['to'] }}">
                </label>
            </div>
            <div class="jt-actions">
                <button class="jt-link primary" type="submit">Tampilkan Analisis</button>
                <a class="jt-link" href="{{ route('rms.robots.job-torque', $robot) }}">Reset</a>
                <a class="jt-link" href="{{ $exportUrl }}">Export CSV</a>
                <label class="jt-checkbox"><input type="hidden" name="auto" value="0"><input type="checkbox" name="auto" value="1" @checked(request()->boolean('auto'))> Auto-refresh 15 detik</label>
            </div>
        </form>
        @if($errors->any())
        <div class="jt-errors">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>
        @endif
        <p class="jt-muted">Waktu data: {{ $filters['from_label'] }} sampai sebelum {{ $filters['until_label'] }} WIB. Akhir menit diproses inklusif. Filter shift mengikuti konfigurasi Robot Logs.</p>
    </section>

    <div class="jt-grid">
        <div class="jt-card"><small>Torque samples valid</small><strong>{{ number_format($report['total_torque_samples'], 0, ',', '.') }}</strong><small>Semua sampel torque valid, tanpa simulasi</small></div>
        <div class="jt-card"><small>Torque + job teridentifikasi</small><strong>{{ number_format($report['tagged_torque_samples'], 0, ',', '.') }}</strong><small>Hanya sampel bertanda job</small></div>
        <div class="jt-card"><small>Cakupan identitas job</small><strong>{{ $report['coverage_percent'] === null ? '—' : number_format($report['coverage_percent'], 1, ',', '.') . '%' }}</strong><small>Bukan persentase waktu produksi</small></div>
        <div class="jt-card"><small>Job yang tampil</small><strong>{{ count(array_slice($report['groups'], 0, $report['max_jobs'])) }}</strong><small>Unit torque: sdk_raw · {{ $filters['mode'] }}</small></div>
    </div>

    <div class="jt-note"><strong>Job terakhir teramati pada periode:</strong> {{ $report['last_tagged_job'] ?: 'Belum tersedia' }}{{ $report['last_tagged_at'] ? ' · ' . $report['last_tagged_at'] : '' }}. Ini adalah job terakhir dengan sampel torque bertanda job, <strong>bukan konfirmasi job yang sedang berjalan saat ini</strong>.</div>

    <div class="jt-note"><strong>Catatan validitas:</strong> Job diambil dari nama yang dilaporkan collector, belum terverifikasi sebagai batas awal/akhir job. Collector membaca job lebih jarang daripada torque. Data torque yang tidak mempunyai job <strong>tidak dipaksakan ke job sebelumnya</strong>, sehingga AVG/MAX di bawah ini adalah statistik sampel yang teridentifikasi, bukan keseluruhan siklus produksi. Satuan <code>sdk_raw</code> belum boleh diklaim sebagai N·m atau ampere.</div>
    @if($report['truncated'])
        <div class="jt-note jt-warning">Lebih dari {{ $report['max_jobs'] }} job teridentifikasi. Halaman dan CSV hanya menampilkan {{ $report['max_jobs'] }} teratas. Gunakan filter job atau periode lebih pendek.</div>
    @endif
    @forelse(array_slice($report['groups'], 0, $report['max_jobs']) as $group)
        @php
            $maxMagnitude = max(0.000001, ...array_map(static fn(array $reading): float => $reading['maximum'] === null ? 0.0 : abs((float)$reading['maximum']), array_values($group['axes'])));
        @endphp
        <section class="jt-job">
            <div class="jt-jobhead">
                <div>
                    <div class="jt-jobname">{{ $group['job'] }}</div>
                    <div class="jt-jobmeta">{{ $group['first_at'] ?: '—' }} — {{ $group['last_at'] ?: '—' }} · Nama job dari telemetry, bukan job cycle tervalidasi</div>
                </div>
                <span class="jt-pill">{{ number_format($group['sample_count'], 0, ',', '.') }} sampel bertanda job</span>
            </div>
            <div class="jt-scroll"><table>
                <thead><tr><th>Axis</th><th>Jumlah valid</th><th>Average ({{ $filters['mode'] }})</th><th>Maximum ({{ $filters['mode'] }})</th><th>Visual magnitude maksimum relatif per job</th></tr></thead>
                <tbody>
                @foreach(\App\Services\Rms\JobTorqueSummary::AXES as $axis)
                    @php($reading=$group['axes'][$axis])
                    <tr>
                        <td class="jt-axis">{{ $axis }}</td>
                        <td>{{ number_format($reading['count'], 0, ',', '.') }}</td>
                        <td>{{ $reading['average'] === null ? '—' : number_format($reading['average'], 4, ',', '.') }}</td>
                        <td>{{ $reading['maximum'] === null ? '—' : number_format($reading['maximum'], 4, ',', '.') }}</td>
                        <td><div class="jt-meter"><span style="width:{{ $reading['maximum'] === null ? 0 : round(min(100, abs((float)$reading['maximum']) / $maxMagnitude * 100), 1) }}%"></span></div></td>
                    </tr>
                @endforeach
                </tbody>
            </table></div>
        </section>
    @empty
        <div class="jt-section jt-empty"><strong>Belum ada data job–torque yang sesuai filter.</strong><p>Pastikan collector membaca job dengan sukses dan kolom <code>job_name</code> terisi. Periksa juga tanggal/shift dan keberadaan data torque valid di Robot Logs.</p></div>
    @endforelse
</div>
@if(request()->boolean('auto'))
<script>
(() => {
    // Optional, page-scoped fallback. Does not alter RMS shared Reverb or other pages.
    const filter = document.getElementById('job-torque-filters');
    window.setInterval(() => {
        const active = document.activeElement;
        if (document.hidden || (active && filter?.contains(active))) return;
        window.location.reload();
    }, 15000);
})();
</script>
@endif
@endsection
