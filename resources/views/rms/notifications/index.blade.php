@extends('rms.layouts.app')
@section('heading', 'Notification center')
@section('subtitle', 'Rencana pengiriman notifikasi maintenance dan alarm; pengaturan pengiriman belum tersedia.')
@section('content')
<div class="notice warn"><strong>Notification delivery: DISABLED</strong> — tidak ada email yang dikirim dari halaman ini.</div>
<div class="two-col rms-section-spaced"><section class="panel"><h2>Notification rules</h2>@include('rms.partials.no-data',['title'=>'Rule belum dikonfigurasi','message'=>$message])</section><section class="panel"><h2>Delivery history</h2>@include('rms.partials.no-data',['title'=>'Riwayat pengiriman belum tersedia','message'=>'Tujuan penerima dan layanan email harus disiapkan sebelum notifikasi dapat dikirim.'])</section></div>
<div class="rms-note">Notification Rules belum tersedia. Halaman ini tidak mengirim notifikasi otomatis sampai konfigurasi pengiriman selesai dan diverifikasi.</div>
@endsection
