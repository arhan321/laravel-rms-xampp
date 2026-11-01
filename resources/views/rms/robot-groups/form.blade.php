@extends('rms.layouts.app')
@section('heading', $group ? 'Edit grup robot' : 'Tambah grup robot')
@section('subtitle', 'Isi informasi grup, kemudian pilih robot yang akan ditampilkan di dalamnya.')
@section('auto_refresh', 'off')
@push('styles')<link rel="stylesheet" href="{{ asset('rms/css/robot-groups.css') }}?v=20261101-1">@endpush
@section('content')
@php($chosenIds = old('members_present') ? array_map('intval', old('robot_ids', [])) : $selectedRobotIds)
<div class="rms-group-admin">
  <div class="rms-group-toolbar"><a class="btn" href="{{ route('rms.robot-groups.index') }}">← Kelola grup</a>@if($group)<a class="btn" href="{{ route('rms.robots.index', ['group' => $group->group_key]) }}">Buka robot di grup ini ↗</a>@endif</div>
  @if(session('group_success'))<div class="notice rms-success" role="status">{{ session('group_success') }}</div>@endif
  @if($errors->any())<div class="notice warn" role="alert"><strong>Perubahan belum disimpan.</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
  <form method="POST" action="{{ $group ? route('rms.robot-groups.update', $group) : route('rms.robot-groups.store') }}" data-rms-group-form>
    @csrf
    @if($group) @method('PUT')<input type="hidden" name="version" value="{{ old('version', $group->version) }}">@endif
    <input type="hidden" name="membership_token" value="{{ old('membership_token', $membershipToken) }}">
    <input type="hidden" name="members_present" value="1">
    <section class="panel rms-group-form-panel">
      <div class="panel-header"><div><span class="rms-group-eyebrow">01 · INFORMASI GRUP</span><h2>{{ $group ? $group->name : 'Grup baru' }}</h2><p>Nama dan keterangan ini tampil pada kartu Robot Monitor.</p></div></div>
      <div class="rms-group-fields">
        <label class="field">Nama grup *<input class="input" name="name" maxlength="100" required value="{{ old('name', $group?->name) }}" placeholder="MAIN BODY 1"></label>
        <label class="field">Area / line<input class="input" name="area_label" maxlength="150" value="{{ old('area_label', $group?->area_label) }}" placeholder="Welding 2"></label>
        <label class="field rms-group-field-wide">Keterangan grup<textarea class="input" name="description" maxlength="4000" rows="3" placeholder="Contoh: Kelompok robot spot welding pada proses Main Body 1.">{{ old('description', $group?->description) }}</textarea></label>
        <label class="field">Rencana jumlah robot<input class="input" type="number" name="planned_robots" min="0" max="10000" value="{{ old('planned_robots', $group?->planned_robots) }}"><small>Opsional. Jumlah anggota dihitung dari robot yang dipilih.</small></label>
        <label class="field">Urutan tampilan *<input class="input" type="number" name="sort_order" required min="0" max="10000" value="{{ old('sort_order', $group?->sort_order ?? 0) }}"><small>Angka lebih kecil ditampilkan lebih dahulu.</small></label>
      </div>
      <input type="hidden" name="is_pilot" value="0"><label class="rms-group-checkbox"><input type="checkbox" name="is_pilot" value="1" @checked((bool) old('is_pilot', $group?->is_pilot ?? false))> Tandai sebagai pilot project</label>
    </section>
    <section class="panel rms-group-form-panel">
      <div class="panel-header"><div><span class="rms-group-eyebrow">02 · ISI GRUP</span><h2>Pilih robot anggota</h2><p><strong data-rms-member-count>{{ count($chosenIds) }}</strong> robot dipilih dari {{ $robots->count() }} robot terdaftar.</p></div></div>
      <div class="rms-note">Setiap robot berada pada satu grup. Memilih robot dari grup lain akan memindahkannya ke grup ini. Melepas centang anggota saat ini akan menempatkannya pada Belum dikelompokkan. Histori robot tetap mengikuti ID robot yang sama.</div>
      @if($robots->isEmpty())<div class="rms-group-empty"><strong>Belum ada robot terdaftar.</strong><p>Grup dapat disimpan terlebih dahulu. Tambahkan robot melalui Robot Monitor, lalu pilih grupnya.</p></div>
      @else
        <label class="field rms-group-search">Cari robot<input type="search" class="input" data-rms-member-search placeholder="Cari kode, nama robot, atau grup saat ini" autocomplete="off"></label>
        <div class="rms-group-members">
          @foreach($robots as $robot)
          <label class="rms-group-member" data-rms-member-row>
            <input type="checkbox" name="robot_ids[]" value="{{ $robot->id }}" @checked(in_array($robot->id, $chosenIds, true))>
            <span class="rms-group-member-identity"><strong>{{ $robot->robot_code }}</strong><span>{{ $robot->name }}</span><small>{{ $robot->controller?->model ?? 'Controller belum diisi' }} · ID #{{ $robot->id }}</small></span>
            <span class="rms-group-current">{{ $robot->group?->name ?? 'Belum dikelompokkan' }}</span>
          </label>
          @endforeach
        </div>
        <p class="rms-group-muted" data-rms-member-empty hidden>Tidak ada robot sesuai pencarian. Pilihan anggota tetap disimpan saat pencarian berubah.</p>
      @endif
    </section>
    <div class="rms-group-toolbar rms-group-submit"><a class="btn" href="{{ route('rms.robot-groups.index') }}">Batal</a><button class="btn primary" type="submit">{{ $group ? 'Simpan perubahan grup & anggota' : 'Simpan grup & anggota' }}</button></div>
  </form>
  @if($group)
    <section class="rms-group-delete"><div><strong>Hapus grup kosong</strong><p>Grup hanya dapat dihapus setelah seluruh anggotanya dipindahkan atau dikeluarkan.</p></div>
      <form method="POST" action="{{ route('rms.robot-groups.destroy', $group) }}" onsubmit="return window.confirm('Hapus grup kosong ini?');">@csrf @method('DELETE')<input type="hidden" name="version" value="{{ $group->version }}"><button class="btn danger" @disabled(count($selectedRobotIds) > 0) type="submit">Hapus grup</button></form>
    </section>
  @endif
</div>
<script src="{{ asset('rms/js/robot-groups.js') }}?v=20261101-1" defer></script>
@endsection
