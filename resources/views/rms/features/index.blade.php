@extends('rms.layouts.app')
@section('heading', 'Development status')
@section('subtitle', 'Peta kemampuan platform berdasarkan pengujian dan rencana pengembangan.')
@section('content')
<div class="rms-feature-grid">
@foreach($features as $feature)
  @php
    $stageLabel = match($feature['stage']) {
      'poc_tested' => 'TESTED',
      'sdk_supported' => 'CONTROLLER DATA AVAILABLE',
      'partially_tested' => 'PARTIALLY TESTED',
      'schema_prepared' => 'PREPARATION READY',
      'needs_investigation' => 'NEEDS INVESTIGATION',
      default => str_replace('_', ' ', strtoupper($feature['stage'])),
    };
    $stageDescription = match($feature['stage']) {
      'poc_tested' => 'Fungsi pernah diuji, tetapi masih perlu verifikasi pada setiap robot production.',
      'sdk_supported' => 'Controller menyediakan data yang dibutuhkan; monitoring lengkap belum diverifikasi.',
      'partially_tested' => 'Fungsi diuji sebagian dan masih memerlukan verifikasi tambahan.',
      'schema_prepared' => 'Persiapan data dan tampilan sudah tersedia; fungsi belum sepenuhnya diaktifkan.',
      'needs_investigation' => 'Masih memerlukan pemeriksaan teknis lanjutan.',
      default => 'Termasuk ruang lingkup atau rencana pengembangan.',
    };
  @endphp
  <article class="capability-card">
    <span class="badge rms-badge {{ $feature['stage'] === 'poc_tested' ? 'connected' : ($feature['stage'] === 'needs_investigation' ? 'warning' : 'unknown') }}">{{ $stageLabel }}</span>
    <h3>{{ $feature['name'] }}</h3>
    <p>{{ $stageDescription }}</p>
  </article>
@endforeach
</div>
<div class="rms-note">{{ $note }}</div>
@endsection
