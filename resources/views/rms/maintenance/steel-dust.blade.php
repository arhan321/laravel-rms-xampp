@extends('rms.layouts.app')
@section('heading', 'Steel Dust Monitoring')
@section('subtitle', 'Catat hasil pemeriksaan Steel Dust per axis dan pantau trennya dalam periode dua bulan.')
@push('styles')
  <link rel="stylesheet" href="{{ asset('rms/css/steel-dust.css') }}?v=2">
@endpush

@section('content')
<a class="btn back" href="{{ route('rms.maintenance.index', $robot ? ['robot_id' => $robot->id] : []) }}">← Maintenance</a>

@if(session('steel_dust_success'))
  <div class="steel-feedback" role="status">{{ session('steel_dust_success') }}</div>
@endif
@if($errors->any())
  <div class="steel-feedback steel-feedback-error" role="alert">
    <strong>Periksa kembali isian Steel Dust.</strong>
    <ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
  </div>
@endif

@if(!$robot)
  <section class="panel"><p>Daftarkan robot untuk mulai mencatat pemeriksaan Steel Dust.</p></section>
@else
  <section class="panel steel-section" aria-labelledby="steel-trend">
    <div class="panel-header">
      <div>
        <h2 id="steel-trend">Tren Steel Dust</h2>
        <p>{{ $start->format('d/m/Y') }} – {{ $end->format('d/m/Y') }} WIB · {{ $robot->robot_code }}</p>
      </div>
      @if($canWrite)<a class="btn primary" href="#steel-input">Input pemeriksaan</a>@endif
    </div>
    <form method="GET" action="{{ route('rms.maintenance.steel-dust.index') }}" class="rms-filter-form">
      <label class="field">Robot
        <select name="robot_id" class="input">
          @foreach($robots as $option)
            <option value="{{ $option->id }}" @selected($option->id === $robot->id)>{{ $option->robot_code }} · {{ $option->name }}</option>
          @endforeach
        </select>
      </label>
      <label class="field">Akhir periode (WIB)
        <input name="period_end" type="date" class="input" value="{{ $end->toDateString() }}" max="{{ $today->toDateString() }}">
      </label>
      <label class="field">Jadwal PM
        <select name="plan_id" class="input">
          <option value="">Semua jadwal</option>
          @foreach($plans as $plan)
            <option value="{{ $plan->id }}" @selected($selectedPlan?->id === $plan->id)>#{{ $plan->id }} · {{ $plan->title }}</option>
          @endforeach
        </select>
      </label>
      <label class="field">Satuan grafik
        <select name="unit" class="input" @disabled($units->isEmpty())>
          @forelse($units as $unit)
            <option value="{{ $unit }}" @selected($unit === $selectedUnit)>{{ $unit }}</option>
          @empty
            <option>—</option>
          @endforelse
        </select>
      </label>
      <button class="btn" type="submit">Terapkan</button>
    </form>

    @if($inspections->isEmpty())
      <p class="steel-empty">Belum ada hasil pemeriksaan untuk periode dan jadwal yang dipilih.</p>
    @else
      @include('rms.maintenance.partials.steel-dust-chart')
    @endif
  </section>

  @if($canWrite)
    <section class="panel steel-section" id="steel-input" aria-labelledby="steel-input-heading">
      <div class="panel-header"><h2 id="steel-input-heading">Input pemeriksaan</h2><span>{{ $robot->robot_code }}</span></div>
      @php($oldText = static fn ($key, $fallback = '') => is_scalar(old($key, $fallback)) ? old($key, $fallback) : '')
      <form method="POST" action="{{ route('rms.maintenance.steel-dust.store', $robot) }}">
        @csrf
        <input type="hidden" name="submission_id" value="{{ $oldText('submission_id', $submissionId) ?: $submissionId }}">
        <div class="steel-form-grid">
          <label class="field">Tanggal pemeriksaan (WIB)
            <input name="inspected_on" class="input" type="date" value="{{ $oldText('inspected_on', $today->toDateString()) }}" max="{{ $today->toDateString() }}" required>
          </label>
          <label class="field">Diperiksa oleh
            <input name="inspected_by" class="input" value="{{ $oldText('inspected_by') }}" maxlength="120" required>
          </label>
          <label class="field">Satuan pengukuran
            <input name="unit" class="input" value="{{ $oldText('unit', $selectedUnit) }}" maxlength="24" placeholder="Sesuai alat atau laporan" required>
          </label>
          <label class="field">Kaitkan ke jadwal PM
            <select name="maintenance_plan_id" class="input">
              <option value="">Tanpa jadwal</option>
              @foreach($plans as $plan)
                <option value="{{ $plan->id }}" @selected($oldText('maintenance_plan_id', $selectedPlan?->id) == $plan->id)>
                  #{{ $plan->id }} · {{ $plan->title }}{{ $plan->is_active ? '' : ' (nonaktif)' }}
                </option>
              @endforeach
            </select>
          </label>
        </div>
        <p class="steel-help" id="steel-reading-help">Isi minimal satu hasil. Gunakan satuan yang sama untuk nilai dan batas maksimum. Biarkan batas kosong jika belum disetujui.</p>
        <div class="steel-table-wrap">
          <table class="steel-table steel-entry">
            <thead><tr><th scope="col">Axis</th><th scope="col">Hasil pengukuran</th><th scope="col">Batas maksimum disetujui</th></tr></thead>
            <tbody>
              @foreach($axes as $axis)
                <tr>
                  <th scope="row">{{ $axis }}</th>
                  <td><input class="input" name="values[{{ $axis }}]" value="{{ $oldText('values.'.$axis) }}" inputmode="decimal" maxlength="15" aria-label="Hasil axis {{ $axis }}" aria-describedby="steel-reading-help"></td>
                  <td><input class="input" name="limits[{{ $axis }}]" value="{{ $oldText('limits.'.$axis) }}" inputmode="decimal" maxlength="15" aria-label="Batas axis {{ $axis }}" aria-describedby="steel-reading-help"></td>
                </tr>
              @endforeach
            </tbody>
          </table>
        </div>
        <div class="steel-form-grid steel-reference">
          <label class="field">Rujukan persetujuan batas
            <input name="limit_reference" class="input" value="{{ $oldText('limit_reference') }}" maxlength="250" placeholder="Nomor SOP atau laporan">
          </label>
          <label class="field">Batas disetujui oleh
            <input name="limit_approved_by" class="input" value="{{ $oldText('limit_approved_by') }}" maxlength="120">
          </label>
          <label class="field span">Catatan pemeriksaan
            <textarea name="notes" class="input" maxlength="2000" rows="2">{{ $oldText('notes') }}</textarea>
          </label>
        </div>
        <p class="steel-help">Rujukan dan penyetuju wajib diisi jika ada batas maksimum. Warning muncul saat hasil melebihi batas axis.</p>
        <button type="submit" class="btn primary">Simpan pemeriksaan</button>
      </form>
    </section>
  @endif

  <section class="panel steel-section" aria-labelledby="steel-history">
    <div class="panel-header"><h2 id="steel-history">Riwayat pemeriksaan</h2><span>{{ $inspections->count() }} pemeriksaan</span></div>
    @if($inspections->isEmpty())
      <p class="steel-empty">Riwayat untuk pilihan ini masih kosong.</p>
    @else
      @php($plansById = $plans->keyBy('id'))
      <div class="steel-table-wrap" tabindex="0" role="region" aria-label="Tabel riwayat pemeriksaan Steel Dust">
        <table class="steel-table steel-history">
          <thead>
            <tr>
              <th scope="col">Tanggal (WIB) / Pemeriksa</th>
              @foreach($axes as $axis)<th scope="col">Axis {{ $axis }} ({{ $selectedUnit }})</th>@endforeach
              <th scope="col">Jadwal PM</th>
              <th scope="col">Rujukan / Catatan</th>
            </tr>
          </thead>
          <tbody>
            @foreach($inspections as $inspection)
              @php($plan = $plansById->get($inspection->maintenance_plan_id))
              <tr>
                <th scope="row">
                  {{ $inspection->inspected_on->format('d/m/Y') }}
                  <span>{{ $inspection->inspected_by }}</span>
                </th>
                @foreach($axes as $axis)
                  @php($status = $inspection->statusFor($axis))
                  <td>
                    <strong>{{ $inspection->readings[$axis]['value'] ?? '—' }}</strong>
                    <span class="{{ $status === 'Warning' ? 'steel-warning' : '' }}">{{ $status }}</span>
                    @if(isset($inspection->readings[$axis]['limit']))
                      <span>Maks. {{ $inspection->readings[$axis]['limit'] }}</span>
                    @endif
                  </td>
                @endforeach
                <td>
                  @if($plan)
                    <a href="{{ route('rms.maintenance.index', ['robot_id' => $robot->id, 'plan_id' => $plan->id]) }}#maintenance-plan-{{ $plan->id }}">#{{ $plan->id }} · {{ $plan->title }}</a>
                    @if(!$plan->is_active)
                      <span>Nonaktif</span>
                    @elseif($plan->basis === 'calendar')
                      <span>Jadwal berikutnya {{ $plan->next_due_at?->timezone('Asia/Jakarta')->format('d/m/Y H:i') ?? '—' }} WIB</span>
                    @else
                      <span>Target {{ $plan->next_due_operating_hours ?? '—' }} jam operasi</span>
                    @endif
                  @else
                    —
                  @endif
                </td>
                <td>
                  {{ $inspection->limit_reference ?? '—' }}
                  @if($inspection->limit_approved_by)<span>Disetujui oleh {{ $inspection->limit_approved_by }}</span>@endif
                  @if($inspection->notes)<span>{{ $inspection->notes }}</span>@endif
                </td>
              </tr>
            @endforeach
          </tbody>
        </table>
      </div>
    @endif
  </section>
@endif
@endsection
