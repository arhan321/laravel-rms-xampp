@extends('rms.layouts.app')
@section('heading', 'Preventive maintenance')
@section('subtitle', 'Atur interval servis, penggantian grease, dan catat pekerjaan robot dengan mudah.')
@section('content')
<link rel="stylesheet" href="{{ asset('rms/css/pm-operator.css') }}?v=20261020-2">
<style>
  .pm-component-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin:14px 0}
  .pm-component-card{border:1px solid #dde9e1;border-radius:10px;padding:14px;background:#fafdfb;display:grid;gap:7px;min-width:0}
  .pm-component-card strong{font-size:13px}.pm-component-card small{font-size:11px;line-height:1.5;color:#687d70}
  .pm-component-card>span{font-size:11px;font-weight:700;color:#397b59}
  .pm-component-card[data-status="due"]>span{color:#9e5b2d}
  .pm-component-card[data-status="unknown"]>span{color:#747f78}
  .pm-component-card[data-status="due"]{border-color:#d69f75;background:#fff8f0}
  .pm-component-card[data-status="unknown"]{background:#f5f7f6}
  .pm-component-empty{padding:18px;border:1px dashed #cbded1;border-radius:10px;background:#f7faf8;color:#456553}
  .pm-component-empty strong{display:block;font-size:13px}
  .pm-component-empty p{margin:6px 0 0;font-size:11px;line-height:1.6}
  @media(max-width:1200px){.pm-component-grid{grid-template-columns:repeat(3,minmax(0,1fr))}}
  @media(max-width:800px){.pm-component-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}
  @media(max-width:540px){.pm-component-grid{grid-template-columns:1fr}}
</style>

{{-- Operator flow is deliberately login-free. Dashboard reads remain as before. --}}
@if(session('pm_success'))
    <div class="pm-feedback pm-feedback-success" role="status">✓ {{ session('pm_success') }}</div>
@endif
@if($errors->any())
    <div class="pm-feedback pm-feedback-error" role="alert">
        <strong>Data belum tersimpan.</strong>
        <ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
    </div>
@endif

{{-- EXISTING FILTERS (unchanged) --}}
<div class="panel">
  <form method="GET" class="rms-filter-form">
    @include('rms.partials.filter-robots', ['robots'=>$robots])
    <label class="field">Plan aktif
      <select name="active" class="input">
        @foreach(['all'=>'Semua','yes'=>'Aktif','no'=>'Nonaktif'] as $k=>$v)
          <option value="{{ $k }}" @selected(($filter['active'] ?? 'all') === $k)>{{ $v }}</option>
        @endforeach
      </select>
    </label>
    <button class="btn primary" type="submit">Filter</button>
    <a class="btn" href="{{ route('rms.maintenance.index') }}">Reset</a>
  </form>
</div>

@if($categoryOverview !== [])
@php
  $configuredCategories = array_filter($categoryOverview, static fn (array $category): bool => $category['plans'] > 0);
  $unconfiguredCount = count($categoryOverview) - count($configuredCategories);
@endphp
<section class="panel rms-section-spaced">
  <div class="panel-header"><h2>PM per komponen</h2><span class="rms-muted-small">Robot terpilih · interval sesuai SOP/manual</span></div>
  @if($configuredCategories === [])
    <div class="pm-component-empty" role="status">
      <strong>Belum ada jadwal PM per komponen untuk robot ini.</strong>
      <p>Buat jadwal maintenance dan pilih kategori komponen agar status dan sisa jamnya tampil di sini.</p>
    </div>
  @else
    <div class="pm-component-grid">
      @foreach($configuredCategories as $category)
        <div class="pm-component-card" data-status="{{ $category['status'] }}">
          <strong>{{ $category['label'] }}</strong>
          <span>{{ match($category['status']) {'due'=>'DUE','scheduled'=>'SCHEDULED','unknown'=>'JAM / JADWAL BELUM VALID',default=>'BELUM ADA PLAN'} }}</span>
          <small>
            {{ $category['plans'] }} plan aktif
            @if($category['hours_remaining'] !== null)
              @if($category['hours_remaining'] <= 0)
                <br>Lewat {{ number_format(abs($category['hours_remaining']), 2, ',', '.') }} jam dari pembacaan terakhir
              @else
                <br>Sisa {{ number_format($category['hours_remaining'], 2, ',', '.') }} jam dari pembacaan terakhir
              @endif
            @endif
          </small>
        </div>
      @endforeach
    </div>
    @if($unconfiguredCount > 0)
      <p class="pm-hint">{{ $unconfiguredCount }} kategori lain belum memiliki jadwal PM aktif untuk robot ini.</p>
    @endif
  @endif
  <p class="pm-hint">Belum ada plan bukan berarti komponen aman. Nilai sisa jam hanya muncul ketika counter servo terverifikasi; status calendar memakai due date. Cocokkan interval dengan SOP spesifik SP210/YRC1000.</p>
</section>
@endif

@if(config('rms_pm.guest_write_enabled', false))
{{-- NEW: PM planning form, collapsed by default for operators. --}}
<details id="pm-new-plan" class="pm-expand pm-new-plan rms-section-spaced" @if($errors->any()) open @endif>
  <summary>
    <span class="pm-plus" aria-hidden="true"></span>
    <span class="pm-summary-copy">
      <span class="pm-summary-eyebrow">PERENCANAAN PREVENTIVE MAINTENANCE</span>
      <strong>Tambah jadwal maintenance</strong>
      <small>Atur pekerjaan, interval, dan komponen sesuai manual atau SOP robot.</small>
    </span>
    <span class="pm-summary-action" aria-hidden="true">
      <span class="pm-action-closed">Buka formulir</span>
      <span class="pm-action-open">Tutup formulir</span>
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14m-6-6 6 6-6 6"/></svg>
    </span>
  </summary>
  <form method="POST" action="{{ route('rms.maintenance.store') }}" class="pm-form" autocomplete="off">
    @csrf
    <div class="pm-form-grid">
      <label class="field">Robot <span class="pm-required">*</span>
        <select class="input" name="robot_id" required>
          <option value="">Pilih robot</option>
          @foreach($robots as $robot)
            <option value="{{ $robot->id }}" @selected(old('robot_id')==$robot->id)>{{ $robot->robot_code }} — {{ $robot->name }}</option>
          @endforeach
        </select>
      </label>
      <label class="field">Nama pekerjaan <span class="pm-required">*</span>
        <input class="input" name="title" maxlength="180" required placeholder="Contoh: Penggantian grease reducer" value="{{ old('title') }}">
      </label>
      <label class="field">Kategori komponen / pekerjaan
        <select class="input" name="component_category"><option value="">Belum diklasifikasikan</option>
          @foreach($componentCategories as $key=>$label)<option value="{{ $key }}" @selected(old('component_category')===$key)>{{ $label }}</option>@endforeach
        </select>
      </label>
      <label class="field">Axis / komponen
        <select class="input" name="component_axis">
          <option value="">Umum / tidak spesifik</option>
          @foreach(['S','L','U','R','B','T','ALL'] as $axis)
            <option value="{{ $axis }}" @selected(old('component_axis')===$axis)>{{ $axis==='ALL'?'Semua axis':'Axis '.$axis }}</option>
          @endforeach
        </select>
      </label>
      <label class="field">Dasar jadwal
        <select class="input" name="basis" required>
          <option value="operating_hours" @selected(old('basis','operating_hours')==='operating_hours')>Jam operasi robot</option>
          <option value="calendar" @selected(old('basis')==='calendar')>Hari / kalender</option>
        </select>
      </label>
      <label class="field">Interval (jam operasi)
        <input class="input" type="number" name="interval_hours" min="0.01" step="0.01" placeholder="Diisi untuk basis jam" value="{{ old('interval_hours') }}">
      </label>
      <label class="field">Interval (hari)
        <input class="input" type="number" name="interval_days" min="1" step="1" placeholder="Diisi untuk basis kalender" value="{{ old('interval_days') }}">
      </label>
      <label class="field">Target jam servis pertama
        <input class="input" type="number" name="next_due_operating_hours" min="0" step="0.01" placeholder="Kosong = terakhir terukur + interval" value="{{ old('next_due_operating_hours') }}">
      </label>
      <label class="field">Tanggal servis pertama (WIB)
        <input class="input" type="datetime-local" name="next_due_at" value="{{ old('next_due_at') }}">
      </label>
      <label class="field">Spesifikasi grease / pelumas
        <input class="input" name="grease_specification" maxlength="250" placeholder="Sesuai manual robot" value="{{ old('grease_specification') }}">
      </label>
      <label class="field">Referensi manual / SOP
        <input class="input" name="reference_document" maxlength="250" placeholder="Nomor dokumen / halaman" value="{{ old('reference_document') }}">
      </label>
      <label class="field pm-wide">Petunjuk pekerjaan
        <textarea class="input" name="work_instructions" rows="2" placeholder="Contoh: matikan sistem sesuai prosedur, cek area reducer, gunakan pelumas sesuai SOP">{{ old('work_instructions') }}</textarea>
      </label>
      <label class="field pm-wide">Keterangan tambahan
        <textarea class="input" name="description" rows="2" placeholder="Catatan maintenance">{{ old('description') }}</textarea>
      </label>
    </div>
    <p class="pm-hint">Interval dan jenis grease harus berasal dari SOP/manual untuk model robot dan reducer terkait. Tidak ada angka interval otomatis dari Yaskawa yang diasumsikan.</p>
    <button class="btn primary" type="submit">Simpan jadwal baru</button>
  </form>
</details>

@endif

{{-- EXISTING MAINTENANCE PLANS + EDIT AND COMPLETE ACTIONS --}}
<section class="rms-section-spaced">
  <div class="section-heading"><h2>Maintenance plans <span class="count">{{ $plans->total() }}</span></h2></div>
  <div class="rms-pm-grid">
  @forelse($plans as $plan)
    @php($due = $dueStatuses[$plan->id] ?? ['due'=>false,'verified_operating_hours'=>null,'hours_remaining'=>null,'sampled_at'=>null])
    <article class="pm-card {{ $due['due'] ? 'due' : '' }} pm-operator-card">
      <div class="between">
        <span class="badge rms-badge {{ !$plan->is_active ? 'unknown' : ($due['due'] ? 'warning' : 'connected') }}">{{ !$plan->is_active ? 'INACTIVE' : ($due['due'] ? 'DUE' : 'SCHEDULED') }}</span>
        <span class="mono">#{{ $plan->id }}</span>
      </div>
      <h3>{{ $plan->title }}</h3>
      @if($plan->component_category)<p class="pm-hint">Kategori: {{ $componentCategories[$plan->component_category] ?? $plan->component_category }}</p>@endif
      <p>{{ $plan->description ?: 'Belum ada deskripsi pekerjaan' }}</p>
      @if($plan->component_axis || $plan->grease_specification)
        <div class="pm-tags">
          @if($plan->component_axis)<span>Axis {{ $plan->component_axis }}</span>@endif
          @if($plan->grease_specification)<span>Grease: {{ $plan->grease_specification }}</span>@endif
        </div>
      @endif
      <div class="rms-kv-list">
        <div><span>Robot</span><strong>{{ $plan->robot?->robot_code ?? '—' }}</strong></div>
        <div><span>Basis</span><strong>{{ $plan->basis === 'calendar' ? 'Kalender' : 'Operating hours' }}</strong></div>
        <div><span>Interval</span><strong>{{ $plan->basis === 'calendar' ? ($plan->interval_days !== null ? $plan->interval_days.' hari' : '—') : ($plan->interval_hours !== null ? number_format((float)$plan->interval_hours,2,',','.').' jam' : '—') }}</strong></div>
        <div><span>Due date</span><strong>{{ $plan->next_due_at?->timezone('Asia/Jakarta')->format('d M Y H:i') ?? '—' }}</strong></div>
        <div><span>Due operating hours</span><strong>{{ $plan->next_due_operating_hours ?? '—' }}</strong></div>
        <div><span>Verified hours (histori)</span><strong>{{ $due['verified_operating_hours'] !== null ? number_format($due['verified_operating_hours'], 2, ',', '.') : 'Belum tersedia' }}</strong></div>
        @if(($due['hours_remaining']??null)!==null)
          <div><span>Sisa ke jadwal</span><strong>{{ number_format($due['hours_remaining'],2,',','.') }} jam</strong></div>
        @endif
        <div><span>Terakhir selesai</span><strong>{{ $plan->last_completed_at?->timezone('Asia/Jakarta')->format('d M Y H:i') ?? 'Belum ada' }}</strong></div>
      </div>
      @if($due['sampled_at']??null)
        <p class="pm-hint">Jam terbaca pada {{ $due['sampled_at']->timezone('Asia/Jakarta')->format('d M Y H:i') }} WIB (tidak otomatis live).</p>
      @endif
      @if($plan->reference_document)<p class="pm-hint">Referensi: {{ $plan->reference_document }}</p>@endif
      @if($plan->work_instructions)<div class="pm-instruction"><strong>Petunjuk kerja</strong><p>{{ $plan->work_instructions }}</p></div>@endif

      @if(config('rms_pm.guest_write_enabled', false))
      <div class="pm-card-actions">
        @if($plan->is_active)
          <details class="pm-expand pm-card-details">
            <summary>✓ Catat pekerjaan selesai</summary>
            <form method="POST" action="{{ route('rms.maintenance.complete', $plan) }}" class="pm-form" autocomplete="off">
              @csrf
              <label class="field">Nama pelaksana <span class="pm-required">*</span>
                <input class="input" name="performed_by" maxlength="120" required placeholder="Nama teknisi / operator">
              </label>
              @if($plan->basis === 'operating_hours')
                <label class="field">Jam operasi saat servis <span class="pm-required">*</span>
                  <input class="input" name="current_operating_hours" type="number" step="0.01" min="0" required
                    value="{{ $due['verified_operating_hours'] !== null ? number_format($due['verified_operating_hours'], 2, '.', '') : '' }}"
                    placeholder="Baca jam operasi pada controller">
                </label>
                <p class="pm-hint">Angka di atas diambil dari histori terakhir. Cocokkan dengan pembacaan saat pekerjaan dilakukan, terutama jika telemetry STALE.</p>
              @endif
              <label class="field">Tindakan dilakukan
                <textarea class="input" name="action_taken" rows="2" placeholder="Contoh: mengganti grease reducer sesuai SOP"></textarea>
              </label>
              <label class="field">Temuan / catatan (opsional)
                <textarea class="input" name="findings" rows="2" placeholder="Contoh: kondisi reducer normal"></textarea>
              </label>
              <button type="submit" class="btn primary pm-submit">Simpan selesai & hitung jadwal berikutnya</button>
            </form>
          </details>
        @endif
        <details class="pm-expand pm-card-details">
          <summary>✎ Ubah jadwal</summary>
          <form method="POST" action="{{ route('rms.maintenance.update', $plan) }}" class="pm-form" autocomplete="off">
            @csrf
            @method('PATCH')
            <input type="hidden" name="robot_id" value="{{ $plan->robot_id }}">
            <label class="field">Nama pekerjaan
              <input class="input" name="title" maxlength="180" required value="{{ $plan->title }}">
            </label>
            <label class="field">Kategori komponen / pekerjaan
              <select class="input" name="component_category"><option value="">Belum diklasifikasikan</option>
                @foreach($componentCategories as $key=>$label)<option value="{{ $key }}" @selected($plan->component_category===$key)>{{ $label }}</option>@endforeach
              </select>
            </label>
            <label class="field">Axis / komponen
              <select class="input" name="component_axis">
                <option value="">Umum</option>
                @foreach(['S','L','U','R','B','T','ALL'] as $axis)
                  <option value="{{ $axis }}" @selected($plan->component_axis===$axis)>{{ $axis==='ALL'?'Semua axis':'Axis '.$axis }}</option>
                @endforeach
              </select>
            </label>
            <label class="field">Basis
              <select class="input" name="basis" required>
                <option value="operating_hours" @selected($plan->basis==='operating_hours')>Jam operasi</option>
                <option value="calendar" @selected($plan->basis==='calendar')>Kalender</option>
              </select>
            </label>
            <div class="pm-form-grid">
              <label class="field">Interval jam
                <input class="input" type="number" step="0.01" min="0.01" name="interval_hours" value="{{ $plan->interval_hours }}">
              </label>
              <label class="field">Interval hari
                <input class="input" type="number" step="1" min="1" name="interval_days" value="{{ $plan->interval_days }}">
              </label>
              <label class="field">Due jam operasi
                <input class="input" type="number" step="0.01" min="0" name="next_due_operating_hours" value="{{ $plan->next_due_operating_hours }}">
              </label>
              <label class="field">Due tanggal (WIB)
                <input class="input" type="datetime-local" name="next_due_at" value="{{ $plan->next_due_at?->timezone('Asia/Jakarta')->format('Y-m-d\TH:i') }}">
              </label>
            </div>
            <label class="field">Spesifikasi grease / pelumas
              <input class="input" name="grease_specification" maxlength="250" value="{{ $plan->grease_specification }}">
            </label>
            <label class="field">Referensi SOP / manual
              <input class="input" name="reference_document" maxlength="250" value="{{ $plan->reference_document }}">
            </label>
            <label class="field">Petunjuk pekerjaan
              <textarea class="input" name="work_instructions" rows="2">{{ $plan->work_instructions }}</textarea>
            </label>
            <label class="field">Deskripsi
              <textarea class="input" name="description" rows="2">{{ $plan->description }}</textarea>
            </label>
            <label class="field">Status jadwal
              <select class="input" name="is_active">
                <option value="1" @selected($plan->is_active)>Aktif</option>
                <option value="0" @selected(!$plan->is_active)>Nonaktif</option>
              </select>
            </label>
            <button class="btn primary pm-submit" type="submit">Simpan perubahan</button>
          </form>
        </details>
      </div>
      @endif
    </article>
  @empty
    <div class="panel pm-plans-empty" role="status">
      <span class="pm-empty-icon" aria-hidden="true">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M7 3v4m10-4v4M3 10h18m-13 5h8"/></svg>
      </span>
      <div><strong>Belum ada jadwal maintenance pada filter ini</strong>
        @if(config('rms_pm.guest_write_enabled', false))
          <p>Gunakan kartu “Tambah jadwal maintenance” di atas untuk membuat plan berdasarkan manual atau SOP robot.</p>
        @else
          <p>Plan berdasarkan manual atau SOP robot akan tampil di sini setelah jadwal dibuat.</p>
        @endif
      </div>
    </div>
  @endforelse
  </div>
  <div class="rms-pagination">{{ $plans->links() }}</div>
</section>

{{-- EXISTING JOURNAL PRESERVED (same columns / pagination) --}}
<section class="panel rms-section-spaced">
  <div class="panel-header"><h2>Maintenance journal</h2><span class="rms-muted-small">{{ $logs->total() }} record</span></div>
  @if($logs->isEmpty())
    <div class="pm-journal-empty" role="status">
      <span class="pm-empty-icon" aria-hidden="true">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M8 3h8l3 3v15H5V3h3zm8 0v4h4M8 12h8m-8 4h5"/></svg>
      </span>
      <div><strong>Belum ada riwayat pekerjaan pada filter ini</strong>
        <p>Catatan servis akan tampil setelah pekerjaan pada jadwal PM dicatat selesai.</p>
      </div>
    </div>
  @else
    <div class="table-wrap">
      <table class="data-table"><thead><tr><th>Robot</th><th>Waktu pekerjaan</th><th>Teknisi</th><th>Jenis / hasil</th><th>Temuan</th><th>Pemeriksa historis (jika ada)</th></tr></thead><tbody>
      @foreach($logs as $l)
        <tr>
          <td>{{ $l->robot?->robot_code ?? '—' }}</td>
          <td>{{ $l->completed_at?->timezone('Asia/Jakarta')->format('d M Y H:i') ?? ($l->created_at?->timezone('Asia/Jakarta')->format('d M Y H:i') ?? '—') }}</td>
          <td>{{ $l->performed_by }}</td>
          <td>{{ $l->action_type }} · {{ $l->result }}</td>
          <td>{{ \Illuminate\Support\Str::limit($l->findings ?: '—',90) }}</td>
          <td>{{ $l->verified_by ?: '—' }}</td>
        </tr>
      @endforeach
      </tbody></table>
    </div>
    <div class="rms-pagination">{{ $logs->links() }}</div>
  @endif
</section>
<div class="rms-note">{{ $note }} Catat selesai tidak mengubah health robot menjadi HEALTHY otomatis. Form ini tidak meminta login; akses tulis sebaiknya hanya tersedia di jaringan internal terpercaya.</div>
@endsection
