@extends('rms.layouts.app')
@section('heading', 'Hardware insights')
@section('subtitle', 'Investigasi kesehatan komponen — prediksi remaining useful life belum dibuktikan.')
@section('content')
<div class="hardware-hero"><div>⚙</div><div><span class="badge rms-badge warning">UNDER REVIEW</span><h2>Hardware life estimation belum tersedia</h2><p>{{ $note }}</p></div></div>
<section class="panel rms-section-spaced"><h2>Kebutuhan validasi berikutnya</h2><ol class="rms-research-list">@foreach($researchItems as $r)<li>{{ $r }}</li>@endforeach</ol><div class="rms-note">Halaman ini tidak menghitung persentase umur hardware atau diagnosis kegagalan hanya dari Motor Torque.</div></section>
@endsection
