<section class="config-section" id="speed-reducer-oem">
  <header class="config-section-head">
    <div>
      <span class="config-kicker">DIAGNOSIS SPEED REDUCER</span>
      <h2>Impor hasil diagnosis Yaskawa</h2>
      <p>Gunakan laporan atau data Yaskawa Cockpit untuk robot ini. Masukkan nilai yang tersedia sesuai sumber; kolom yang tidak tersedia bisa dikosongkan.</p>
    </div>
    <a class="config-button" href="{{ route('rms.robots.cockpit', $robot) }}#speed-reducer-diagnosis">Buka grafik</a>
  </header>

  @php($oemErrors = $errors->getBag('reducerOem'))
  <form method="POST" action="{{ route('rms.robots.configuration.speed-reducer-oem', $robot) }}" class="config-reducer-form">
    @csrf
    <div class="config-form-block">
      @if($oemErrors->any())
        <ul role="alert">@foreach($oemErrors->all() as $message)<li>{{ $message }}</li>@endforeach</ul>
      @endif
      <div class="config-form-grid">
        <label class="config-field"><span>Rujukan laporan Yaskawa</span><input name="source_reference" maxlength="255" value="{{ $oemErrors->any() ? old('source_reference') : '' }}" required placeholder="Contoh: nama laporan dan periodenya"></label>
        <label class="config-field"><span>Diperiksa oleh</span><input name="verified_by" maxlength="120" value="{{ old('verified_by') }}" required></label>
        <label class="config-field config-field-wide">
          <span>Data CSV dalam format RMS</span>
          <textarea name="csv" rows="7" maxlength="500000" required>{{ old('csv', implode(',', \App\Services\Rms\SpeedReducerOemImport::COLUMNS)."\n") }}</textarea>
          <small>Tanggal YYYY-MM-DD, pemisah koma, desimal titik. Satu baris per tanggal, grup, dan axis. Measurement dalam persen rated torque; Variation dalam poin persentase. Jam operasi dan sisa umur diisi berpasangan.</small>
        </label>
      </div>
      <label class="config-verify"><input type="checkbox" name="verified" value="1" required><span>Saya sudah mencocokkan robot, axis, tanggal, dan angka dengan sumber Yaskawa.</span></label>
    </div>
    <div class="config-form-actions">
      <p>Jika tanggal, grup, dan axis sudah tercatat, baris baru menggantikan baris tersebut. Isi kembali nilai lama yang ingin dipertahankan.</p>
      <button type="submit" class="config-button config-button-primary">Simpan Hasil Diagnosis</button>
    </div>
  </form>
</section>
