'use strict';
/*
 * RMS Yaskawa — Robot Logs UI
 * Full replacement for src/public/rms/js/logs.js
 * - Existing robot/type/date filtering preserved.
 * - JSON <details> progressively enhanced to an accessible read-only modal.
 * - Data always comes from Laravel Blade / PostgreSQL. No invented telemetry.
 * - No remote resources, extra dependencies, API calls, or robot commands.
 */

// Date/shift filter. Validation and SQL queries remain server-side.
(() => {
  const preset = document.getElementById('rms-logs-preset');
  const dateField = document.getElementById('rms-logs-date-field');
  const range = document.getElementById('rms-logs-custom-range');
  const from = document.getElementById('rms-logs-from');
  const to = document.getElementById('rms-logs-to');
  if (!preset || !range || !from || !to) return;
  function sync() {
    const custom = preset.value === 'custom';
    range.hidden = !custom;
    from.disabled = !custom;
    to.disabled = !custom;
    from.required = custom;
    to.required = custom;
    if (dateField) dateField.hidden = custom;
  }
  preset.addEventListener('change', sync);
  sync();
})();

// Multi-select robot picker.
(() => {
  const picker = document.getElementById('rms-logs-robot-select');
  const summary = document.getElementById('rms-logs-robot-summary');
  const chips = document.getElementById('rms-logs-selected-robots');
  const options = Array.from(document.querySelectorAll('#rms-logs-robot-select input[name="robot_ids[]"]'));
  const all = document.getElementById('rms-logs-all');
  const selectAll = document.getElementById('rms-logs-select-all');
  const done = document.getElementById('rms-logs-robot-done');
  const search = document.getElementById('rms-logs-robot-search');
  if (!picker || !summary || !chips) return;

  function renderSelection() {
    const checked = options.filter(option => option.checked);
    summary.textContent = checked.length === 0 ? 'Semua robot'
      : checked.length === 1 ? checked[0].dataset.robotCode : `${checked.length} robot dipilih`;
    chips.replaceChildren();
    chips.hidden = checked.length === 0;
    for (const option of checked) {
      const chip = document.createElement('span');
      chip.className = 'rms-logs-robot-chip';
      chip.textContent = option.dataset.robotCode ?? option.value;
      chips.appendChild(chip);
    }
  }
  function setAll(checked) {
    for (const option of options) option.checked = checked;
    renderSelection();
  }
  options.forEach(option => option.addEventListener('change', renderSelection));
  all?.addEventListener('click', () => setAll(false));
  selectAll?.addEventListener('click', () => setAll(true));
  done?.addEventListener('click', () => { picker.open = false; });
  search?.addEventListener('input', () => {
    const term = search.value.trim().toLocaleLowerCase('id-ID');
    picker.querySelectorAll('.rms-logs-robot-option').forEach(row => {
      row.hidden = !(row.dataset.search ?? '').toLocaleLowerCase('id-ID').includes(term);
    });
  });
  document.addEventListener('click', event => {
    if (picker.open && !picker.contains(event.target)) picker.open = false;
  });
  renderSelection();
})();

// Multi-select log type picker.
(() => {
  const picker = document.getElementById('rms-logs-type-select');
  const summary = document.getElementById('rms-logs-type-summary');
  const chips = document.getElementById('rms-logs-selected-types');
  const options = Array.from(document.querySelectorAll('#rms-logs-type-select input[name="types[]"]'));
  const all = document.getElementById('rms-logs-type-all');
  const events = document.getElementById('rms-logs-type-events');
  const selectAll = document.getElementById('rms-logs-type-select-all');
  const done = document.getElementById('rms-logs-type-done');
  if (!picker || !summary || !chips) return;

  function renderSelection() {
    const selected = options.filter(option => option.checked);
    summary.textContent = selected.length === 0 ? 'Semua jenis log'
      : selected.length === 1 ? (selected[0].dataset.typeLabel ?? selected[0].value)
      : `${selected.length} jenis log dipilih`;
    chips.replaceChildren();
    chips.hidden = selected.length === 0;
    for (const option of selected) {
      const chip = document.createElement('span');
      chip.className = 'rms-logs-type-chip';
      chip.textContent = option.dataset.typeLabel ?? option.value;
      chips.appendChild(chip);
    }
  }
  function selectBy(predicate) {
    for (const option of options) option.checked = predicate(option);
    renderSelection();
  }
  options.forEach(option => option.addEventListener('change', renderSelection));
  all?.addEventListener('click', () => selectBy(() => false));
  events?.addEventListener('click', () => selectBy(option => option.dataset.important === '1'));
  selectAll?.addEventListener('click', () => selectBy(() => true));
  done?.addEventListener('click', () => { picker.open = false; });
  document.addEventListener('click', event => {
    if (picker.open && !picker.contains(event.target)) picker.open = false;
  });
  renderSelection();
})();

