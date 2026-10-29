@extends('rms.layouts.app')
@section('heading', 'Maintenance recommendation')
@section('subtitle', 'Tindakan berbasis kondisi equipment health dan temuan operator — mudah dicatat tanpa login.')
@section('content')
<link rel="stylesheet" href="{{ asset('rms/css/recommendations-operator.css') }}">

@if(session('pm_success'))
    <div class="rec-message rec-message-ok" role="status">✓ {{ session('pm_success') }}</div>
@endif
@if($errors->any())
    <div class="rec-message rec-message-error" role="alert"><strong>Periksa kembali input:</strong>
        <ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
    </div>
@endif

<div class="stats rms-three-stats rec-stats">
    @include('rms.partials.metric',['label'=>'Open','value'=>$openCount,'unit'=>'record','description'=>'Belum ditangani'])
    @include('rms.partials.metric',['label'=>'Sedang ditangani','value'=>$inProgressCount,'unit'=>'record','description'=>'Sudah diambil operator'])
    @include('rms.partials.metric',['label'=>'Selesai','value'=>$resolvedCount,'unit'=>'record','description'=>'Pekerjaan tercatat di jurnal'])
</div>

<div class="rec-control-row">
    <div class="rec-engine">
        <span class="badge rms-badge {{ $generationEnabled ? 'connected' : 'unknown' }}">AUTOMATIC REVIEW {{ $generationEnabled ? 'ON' : 'OFF' }}</span>
        <small>{{ $generationEnabled ? 'RMS meninjau kondisi secara berkala; hasil hanya muncul jika data mendukung.' : 'Pemeriksaan otomatis belum diaktifkan. Rekomendasi tersimpan tetap bisa ditindaklanjuti.' }}</small>
    </div>
    @if($writesEnabled)
    <details class="rec-add" id="tambah-temuan">
        <summary class="btn primary">+ Tambah temuan manual</summary>
        <form method="POST" action="{{ route('rms.recommendations.manual.store') }}" class="rec-form rec-form-wide">
            @csrf
            <h3>Temuan inspeksi operator</h3>
            <p class="rms-muted-small">Temuan manual ditandai MANUAL; bukan alarm controller dan bukan hasil prediksi.</p>
            <div class="rec-fields">
                <label>Robot <select class="input" name="robot_id" required>
                    <option value="">Pilih robot</option>
                    @foreach($robots as $robot)<option value="{{ $robot->id }}" @selected(old('robot_id') == $robot->id)>{{ $robot->robot_code }} — {{ $robot->name }}</option>@endforeach
                </select></label>
                <label>Nama pelapor <input class="input" name="reported_by" maxlength="120" value="{{ old('reported_by') }}" required placeholder="Nama operator"></label>
                <label>Axis (opsional) <select class="input" name="axis"><option value="">Umum / tidak terkait axis</option>@foreach(['S','L','U','R','B','T'] as $a)<option value="{{ $a }}" @selected(old('axis') === $a)>{{ $a }}</option>@endforeach</select></label>
                <label>Prioritas <select class="input" name="priority" required>@foreach(['low'=>'Low','normal'=>'Normal','medium'=>'Medium','high'=>'High','critical'=>'Critical'] as $p=>$label)<option value="{{ $p }}" @selected(old('priority','normal') === $p)>{{ $label }}</option>@endforeach</select></label>
            </div>
            <label>Judul temuan <input class="input" name="title" maxlength="180" value="{{ old('title') }}" required placeholder="Contoh: Suara abnormal di axis U"></label>
            <label>Kondisi yang diamati <textarea class="input" name="finding" rows="2" maxlength="3000" placeholder="Apa yang ditemukan?">{{ old('finding') }}</textarea></label>
            <label>Saran tindakan <textarea class="input" name="recommendation_text" rows="2" maxlength="3000" required placeholder="Contoh: Inspeksi mekanis sesuai SOP sebelum operasi lanjutan">{{ old('recommendation_text') }}</textarea></label>
            <button class="btn primary" type="submit">Simpan temuan</button>
        </form>
    </details>
    @else
    <small class="rec-write-off">Mode catat tindakan belum diaktifkan oleh administrator RMS.</small>
    @endif
</div>

<div class="panel rec-filter-panel">
    <form method="GET" class="rms-filter-form">
        @include('rms.partials.filter-robots',['robots'=>$robots])
        <label class="field">Status<select class="input" name="status">@foreach(['all'=>'Semua','open'=>'Open','acknowledged'=>'Ditangani','resolved'=>'Selesai','dismissed'=>'Tidak relevan'] as $key=>$label)<option value="{{ $key }}" @selected(($filter['status']??'all')===$key)>{{ $label }}</option>@endforeach</select></label>
        <label class="field">Prioritas<select class="input" name="priority"><option value="">Semua prioritas</option>@foreach(['low','normal','medium','high','critical'] as $p)<option value="{{ $p }}" @selected(($filter['priority']??'')===$p)>{{ strtoupper($p) }}</option>@endforeach</select></label>
        <label class="field">Sumber<select class="input" name="source_type">@foreach(['all'=>'Semua','automatic'=>'Sistem RMS','manual'=>'Manual'] as $key=>$label)<option value="{{ $key }}" @selected(($filter['source_type']??'all')===$key)>{{ $label }}</option>@endforeach</select></label>
        <button type="submit" class="btn primary">Filter</button>
        <a class="btn" href="{{ route('rms.recommendations.index') }}">Reset</a>
    </form>
