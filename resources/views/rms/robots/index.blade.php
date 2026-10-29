@extends('rms.layouts.app')
@section('heading', 'Robot monitor')
@section('subtitle', 'Daftar robot beserta monitoring data terbaru.')
@section('auto_refresh', 'off')
@section('content')
@if(session('success'))<div class="notice rms-success" role="status">✓ {{ session('success') }}</div>@endif
@include('rms.partials.health-legend')
<div class="toolbar"><form method="GET" class="rms-filter-form"><label class="field">Status koneksi <select name="connectivity" class="input"><option value="all">Semua status</option>@foreach(['connected','offline','stale','unknown'] as $s)<option @selected($connectivityFilter === $s) value="{{ $s }}">{{ strtoupper($s) }}</option>@endforeach</select></label><button class="btn" type="submit">Terapkan</button><a href="{{ route('rms.robots.index') }}" class="btn text">Reset</a></form><span class="rms-muted-small">{{ $robots->count() }} robot ditemukan</span><a href="{{ route('rms.robots.create') }}" class="btn primary">＋ Tambah robot</a></div>
@if($robots->isEmpty()) @include('rms.partials.no-data',['message'=>'Tidak ada robot sesuai filter, atau belum ada robot yang didaftarkan.'])
@else <div class="robot-grid rms-robot-grid">@foreach($robots as $item) @include('rms.partials.robot-card',['item'=>$item]) @endforeach</div> @endif
<div class="rms-note">CONNECTED berarti RMS menerima telemetry valid dan Fresh; <strong>bukan berarti robot sedang bergerak/RUN</strong>. Klik kartu untuk melihat Servo Monitor dan Servo Monitor - Torq.</div>
@endsection