// ===============================================================
// USER-FRIENDLY LOG DETAIL — a single modal for every log category.
// ===============================================================
(() => {
  const table = document.querySelector('.rms-logs-table');
  if (!table || !('HTMLDialogElement' in window)) return;

  const axisNames = ['S', 'L', 'U', 'R', 'B', 'T'];
  const axisNamesLong = {S:'S — Base', L:'L — Lower', U:'U — Upper', R:'R — Wrist rotation', B:'B — Wrist bend', T:'T — Tool rotation'};
  const records = new WeakMap();
  const number = (n, maxDigits = 2) => n === null || n === undefined || n === '' ||
    (typeof n !== 'number' && typeof n !== 'string') || !Number.isFinite(Number(n))
    ? '—' : Number(n).toLocaleString('id-ID', {maximumFractionDigits: maxDigits});
  const str = value => value === null || value === undefined || value === '' ? '—' : String(value);
  const bool = value => value === true ? 'Berhasil' : value === false ? 'Gagal / tidak tersedia' : 'Belum diketahui';
  const make = (tag, className = '', text = undefined) => {
    const el = document.createElement(tag);
    if (className) el.className = className;
    if (text !== undefined) el.textContent = str(text);
    return el;
  };
  const add = (parent, ...children) => { for (const el of children) parent.appendChild(el); return parent; };

  // Styling in the same file: no CSS/Blade modifications needed.
  const css = `
.rms-log-view-btn{cursor:pointer;border:1px solid #bedcca;border-radius:8px;padding:7px 11px;font-family:inherit;font-weight:600;font-size:11px;line-height:1.3;background:#eef7f1;color:#22664b;white-space:nowrap;transition:.15s}
.rms-log-view-btn:hover,.rms-log-view-btn:focus-visible{background:#dbeee2;border-color:#83b69a;outline-offset:2px}
.rms-log-modal{border:0;border-radius:18px;padding:0;width:min(840px,calc(100vw - 30px));max-width:840px;max-height:min(90dvh,900px);overflow:hidden;background:#f8faf9;color:#233e31;box-shadow:0 28px 80px #0e231d48;font-family:inherit}
.rms-log-modal::backdrop{background:#10241bd1;backdrop-filter:blur(3px)}
.rms-log-modal-shell{display:flex;flex-direction:column;max-height:min(90dvh,900px)}
.rms-log-modal-head{background:linear-gradient(115deg,#173e33,#28775b);color:white;display:flex;justify-content:space-between;align-items:flex-start;gap:15px;padding:22px 25px 20px}
.rms-log-modal-eyebrow{font-size:10px;font-weight:700;letter-spacing:1.5px;opacity:.75;margin-bottom:8px}
.rms-log-modal-head h2{color:#fff;font-size:22px;font-weight:750;line-height:1.25;margin:0}
.rms-log-modal-subtitle{font-size:12px;color:#d4ede0;margin-top:8px}
.rms-log-modal-close{flex:none;width:36px;height:36px;background:#ffffff24;border:1px solid #ffffff45;border-radius:9px;color:#fff;cursor:pointer;font-size:22px;line-height:1}
.rms-log-modal-close:hover,.rms-log-modal-close:focus-visible{background:#ffffff44}
.rms-log-modal-scroll{overflow:auto;min-height:0;overscroll-behavior:contain;padding:22px 25px 24px}
.rms-log-meta{display:flex;flex-wrap:wrap;gap:7px;margin-bottom:18px}
.rms-log-tag{border-radius:6px;background:#edf3ef;color:#406d55;padding:6px 9px;font-weight:650;font-size:11px}
.rms-log-tag--green{background:#e3f4e8;color:#20714b}
.rms-log-tag--amber{background:#fff0d9;color:#9d661b}
.rms-log-tag--red{background:#fee6e3;color:#aa4338}
.rms-log-intro{background:#fff;border:1px solid #e3ece7;border-radius:11px;padding:14px 16px;margin-bottom:18px;font-size:12px;line-height:1.65;color:#516b5d}
.rms-log-section{margin:18px 0}
.rms-log-section h3{color:#284e3a;font-size:13px;margin:0 0 10px;font-weight:750}
.rms-log-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}
.rms-log-card{border:1px solid #dfeae3;border-radius:10px;background:white;padding:12px 13px;min-width:0}
.rms-log-card-label{font-size:10px;color:#7b9182;font-weight:600;letter-spacing:.1px;margin-bottom:6px}
.rms-log-card-value{font-size:15px;color:#214c36;font-weight:750;overflow-wrap:anywhere;line-height:1.4}
.rms-log-card-caption{font-size:10px;color:#819489;line-height:1.4;margin-top:5px}
.rms-log-axis-wrap{width:100%;overflow:auto;border:1px solid #e2ebe5;border-radius:10px;background:white}
.rms-log-axis{border-collapse:collapse;width:100%;font-size:12px;min-width:440px}
.rms-log-axis th{background:#f0f6f2;text-align:left;font-size:10px;text-transform:uppercase;letter-spacing:.5px;color:#63806e;padding:11px 12px}
.rms-log-axis td{padding:11px 12px;color:#334e3f;border-top:1px solid #edf1ee;font-variant-numeric:tabular-nums}
.rms-log-axis tbody tr:nth-child(even){background:#fafcfb}
.rms-log-axis td:first-child{font-weight:700;color:#1e7650}
.rms-log-note{border-left:3px solid #daa648;background:#fff8e9;color:#806128;border-radius:7px;padding:10px 13px;margin-top:12px;font-size:11px;line-height:1.6}
.rms-log-note--green{border-color:#3da572;background:#ebf7ef;color:#2d7951}
.rms-log-paragraph{font-size:12px;line-height:1.7;color:#476554;white-space:pre-wrap;overflow-wrap:anywhere;background:white;border:1px solid #e3ede6;padding:12px;border-radius:9px}
.rms-log-technical{border:1px solid #dfe9e2;border-radius:10px;margin-top:19px;background:white}
.rms-log-technical summary{list-style:none;cursor:pointer;padding:13px 14px;color:#456854;font-size:12px;font-weight:700;display:flex;justify-content:space-between;gap:12px}
.rms-log-technical summary::-webkit-details-marker{display:none}
.rms-log-tech-content{padding:0 13px 13px}
.rms-log-technical pre{margin:0;padding:13px;border-radius:8px;background:#132b24;color:#deeee3;max-height:220px;overflow:auto;white-space:pre-wrap;overflow-wrap:anywhere;font:11px/1.65 Consolas,monospace}
.rms-log-copy{cursor:pointer;margin-bottom:10px;border:1px solid #bed6c4;background:#f2f8f3;border-radius:7px;color:#2b6f4b;font-size:11px;padding:7px 10px}
.rms-log-modal-footer{display:flex;justify-content:space-between;align-items:center;gap:12px;background:white;border-top:1px solid #e3ebe5;padding:12px 25px;color:#809687;font-size:11px}
.rms-log-modal-done{border:0;border-radius:8px;background:#237c56;color:white;font-weight:700;font-size:12px;padding:10px 19px;cursor:pointer}
.rms-log-modal-done:hover{background:#1c6345}
@media(max-width:640px){.rms-log-modal{width:calc(100vw - 16px);max-height:95dvh}.rms-log-modal-shell{max-height:95dvh}.rms-log-modal-head{padding:17px}.rms-log-modal-head h2{font-size:18px}.rms-log-modal-scroll{padding:15px}.rms-log-modal-footer{padding:12px 15px}.rms-log-grid{grid-template-columns:1fr}.rms-log-axis{min-width:380px}}
@media(prefers-reduced-motion:reduce){.rms-log-view-btn{transition:none}}
  `;
  add(document.head, make('style', 'rms-log-styles', css));

  const modal = make('dialog', 'rms-log-modal');
  modal.setAttribute('aria-labelledby', 'rms-log-dialog-title');
  const shell = make('div', 'rms-log-modal-shell');
  const head = make('div', 'rms-log-modal-head');
  const heading = make('div');
  const eyebrow = make('div', 'rms-log-modal-eyebrow', 'RMS YASKAWA · DETAIL LOG ROBOT');
  const title = make('h2', '', 'Detail pembacaan');
  title.id = 'rms-log-dialog-title';
  const subtitle = make('div', 'rms-log-modal-subtitle');
  add(heading, eyebrow, title, subtitle);
  const close = make('button', 'rms-log-modal-close', '×');
  close.type = 'button';
  close.setAttribute('aria-label', 'Tutup detail log');
  add(head, heading, close);
  const body = make('div', 'rms-log-modal-scroll');
  const footer = make('div', 'rms-log-modal-footer');
  const footerNote = make('span', '', 'Read-only · Historical monitoring data');
  const done = make('button', 'rms-log-modal-done', 'Tutup');
  done.type = 'button';
  add(footer, footerNote, done);
  add(shell, head, body, footer);
  modal.appendChild(shell);
  document.body.appendChild(modal);

  let lastButton = null;
  const closeDialog = () => { if (modal.open) modal.close(); };
  close.addEventListener('click', closeDialog);
  done.addEventListener('click', closeDialog);
  modal.addEventListener('click', event => {
    if (event.target === modal) closeDialog();
  });
  modal.addEventListener('close', () => {
    if (lastButton && lastButton.isConnected) lastButton.focus();
    lastButton = null;
  });

  function category(label) {
    const lower = label.toLowerCase();
    if (lower.includes('telemetry')) return 'telemetry';
    if (lower.includes('alarm reading')) return 'alarm_snapshot';
    if (lower.includes('alarm events')) return 'alarm_event';
    if (lower.includes('connection')) return 'connection';
    if (lower.includes('operating')) return 'operating';
    if (lower.includes('equipment health')) return 'health';
    if (lower.includes('maintenance journal')) return 'maintenance';
    if (lower.includes('maintenance recommendation')) return 'recommendation';
    return 'other';
  }
  function section(name) {
    const wrap = make('section', 'rms-log-section');
    const header = make('h3', '', name);
    const content = make('div');
    add(wrap, header, content);
    body.appendChild(wrap);
    return content;
  }
  function grid(name, fields) {
    const parent = section(name);
    const cards = make('div', 'rms-log-grid');
    for (const [label, value, caption] of fields) {
      const card = make('div', 'rms-log-card');
      add(card, make('div', 'rms-log-card-label', label), make('div', 'rms-log-card-value', value));
      if (caption) card.appendChild(make('div', 'rms-log-card-caption', caption));
      cards.appendChild(card);
    }
    parent.appendChild(cards);
  }
  function note(message, positive = false) {
    body.appendChild(make('div', `rms-log-note${positive ? ' rms-log-note--green' : ''}`, message));
  }
  function paragraph(name, value) {
    const place = section(name);
    place.appendChild(make('div', 'rms-log-paragraph', value));
  }
  function axisTable(data) {
    const place = section('Pembacaan enam axis · S / L / U / R / B / T');
    const wrap = make('div', 'rms-log-axis-wrap');
    const tableEl = make('table', 'rms-log-axis');
    const header = make('thead');
    const tr = make('tr');
    for (const v of ['Axis', 'Position (pulse)', 'Motor Torque']) tr.appendChild(make('th', '', v));
    header.appendChild(tr);
    const tbody = make('tbody');
    const pos = data.position_pulses && typeof data.position_pulses === 'object' ? data.position_pulses : {};
    const torque = data.torque_axes && typeof data.torque_axes === 'object' ? data.torque_axes : {};
    for (const axis of axisNames) {
      const row = make('tr');
      add(row,
        make('td', '', axisNamesLong[axis]),
        make('td', '', data.position_ok === true ? number(pos[axis], 0) : 'Tidak tersedia'),
        make('td', '', data.torque_ok === true ? number(torque[axis], 4) : 'Tidak tersedia'));
      tbody.appendChild(row);
    }
    add(tableEl, header, tbody);
    wrap.appendChild(tableEl);
    place.appendChild(wrap);
  }
  function renderTelemetry(data) {
    grid('Hasil pembacaan sensor', [
      ['Position / Pulse', bool(data.position_ok), 'Status pembacaan sensor, bukan status gerak robot'],
      ['Motor Torque', bool(data.torque_ok), 'Controller monitor value — unit belum dinyatakan sebagai Nm atau %'],
      ['Urutan pembacaan', str(data.sequence), 'Nomor urut data monitoring'],
      ['Sumbu robot', '6 axis · S / L / U / R / B / T', 'Control group dapat dilihat pada Robot Detail'],
    ]);
    axisTable(data);
    if (data.torque_ok === true && data.torque_axes && axisNames.every(ax => typeof data.torque_axes[ax] === 'number' && data.torque_axes[ax] === 0)) {
      note('Seluruh torque tercatat 0 dalam sampel ini. Hal ini tidak otomatis berarti robot sehat atau sensor rusak; periksa kondisi servo dan gerakan robot saat pengambilan data.');
    } else if (data.torque_ok === true) {
      note('Motor Torque belum dinyatakan sebagai % atau Nm. Cocokkan nilainya dengan pendant atau referensi yang disahkan sebelum menetapkan threshold.');
    }
    if (data.position_ok !== true || data.torque_ok !== true) {
      note('Ada pembacaan yang gagal atau belum tersedia. Nilai hilang ditampilkan sebagai “Tidak tersedia”, bukan nol.');
    }
  }
  function parseElapsed(value) {
    if (typeof value !== 'string') return null;
    const m = value.match(/Elapsed\s*Time\s*:\s*(\d+)\s*Hours?\s*:\s*(\d+)\s*Minutes?\s*:\s*(\d+)\s*Seconds?/i);
    if (!m) return null;
    const hours = Number(m[1]), mins = Number(m[2]), secs = Number(m[3]);
    if (mins > 59 || secs > 59) return null;
    return `${number(hours, 0)} jam ${mins} menit ${secs} detik`;
  }
  function renderOperating(data) {
    const verified = data.read_ok === true && typeof data.value_seconds === 'number' && Number.isFinite(data.value_seconds) && data.value_seconds >= 0;
    grid('Informasi jam operasi', [
      ['Jenis waktu', str(data.time_type)],
      ['Status pembacaan', bool(data.read_ok)],
      ['Operating hours terverifikasi', verified ? `${number(data.value_seconds / 3600)} jam` : 'Belum terverifikasi', 'Hanya nilai jam yang sudah diperiksa yang ditampilkan'],
      ['Controller Status Code', str(data.status_code)],
    ]);
    const candidate = parseElapsed(data.raw_value);
    if (candidate) {
      grid('Operating Time yang terbaca', [['Elapsed Time', candidate, 'Belum merupakan angka PM terverifikasi']]);
    }
    if (data.raw_value) paragraph('Keterangan asli dari controller', data.raw_value);
    if (data.message) note(data.message);
    if (!verified) note('Operating Time belum diverifikasi terhadap pendant Yaskawa. Jangan gunakan angka ini untuk menjadwalkan penggantian komponen secara otomatis.');
  }
  function renderAlarmSnapshot(data) {
    grid('Status pembacaan alarm', [
      ['Read Status', bool(data.read_ok)],
      ['Jumlah alarm dilaporkan', data.active_alarm_count === null || data.active_alarm_count === undefined ? 'Belum terstruktur' : number(data.active_alarm_count, 0)],
      ['Status code', str(data.status_code)],
      ['Identified Alarm Events', Array.isArray(data.parsed_alarms) ? `${data.parsed_alarms.length} alarm teridentifikasi` : 'Belum tersedia'],
    ]);
    if (data.sdk_text) {
      paragraph('Informasi alarm dari controller', data.sdk_text);
      if (/^No active alarms\.?\s*$/i.test(data.sdk_text.trim())) {
        note('Controller melaporkan tidak ada alarm aktif pada pembacaan ini. Alarm History dan pembacaan terbaru tetap perlu diperiksa.', true);
      } else if (/\d+\s+alarms?\s+active/i.test(data.sdk_text)) {
        note('Jumlah alarm yang dilaporkan controller belum tentu sama dengan rincian yang berhasil diidentifikasi. Hanya alarm dengan identitas lengkap yang menjadi Alarm Event.');
      }
    }
    if (data.message) note(data.message);
    if (data.read_ok !== true) note('Pembacaan alarm gagal. Jangan menyimpulkan tidak ada alarm.');
  }
  function renderAlarmEvent(data) {
    grid('Detail alarm yang teridentifikasi', [
      ['Kode alarm', str(data.alarm_code)],
      ['Subcode', str(data.subcode)],
      ['Nama alarm', str(data.alarm_name)],
      ['Severity', str(data.severity)],
      ['Waktu terakhir terlihat', str(data.last_seen_at)],
      ['Waktu clear', str(data.cleared_at)],
    ]);
    if (data.source) note('Status Alarm Event mengikuti riwayat monitoring yang tersimpan; bukan pemeriksaan langsung robot saat ini.');
    if (data.details && typeof data.details === 'object') note('Informasi tambahan untuk pemeriksaan lanjutan sudah tersimpan pada RMS.');
  }
  function renderConnection(data) {
    const value = String(data.state ?? '').toLowerCase();
    grid('Informasi koneksi', [
      ['Data Connection', value === 'connected' ? 'Terhubung' : value === 'offline' ? 'Terputus' : value === 'reconnecting' ? 'Menghubungkan ulang' : value === 'stopped' ? 'Dihentikan' : str(data.state)],
      ['IP controller', str(data.ip_address)],
    ]);
    note('Data Connection menunjukkan jalur monitoring tersedia. Status ini berbeda dari RUN/STOP, SERVO ON/OFF, dan kondisi keselamatan fisik robot.');
  }
  function renderHealth(data) {
    grid('Informasi penilaian kondisi', [
      ['Sumber penilaian', data.assessment_source === 'rms_rule_engine' ? 'RMS monitoring rules' : 'RMS health assessment'],
      ['Status bukti', data.indicators?.evidence_complete === true ? 'Lengkap' : 'Belum lengkap'],
    ]);
    const indicators = data.indicators;
    if (indicators && typeof indicators === 'object') {
      const axis = indicators.axis;
      if (axis && typeof axis === 'object') {
        const fields = axisNames.map(a => [`Axis ${a}`, str(axis[a]?.state ?? 'UNKNOWN')]);
        grid('Status tiap axis', fields);
      }
      grid('Kelengkapan data', [
        ['Telemetry terbaru', bool(indicators.telemetry_current)],
        ['Data alarm terbaru', bool(indicators.alarm_data_current)],
        ['Bukti lengkap', bool(indicators.evidence_complete)],
        ['Jumlah alarm aktif', indicators.alarm_count === null || indicators.alarm_count === undefined ? 'Belum diketahui' : number(indicators.alarm_count, 0)],
      ]);
    }
    note('Assessment ini dihitung oleh RMS dan tidak menggantikan inspeksi teknisi ataupun algoritma kesehatan OEM. UNKNOWN bukan HEALTHY.');
  }
  function renderMaintenance(data) {
    grid('Catatan kegiatan maintenance', [
      ['Mulai pengerjaan', str(data.started_at)],
      ['Selesai pengerjaan', str(data.completed_at)],
      ['Diverifikasi oleh', str(data.verified_by)],
      ['Waktu verifikasi', str(data.verified_at)],
    ]);
    if (data.findings) paragraph('Temuan pemeriksaan', data.findings);
    if (data.action_taken) paragraph('Tindakan yang dilakukan', data.action_taken);
    if (!data.findings && !data.action_taken) note('Belum ada rincian temuan/tindakan pada record ini.');
  }
  function renderRecommendation(data) {
    grid('Ringkasan rekomendasi', [
      ['Prioritas', str(data.priority)],
      ['Sumber rekomendasi', data.source === 'manual_operator' ? 'Temuan operator' : 'RMS condition review'],
    ]);
    if (data.recommendation) paragraph('Tindakan yang direkomendasikan', data.recommendation);
    if (data.evidence && typeof data.evidence === 'object') note('Bukti pendukung rekomendasi sudah tersimpan dan dapat ditinjau pada halaman Maintenance Recommendation.');
    note('Rekomendasi otomatis harus diverifikasi tim maintenance sebelum melakukan tindakan pada robot.');
  }
  const renderers = {
    telemetry: renderTelemetry,
    operating: renderOperating,
    alarm_snapshot: renderAlarmSnapshot,
    alarm_event: renderAlarmEvent,
    connection: renderConnection,
    health: renderHealth,
    maintenance: renderMaintenance,
    recommendation: renderRecommendation,
  };
  function render(record) {
    const {row, data} = record;
    const typeLabel = row.querySelector('.rms-logs-type')?.textContent.trim() ?? 'Jenis log tidak diketahui';
    const kind = category(typeLabel);
    title.textContent = ({telemetry:'Detail pembacaan sensor', operating:'Detail jam operasi', alarm_snapshot:'Detail snapshot alarm', alarm_event:'Detail kejadian alarm', connection:'Detail koneksi', health:'Detail equipment health', maintenance:'Detail maintenance', recommendation:'Detail rekomendasi'})[kind] || 'Detail log robot';
    subtitle.textContent = `${row.querySelector('.rms-link')?.textContent.trim() || 'Robot'} · ${row.querySelector('.rms-logs-date-cell')?.textContent.trim() || 'Waktu belum tersedia'} WIB`;
    body.replaceChildren();
    body.scrollTop = 0;
    const meta = make('div', 'rms-log-meta');
    const status = row.querySelector('.rms-logs-status')?.textContent.trim() || 'UNKNOWN';
    const tone = status === 'OK' || status === 'READ OK' || status === 'CONNECTED' ? 'green'
      : /FAILED|OFFLINE|CRITICAL|ACTIVE/i.test(status) ? 'red' : 'amber';
    add(meta,
      make('span', 'rms-log-tag', typeLabel),
      make('span', `rms-log-tag rms-log-tag--${tone}`, status),
      make('span', 'rms-log-tag', 'Historical record'));
    body.appendChild(meta);
    const summary = row.querySelector('.rms-logs-summary')?.textContent.trim();
    if (summary) body.appendChild(make('div', 'rms-log-intro', summary));
    if (renderers[kind]) renderers[kind](data);
    else paragraph('Isi catatan', 'Informasi untuk jenis catatan ini belum memiliki tampilan khusus.');
  }

  // Extract JSON already rendered by Blade; no new endpoint/query is needed.
  for (const row of table.querySelectorAll('tbody tr')) {
    const old = row.querySelector('details.rms-logs-details');
    const pre = old?.querySelector('pre');
    if (!old || !pre) continue;
    try {
      const raw = pre.textContent;
      const data = JSON.parse(raw);
      if (!data || typeof data !== 'object' || Array.isArray(data)) continue;
      const button = make('button', 'rms-log-view-btn', 'Lihat detail ↗');
      button.type = 'button';
      button.setAttribute('aria-label', `Lihat detail log ${row.querySelector('.rms-link')?.textContent.trim() || 'robot'}`);
      records.set(button, {row, data});
      old.replaceWith(button);
    } catch (_) {
      // If raw JSON is malformed, keep original <details> instead of hiding diagnostics.
    }
  }
  table.addEventListener('click', event => {
    const button = event.target.closest('.rms-log-view-btn');
    if (!button || !table.contains(button)) return;
    const record = records.get(button);
    if (!record) return;
    lastButton = button;
    try { render(record); modal.showModal(); close.focus(); }
    catch (e) {
      // Keep the application usable if a rare browser-specific rendering error occurs.
      console.error('RMS log detail unavailable', e);
    }
  });
})();
