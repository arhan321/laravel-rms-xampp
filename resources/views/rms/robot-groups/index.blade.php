@extends('rms.layouts.app')
@section('heading', 'Kelola grup robot')
@section('subtitle', 'Buat area atau kelompok robot dan atur robot yang menjadi anggotanya.')
@section('auto_refresh', 'off')
@push('styles')<link rel="stylesheet" href="{{ asset('rms/css/robot-groups.css') }}?v=20261101-1">@endpush
@section('content')
<div class="rms-group-admin">
  <div class="rms-group-toolbar"><a class="btn" href="{{ route('rms.robots.index') }}">← Robot monitor</a>@if($writesEnabled)<a class="btn primary" href="{{ route('rms.robot-groups.create') }}">＋ Tambah grup</a>@endif<span class="rms-group-muted">{{ $groups->count() }} grup · {{ $groups->sum('robots_count') }} robot anggota</span></div>
  @if(session('group_success'))<div class="notice rms-success" role="status">{{ session('group_success') }}</div>@endif
  @if($errors->any())<div class="notice warn" role="alert"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
  @if($unassignedCount > 0)<div class="rms-group-unassigned"><span><strong>{{ $unassignedCount }} robot belum dikelompokkan.</strong> Pilih anggotanya saat menambah atau mengedit grup.</span><a class="btn" href="{{ route('rms.robots.index', ['group' => 'unassigned']) }}">Lihat robot</a></div>@endif
  @unless($writesEnabled)<div class="rms-note">Pengelolaan grup sedang dalam mode baca.</div>@endunless
  @if($groups->isEmpty())
    <section class="panel rms-group-empty"><h2>Belum ada grup robot.</h2><p>Buat grup pertama, misalnya Main Body 1 atau DOJO, lalu pilih robot anggotanya.</p>@if($writesEnabled)<a class="btn primary" href="{{ route('rms.robot-groups.create') }}">Buat grup pertama</a>@endif</section>
  @else
    <div class="rms-group-grid">
      @foreach($groups as $item)
      <article class="panel rms-group-tile">
        <div class="rms-group-tile-heading"><span class="rms-group-eyebrow">{{ $item->area_label ?: 'GRUP ROBOT' }}</span>@if($item->is_pilot)<span class="rms-group-pilot">PILOT</span>@endif</div>
        <h2>{{ $item->name }}</h2><p class="rms-group-tile-description">{{ $item->description ?: 'Keterangan grup belum diisi.' }}</p>
        <div class="rms-group-numbers"><div><strong>{{ $item->robots_count }}</strong><span>robot anggota</span></div><div><strong>{{ $item->planned_robots ?? '—' }}</strong><span>rencana robot</span></div></div>
        <div class="rms-group-toolbar"><a class="btn" href="{{ route('rms.robots.index', ['group' => $item->group_key]) }}">Buka robot →</a>@if($writesEnabled)<a class="btn primary" href="{{ route('rms.robot-groups.edit', $item) }}">Edit grup &amp; anggota</a>@endif</div>
      </article>
      @endforeach
    </div>
  @endif
</div>
@endsection