</div>

<section class="panel rms-section-spaced rec-list-panel">
    <div class="panel-header rec-list-head"><h2>Recommendation records</h2><span class="rms-muted-small">{{ $recommendations->total() }} record</span></div>
    @if($recommendations->isEmpty())
        @include('rms.partials.no-data',['message'=>'Belum ada rekomendasi untuk filter ini. Jika data robot belum terhubung, sistem tidak membuat rekomendasi torque palsu.'])
    @else
    <div class="rec-list">
        @foreach($recommendations as $r)
            @php
                $manual = $r->source === 'manual_operator';
                $ongoing = in_array($r->status, ['open', 'acknowledged'], true);
                $activities = $actionsByRecommendation->get($r->id, collect());
                $evidence = is_array($r->evidence) ? $r->evidence : [];
                $sourceLabel = $manual ? 'Temuan operator' : 'RMS condition review';
                $conditionKey = (string) ($evidence['rule_key'] ?? '');
                $conditionLabel = str_starts_with($conditionKey, 'axis_')
                    ? 'Torque Threshold · Axis '.strtoupper(substr($conditionKey, 5))
                    : match($conditionKey) {
                        'active_alarm' => 'Controller Alarm',
                        'maintenance_due' => 'Maintenance Due',
                        default => 'RMS condition review',
                    };
            @endphp
            <article class="rec-item" id="recommendation-{{ $r->id }}">
                <div class="rec-top">
                    <div class="rec-title-area">
                        <div class="rec-meta"><strong>{{ $r->robot?->robot_code ?? 'Robot tidak tersedia' }}</strong><span class="rec-separator">•</span><span>{{ $r->generated_at?->timezone('Asia/Jakarta')->format('d M Y H:i') ?? 'Waktu tidak tersedia' }} WIB</span><span class="rec-separator">•</span><span>{{ strtoupper($sourceLabel) }}</span></div>
                        <h3>{{ $r->title }}</h3>
                    </div>
                    <div class="rec-labels">
                        <span class="badge rms-badge {{ in_array($r->priority,['critical','high']) ? 'critical' : (in_array($r->priority,['medium','normal']) ? 'warning' : 'connected') }}">{{ strtoupper($r->priority ?? 'normal') }}</span>
                        <span class="badge rms-badge {{ $r->status }}">{{ $r->status === 'acknowledged' ? 'DITANGANI' : ($r->status === 'resolved' ? 'SELESAI' : strtoupper($r->status)) }}</span>
                    </div>
                </div>
                <p class="rec-summary">{{ $r->recommendation_text }}</p>
                <div class="rec-links">
                    <a href="{{ route('rms.robots.show', $r->robot_id) }}">Detail robot →</a>
                    @if(Route::has('rms.robots.cockpit'))<a href="{{ route('rms.robots.cockpit', $r->robot_id) }}">Robot Cockpit →</a>@endif
                    <a href="{{ route('rms.maintenance.index', ['robot_id'=>$r->robot_id]) }}">Maintenance Journal →</a>
                </div>
                <div class="rec-bottom">
                    <details class="rec-details">
                        <summary>Lihat bukti & riwayat</summary>
                        <div class="rec-detail-body">
                            <p><strong>Sumber:</strong> {{ $sourceLabel }}</p>
                            @if($r->healthAssessment)
                                <p><strong>Health assessment tercatat:</strong> {{ strtoupper($r->healthAssessment->health_status) }} — {{ $r->healthAssessment->summary }}</p>
                                <p class="rms-muted-small">Snapshot health bukan jaminan kondisi robot saat ini; cek telemetry terbaru.</p>
                            @endif
                            @if($r->maintenancePlan)<p><strong>Jadwal PM terkait:</strong> {{ $r->maintenancePlan->title }}</p>@endif
                            @if($evidence)
                                <p><strong>Evidence tersimpan:</strong></p>
                                <div class="rec-evidence">
                                    @if(!empty($evidence['reported_by']))<p><strong>Dilaporkan oleh:</strong> {{ $evidence['reported_by'] }}</p>@endif
                                    @if(!empty($evidence['axis']))<p><strong>Axis:</strong> {{ $evidence['axis'] }}</p>@endif
                                    @if(!empty($evidence['finding']))<p><strong>Temuan:</strong> {{ $evidence['finding'] }}</p>@endif
                                    @if(!empty($evidence['recorded_at']))<p><strong>Waktu dicatat:</strong> {{ \Carbon\Carbon::parse($evidence['recorded_at'])->timezone('Asia/Jakarta')->format('d M Y H:i') }} WIB</p>@endif
                                    @if(!empty($evidence['rule_key']))<p><strong>Dasar pemeriksaan:</strong> {{ $conditionLabel }}</p>@endif
                                    @if(!empty($evidence['assessment_id']))<p><strong>Health review:</strong> #{{ $evidence['assessment_id'] }}</p>@endif
                                    @if(empty(array_intersect(['reported_by','axis','finding','recorded_at','rule_key','assessment_id'], array_keys($evidence))))<p>Bukti pendukung tercatat pada RMS dan dapat ditinjau bersama administrator.</p>@endif
                                </div>
                            @else<p class="rms-muted-small">Bukti terstruktur belum tersedia untuk rekomendasi ini.</p>@endif
                            @if($r->acknowledged_by)<p><strong>Ditangani oleh:</strong> {{ $r->acknowledged_by }} {{ $r->acknowledged_at?->timezone('Asia/Jakarta')->format('d M Y H:i') }}</p>@endif
                            @if($r->closed_at)<p><strong>Ditutup:</strong> {{ $r->closed_at->timezone('Asia/Jakarta')->format('d M Y H:i') }} WIB — {{ $r->closure_note }}</p>@endif
                            <h4>Riwayat tindak lanjut ({{ $activities->count() }})</h4>
                            @forelse($activities as $a)
                                <p class="rec-action-history"><strong>{{ $a->performed_by }}</strong> · {{ strtoupper($a->result) }} · {{ $a->created_at?->timezone('Asia/Jakarta')->format('d M Y H:i') }} WIB<br>{{ $a->action_taken }}@if($a->findings)<br><span class="rms-muted-small">Temuan: {{ $a->findings }}</span>@endif</p>
                            @empty<p class="rms-muted-small">Belum ada catatan tindak lanjut untuk rekomendasi ini.</p>@endforelse
                        </div>
                    </details>
                    @if($writesEnabled && $ongoing)
                    <div class="rec-buttons">
                        @if($r->status === 'open')
                            <details class="rec-action"><summary class="btn">Mulai tangani</summary>
                                <form method="POST" action="{{ route('rms.recommendations.acknowledge',$r) }}" class="rec-action-form">@csrf
                                    <label>Nama pelaksana<input class="input" name="performed_by" maxlength="120" required placeholder="Nama teknisi"></label>
                                    <button type="submit" class="btn primary">Simpan status</button>
                                </form>
                            </details>
                        @endif
                        <details class="rec-action"><summary class="btn primary">Catat hasil</summary>
                            <form method="POST" action="{{ route('rms.recommendations.action',$r) }}" class="rec-action-form">@csrf
                                <label>Nama pelaksana<input class="input" name="performed_by" maxlength="120" value="{{ $r->acknowledged_by }}" required placeholder="Nama teknisi"></label>
                                <label>Jenis pekerjaan<select name="action_type" class="input" required><option value="inspection">Inspeksi</option><option value="preventive">Preventive</option><option value="corrective">Corrective</option><option value="other">Lainnya</option></select></label>
                                <label>Hasil<select name="result" class="input" required><option value="follow_up_required">Perlu tindak lanjut</option><option value="completed">Selesai</option></select></label>
                                <label>Temuan<textarea name="findings" class="input" rows="2" maxlength="5000" placeholder="Opsional"></textarea></label>
                                <label>Tindakan yang dilakukan<textarea name="action_taken" class="input" rows="3" maxlength="5000" required placeholder="Pekerjaan / pemeriksaan aktual"></textarea></label>
                                <button type="submit" class="btn primary">Simpan ke Maintenance Journal</button>
                                <small>Selesai = menutup rekomendasi, bukan otomatis menyatakan robot sehat atau menggeser jadwal grease.</small>
                            </form>
                        </details>
                        <details class="rec-action"><summary class="btn">Tidak relevan</summary>
                            <form method="POST" action="{{ route('rms.recommendations.dismiss',$r) }}" class="rec-action-form">@csrf
                                <label>Nama pelaksana<input name="performed_by" class="input" maxlength="120" required></label>
                                <label>Alasan<textarea name="closure_note" class="input" minlength="8" maxlength="2000" rows="2" required placeholder="Kenapa rekomendasi tidak relevan?"></textarea></label>
                                <button type="submit" class="btn">Tutup sebagai tidak relevan</button>
                            </form>
                        </details>
                    </div>
                    @elseif(!$ongoing)
                        <span class="rms-muted-small">Rekomendasi ditutup; riwayat tetap tersimpan.</span>
                    @endif
                </div>
            </article>
        @endforeach
    </div>
    <div class="rms-pagination">{{ $recommendations->links() }}</div>
    @endif
</section>
<div class="rms-note"><strong>Catatan:</strong> {{ $note }} Live Update Active hanya menunjukkan pembaruan dashboard tersedia, bukan bukti telemetry robot masih Fresh. Rekap ini tidak mengirim perintah ke YRC1000.</div>
@endsection
