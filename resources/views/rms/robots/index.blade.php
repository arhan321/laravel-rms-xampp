@extends('rms.layouts.app')
@section('heading', 'Robot monitor')
@section('subtitle', 'Pilih area atau line untuk melihat robot dan kondisi monitoringnya.')
@section('auto_refresh', 'off')
@push('styles')
<link rel="stylesheet" href="{{ asset('rms/css/robot-line-groups.css') }}?v=20261031-1">
@endpush
@section('content')
@if(session('success'))<div class="notice rms-success" role="status">✓ {{ session('success') }}</div>@endif
<div class="rms-line-monitor" data-rms-line-monitor data-rms-selected-group="{{ $selectedGroupKey ?? '' }}" data-rms-connectivity-filter="{{ $connectivityFilter }}">
  <nav class="rms-line-breadcrumb" aria-label="Tahapan monitoring">
    <a href="{{ route('rms.robots.index') }}" @if($selectedGroup === null) aria-current="page" @endif>01 · Area / line</a>
    <span aria-hidden="true">›</span><span @if($selectedGroup !== null) aria-current="page" @endif>02 · {{ $selectedGroup['label'] ?? 'Daftar robot' }}</span>
    <span aria-hidden="true">›</span><span>03 · Detail robot</span>
  </nav>
  @if($selectedGroup === null)
    <section class="rms-line-hero">
      <div><span class="rms-line-eyebrow">GRUP ROBOT</span><h2>Monitoring dimulai dari area.</h2><p>Pilih kelompok produksi, lihat robot yang terdaftar, lalu buka detail kondisi setiap unit.</p><div class="rms-line-hero-actions"><a class="btn primary" href="{{ route('rms.robots.index', ['group' => 'all']) }}">Lihat semua robot ↗</a><a class="btn" href="{{ route('rms.robot-groups.index') }}">Kelola grup</a>@if($groupWritesEnabled)<a class="btn" href="{{ route('rms.robot-groups.create') }}">＋ Tambah grup</a>@endif</div></div>
      <div class="rms-line-fleet" data-rms-line-summary="all"><div><strong data-rms-group-stat="registered">{{ $fleetSummary['registered'] }}</strong><span>robot terdaftar</span></div><div class="rms-line-fleet-facts"><span><b data-rms-group-stat="fresh">{{ $fleetSummary['fresh'] }}</b> data fresh</span><span><b data-rms-group-stat="attention">{{ $fleetSummary['attention'] }}</b> health perlu perhatian</span></div></div>
    </section>
    <div class="rms-line-section-heading"><div><span class="rms-line-eyebrow">AREA PRODUKSI</span><h2>Pilih grup robot</h2></div><span class="rms-line-muted">Klik area untuk membuka daftar robot</span></div>
    @if($groups->where('additional', false)->isEmpty())<div class="rms-line-empty"><strong>Belum ada grup robot.</strong><p>Buat grup melalui Kelola grup, lalu pilih robot yang menjadi anggotanya.</p></div>@endif
    <div class="rms-line-grid">
      @foreach($groups->where('additional', false) as $group)
        @include('rms.robots.partials.line-group-card', ['group' => $group])
      @endforeach
    </div>
    <p class="rms-line-plan-note">Jumlah <strong>rencana</strong> diatur pada informasi grup. Jumlah <strong>terdaftar</strong> dihitung dari robot anggota grup tersebut.</p>
    @if($groups->where('additional', true)->isNotEmpty())
      <div class="rms-line-section-heading"><div><span class="rms-line-eyebrow">KELOMPOK LAINNYA</span><h2>Robot yang belum dikelompokkan</h2></div></div>
      <div class="rms-line-grid rms-line-extra-grid">@foreach($groups->where('additional', true) as $group) @include('rms.robots.partials.line-group-card', ['group' => $group]) @endforeach</div>
    @endif
    <div class="rms-line-help"><strong>Atur penempatan robot</strong><p>Buka <strong>Kelola grup → Tambah / Edit grup</strong>, lalu centang robot anggotanya. Kamu juga dapat memilih grup melalui <strong>Edit data robot → Grup robot</strong>.</p></div>
  @else
    <div class="rms-line-detail-heading"><div><a class="rms-line-back" href="{{ route('rms.robots.index') }}">← Kembali ke pilihan area</a><h2>{{ $selectedGroup['label'] }} @if($selectedGroup['pilot'])<span class="rms-line-pilot">PILOT PROJECT</span>@endif</h2><p>@if($selectedGroupKey === 'unassigned')Pilih grup melalui Edit data robot atau Kelola grup untuk menempatkan unit.@else Klik robot untuk membuka servo monitor, operating time, alarm, dan histori.@endif</p>@if($selectedGroup['description'])<p class="rms-group-description">{{ $selectedGroup['description'] }}</p>@endif
    @if($selectedGroup['area_label'])<p>Area: <strong>{{ $selectedGroup['area_label'] }}</strong></p>@endif</div><div class="rms-line-hero-actions">@if($selectedGroup['id'] && $groupWritesEnabled)<a class="btn" href="{{ route('rms.robot-groups.edit', $selectedGroup['id']) }}">Edit grup &amp; anggota</a>@endif<a class="btn primary" href="{{ route('rms.robots.create', ['robot_group_id' => $selectedGroup['id']]) }}">＋ Tambah robot di grup ini</a></div></div>
    <div class="rms-line-detail-stats" data-rms-line-summary="{{ $selectedGroupKey }}"><div><strong data-rms-group-stat="registered">{{ $selectedGroup['registered'] }}</strong><span>Robot terdaftar</span></div><div><strong data-rms-group-stat="fresh">{{ $selectedGroup['fresh'] }}</strong><span>Data fresh</span></div><div><strong data-rms-group-stat="attention">{{ $selectedGroup['attention'] }}</strong><span>Health perlu perhatian</span></div>@if($selectedGroup['planned_robots'] !== null)<div class="rms-line-planned"><strong>{{ $selectedGroup['planned_robots'] }}</strong><span>Rencana robot</span></div>@endif</div>
    <nav class="rms-line-switcher" aria-label="Pindah area"><a class="{{ $selectedGroupKey === 'all' ? 'is-selected' : '' }}" href="{{ route('rms.robots.index', ['group' => 'all']) }}">Semua robot</a>@foreach($groups as $group)<a class="{{ $selectedGroupKey === $group['key'] ? 'is-selected' : '' }}" href="{{ route('rms.robots.index', ['group' => $group['key']]) }}" @if($selectedGroupKey === $group['key']) aria-current="page" @endif>{{ $group['label'] }}</a>@endforeach</nav>
    @include('rms.partials.health-legend')
    @php($visibleCount = $connectivityFilter === 'all' ? $robots->count() : $robots->where('connectivity', $connectivityFilter)->count())
    <div class="toolbar"><form method="GET" class="rms-filter-form"><input type="hidden" name="group" value="{{ $selectedGroupKey }}"><label class="field">Status koneksi <select name="connectivity" class="input"><option value="all">Semua status</option>@foreach(['connected','offline','stale','unknown'] as $s)<option @selected($connectivityFilter === $s) value="{{ $s }}">{{ strtoupper($s) }}</option>@endforeach</select></label><button class="btn" type="submit">Terapkan</button><a href="{{ route('rms.robots.index', ['group' => $selectedGroupKey]) }}" class="btn text">Reset filter</a></form><span class="rms-line-muted"><b data-rms-visible-robot-count>{{ $visibleCount }}</b> robot sesuai filter</span></div>
    <div class="rms-line-empty" data-rms-line-empty @if($visibleCount > 0) hidden @endif><strong>{{ $robots->isEmpty() ? 'Belum ada robot terdaftar di kelompok ini.' : 'Belum ada robot sesuai filter koneksi.' }}</strong><p>{{ $robots->isEmpty() ? 'Pilih anggota melalui Edit grup, atau tambahkan robot baru ke grup ini.' : 'Pilih Semua status untuk melihat unit lainnya. Status akan mengikuti pembacaan terbaru.' }}</p></div>
    <div class="robot-grid rms-robot-grid">@foreach($robots as $item) @include('rms.partials.robot-card', ['item' => $item, 'cardHidden' => $connectivityFilter !== 'all' && $item['connectivity'] !== $connectivityFilter]) @endforeach</div>
    <div class="rms-note rms-section-spaced">CONNECTED berarti RMS menerima telemetry valid dan fresh. Status gerakan, servo, dan health mengikuti pembacaan masing-masing unit.</div>
  @endif
  <div class="rms-line-data-notice" data-rms-line-data-notice role="status" hidden>Daftar atau penempatan robot telah berubah. <a href="{{ request()->fullUrl() }}">Muat ulang daftar</a> untuk menampilkan perubahan.</div>
</div>
@endsection

