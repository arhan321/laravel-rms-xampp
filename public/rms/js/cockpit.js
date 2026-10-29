'use strict';
/**
 * RMS Yaskawa Cockpit (full replacement for public/rms/js/cockpit.js).
 *
 * - Read-only dashboard. Never communicates with the robot controller.
 * - Initial history comes from Blade; new history/metrics come from Laravel JSON.
 * - Refreshes immediately on Reverb invalidation, with a 5-second fallback.
 * - Never fabricates torque, position, operating time, alarm or reducer life.
 * - Keeps the original Measurement, Variation, SLURBT and reducer controls.
 */
(() => {
  const root = document.querySelector('[data-cockpit]');
  const data = window.__RMS_COCKPIT__;
  if (!root || !data) return;

  const AXES = ['S', 'L', 'U', 'R', 'B', 'T'];
  const PERIODS = ['1h', '8h', '24h'];
  const COLORS = {
    S: '#2588c8', L: '#ee8842', U: '#4aaf78',
    R: '#c14d56', B: '#8068ba', T: '#a67d48'
  };
  const STATUS_LABELS = {
    unavailable: 'UNAVAILABLE',
    not_configured: 'NOT CONFIGURED',
    normal: 'NORMAL',
    warning: 'WARNING',
    critical: 'CRITICAL'
  };
  const SOURCE_LABELS = {
    oem_report: 'OEM Report',
    oem_cockpit: 'Yaskawa/OEM Cockpit',
    technician_measurement: 'Technician Measurement'
  };
  const state = {
    mode: 'measurement',
    visible: new Set(AXES),
    focus: 'S',
    reducer: 'S',
    period: '8h'
  };

  const one = (selector, base = document) => base.querySelector(selector);
  const all = (selector, base = document) => [...base.querySelectorAll(selector)];
  const numeric = value => typeof value === 'number' && Number.isFinite(value);
  const parsed = value => value === null || value === undefined || value === '' ||
    !Number.isFinite(Number(value)) ? null : Number(value);
  const fmt = (value, decimals = 2) => {
    const number = parsed(value);
    return number === null ? '—' : number.toLocaleString('id-ID', {
      maximumFractionDigits: decimals
    });
  };
  const escapeHTML = text => String(text ?? '').replace(/[&<>"']/g, character => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
  }[character]));

  // Existing Cockpit URL: /rms/robots/1/cockpit?period=8h
  // The extra endpoint is registered by routes/rms_cockpit_live.php.
  const cockpitUrl = new URL(location.href);
  state.period = PERIODS.includes(cockpitUrl.searchParams.get('period'))
    ? cockpitUrl.searchParams.get('period') : '8h';
  const endpoint = root.dataset.cockpitLiveUrl ||
    `${location.pathname.replace(/\/$/, '')}/live`;

  // Preserve the existing last-child freshness pill selector used by rms-live.js.
  const tags = one('.cp-tags', root);
  let updateStatus = one('#cp-live-update-status', root);
  if (tags && !updateStatus) {
    updateStatus = document.createElement('span');
    updateStatus.id = 'cp-live-update-status';
    updateStatus.className = 'cp-pill cp-muted';
    updateStatus.setAttribute('role', 'status');
    updateStatus.setAttribute('aria-live', 'polite');
    tags.prepend(updateStatus);
  }
  function indicator(text, kind = 'muted') {
    if (!updateStatus) return;
    updateStatus.textContent = text;
    updateStatus.classList.toggle('cp-ok', kind === 'ok');
    updateStatus.classList.toggle('cp-muted', kind !== 'ok');
    updateStatus.title = 'Dashboard mengikuti notifikasi data baru dan memeriksa ulang tiap 5 detik; data berasal dari collector';
  }

  // ---------- Canvas charts (original Cockpit visualization, retained) ----------
  function setupCanvas(canvas) {
    const cssW = Math.max(320, canvas.parentElement?.clientWidth || 320);
    const cssH = window.matchMedia('(max-width:590px)').matches ? 245 :
      (canvas.id === 'cp-reducer-chart' ? 225 : 300);
    const ratio = Math.min(window.devicePixelRatio || 1, 2);
    canvas.width = Math.round(cssW * ratio);
    canvas.height = Math.round(cssH * ratio);
    canvas.style.width = '100%';
    canvas.style.height = cssH + 'px';
    const ctx = canvas.getContext('2d');
    if (!ctx) return null;
    ctx.setTransform(ratio, 0, 0, ratio, 0, 0);
    return {ctx, w: cssW, h: cssH};
  }

  function drawChart(canvas, points, series, limits = [], yLabel = 'Torque Value') {
    if (!canvas) return false;
    const setup = setupCanvas(canvas);
    if (!setup) return false;
    const {ctx, w, h} = setup;
    ctx.clearRect(0, 0, w, h);
    const pad = {left: 54, right: 18, top: 24, bottom: 44};
    const cw = w - pad.left - pad.right;
    const ch = h - pad.top - pad.bottom;
    const values = [];
    for (const line of series) {
      for (const value of line.values) if (numeric(value)) values.push(value);
    }
    for (const limit of limits) if (numeric(limit.value)) values.push(limit.value);
    if (!values.length || !points.length) return false;

    let lo = Math.min(...values);
    let hi = Math.max(...values);
    if (lo === hi) {
      const spread = Math.max(Math.abs(lo) * .15, 1);
      lo -= spread;
      hi += spread;
    }
    const margin = (hi - lo) * .12;
    lo -= margin;
    hi += margin;
    const x = index => pad.left + (points.length === 1
      ? cw / 2 : index / (points.length - 1) * cw);
    const y = value => pad.top + (hi - value) / (hi - lo) * ch;

    ctx.font = '10px Segoe UI,Arial,sans-serif';
    ctx.lineWidth = 1;
    for (let i = 0; i <= 4; i++) {
      const yy = pad.top + i * ch / 4;
      ctx.strokeStyle = '#e5eef2';
      ctx.beginPath();
      ctx.moveTo(pad.left, yy);
      ctx.lineTo(w - pad.right, yy);
      ctx.stroke();
      ctx.fillStyle = '#8199a6';
      ctx.textAlign = 'right';
      ctx.fillText(fmt(hi - i * (hi - lo) / 4), pad.left - 7, yy + 3);
    }
    ctx.textAlign = 'left';
    ctx.fillStyle = '#66899f';
    ctx.fillText(yLabel, pad.left, pad.top - 11);
    for (let i = 0; i < points.length; i += Math.max(1, Math.ceil(points.length / 5))) {
      ctx.fillStyle = '#8397a3';
      ctx.textAlign = 'center';
      ctx.fillText(points[i].time || '', x(i), h - 17);
    }
    if (points.length > 1) {
      ctx.fillText(points[points.length - 1].time || '', x(points.length - 1), h - 17);
    }

    for (const limit of limits) {
      if (!numeric(limit.value)) continue;
      ctx.save();
      ctx.strokeStyle = limit.color;
      ctx.setLineDash([5, 5]);
      ctx.lineWidth = 1.3;
      ctx.beginPath();
      ctx.moveTo(pad.left, y(limit.value));
      ctx.lineTo(w - pad.right, y(limit.value));
      ctx.stroke();
      ctx.restore();
    }
    for (const line of series) {
      ctx.strokeStyle = line.color;
      ctx.lineWidth = 2;
      ctx.lineJoin = 'round';
      ctx.lineCap = 'round';
      ctx.beginPath();
      let started = false;
      for (let i = 0; i < line.values.length; i++) {
        const value = line.values[i];
        if (!numeric(value)) { started = false; continue; }
        if (!started) { ctx.moveTo(x(i), y(value)); started = true; }
        else ctx.lineTo(x(i), y(value));
      }
      ctx.stroke();
      for (let i = line.values.length - 1; i >= 0; i--) {
        if (!numeric(line.values[i])) continue;
        ctx.fillStyle = line.color;
        ctx.beginPath();
        ctx.arc(x(i), y(line.values[i]), 2.5, 0, Math.PI * 2);
        ctx.fill();
        break;
      }
    }
    return true;
  }

  function renderTorque() {
    const canvas = one('#cp-torque-chart');
    if (!canvas) return;
    const points = Array.isArray(data.series) ? data.series : [];
    const series = AXES.filter(axis => state.visible.has(axis)).map(axis => {
      const raw = points.map(point => parsed(point.axes?.[axis]));
      const baseline = raw.find(numeric);
      return {
        color: COLORS[axis],
        values: state.mode === 'variation'
          ? raw.map(value => numeric(value) && numeric(baseline)
            ? value - baseline : null)
          : raw
      };
    });
    const rule = data.axisStatus?.[state.focus] || {};
    const limits = [];
    if (state.mode === 'measurement' && parsed(rule.warning) !== null &&
        parsed(rule.critical) !== null) {
      for (const value of [Number(rule.warning), -Number(rule.warning)]) {
        limits.push({value, color: '#e7a64c'});
      }
      for (const value of [Number(rule.critical), -Number(rule.critical)]) {
        limits.push({value, color: '#d64e4e'});
      }
    }
    const valid = drawChart(canvas, points, series, limits,
      state.mode === 'variation' ? 'Variation Δ' : 'Servo Monitor - Torq');
    const empty = one('#cp-torque-empty');
    if (empty) empty.hidden = valid;
  }

  function renderReducer() {
    const reducer = data.reducerTrend?.[state.reducer] || {points: [], unit: null};
    const points = (reducer.points || []).filter(point => parsed(point.value) !== null)
      .map(point => ({time: point.time, value: Number(point.value)}));
    const latest = data.reducerLatest?.[state.reducer] || null;
    const limits = [];
    if (latest?.condition_unit === reducer.unit) {
      for (const field of ['lower_threshold', 'upper_threshold']) {
        const value = parsed(latest[field]);
        if (value !== null) limits.push({value, color: '#d96a52'});
      }
    }
    const valid = drawChart(one('#cp-reducer-chart'), points,
      [{color: '#2884b9', values: points.map(point => point.value)}],
      limits, reducer.unit || 'Unit belum tersedia');
    const empty = one('#cp-reducer-empty');
    if (empty) empty.hidden = valid;
    const meta = one('#cp-reducer-meta');
    if (!meta) return;
    if (!latest) {
      meta.textContent = 'Belum ada data pemeriksaan terverifikasi untuk axis ini.';
      return;
    }
    const life = parsed(latest.remaining_life_hours) === null
      ? 'Belum tersedia'
      : `${fmt(latest.remaining_life_hours)} jam (sesuai sumber)`;
    const sourceLabel = SOURCE_LABELS[latest.source_type] || latest.source_type || 'Tidak diketahui';
    meta.innerHTML = `<strong>Sumber:</strong> ${escapeHTML(sourceLabel)} · ` +
      `${escapeHTML(latest.source_reference)}` +
      `<br><strong>Pengukuran:</strong> ${escapeHTML(latest.measured_at)} · ` +
      `${escapeHTML(latest.condition_value ?? '—')} ` +
      `${escapeHTML(latest.condition_unit ?? '')}` +
      `<br><strong>Sisa umur dari sumber:</strong> ${escapeHTML(life)}`;
  }

  // ---------- Live DOM synchronization ----------
  function updateMetrics(payload) {
    const metrics = all('.cp-metrics > div > strong');
    if (metrics[0]) metrics[0].textContent = payload.last_sampled_wib || 'Belum tersedia';
    if (metrics[1]) {
      const verified = parsed(payload.operating_hours);
      const raw = parsed(payload.operating_candidate_hours);
      const display = payload.operating_display;
      metrics[1].textContent = display?.text ?? (verified !== null ? `${fmt(verified, 2)} h` :
        raw !== null ? `${fmt(raw, 2)} h (NOT VERIFIED)` : 'Belum tersedia');
      metrics[1].title = display?.caption ?? '';
      metrics[1].dataset.rmsOperatingStatus = display?.status ?? 'unknown';
      const small = metrics[1].parentElement?.querySelector('small');
      if (small) small.textContent = display?.caption ?? (verified !== null ? 'Jam sudah diverifikasi untuk jadwal PM' :
        raw !== null ? 'Format nilai belum diverifikasi; jangan dipakai untuk jadwal PM' : 'Operating Time belum tersedia');
    }
    if (metrics[2]) metrics[2].textContent = String(payload.open_threshold_count ?? 0);
    if (metrics[3]) {
      metrics[3].textContent = String(payload.active_structured_alarm_count ?? 0);
      const small = metrics[3].parentElement?.querySelector('small');
      if (small) {
        const raw = payload.alarm_raw;
        const base = raw?.reported_count === null || raw?.reported_count === undefined
          ? 'Controller Alarm summary belum tersedia'
          : `Controller reported: ${raw.reported_count} · RMS identified: ${raw.identified_count ?? 0}`;
        const quality = raw?.parse_state === 'partial' ? ' · PARTIAL DETAILS' :
          raw?.parse_state === 'unrecognized' ? ' · FORMAT UNRECOGNIZED' : '';
        small.textContent = base + quality;
      }
    }
    // Don't replace the last pill: rms-live.js also updates that same element.
    const pill = one('.cp-tags .cp-pill:last-child');
    if (pill) {
      pill.textContent = payload.fresh ? 'TELEMETRY FRESH' : 'TELEMETRY STALE / UNKNOWN';
      pill.classList.toggle('cp-ok', Boolean(payload.fresh));
      pill.classList.toggle('cp-muted', !payload.fresh);
    }
  }

  function updateAxisRows(payload) {
    const rows = all('.cp-axis-list .cp-axis-item');
    rows.forEach((row, index) => {
      const axis = AXES[index];
      const value = payload.axisStatus?.[axis];
      if (!value) return;
      const main = one('.cp-axis-measure strong', row);
      const detail = one('.cp-axis-measure small', row);
      const badge = one('.cp-state', row);
      if (main) main.textContent = fmt(value.value, 4);
      if (detail) detail.textContent = `Servo Monitor - Torq · Servo Monitor ${fmt(value.pulse, 0)} pulse`;
      if (badge) {
        const status = ['unavailable', 'not_configured', 'normal', 'warning', 'critical']
          .includes(value.status) ? value.status : 'unavailable';
        badge.className = `cp-state cp-${status}`;
        badge.textContent = STATUS_LABELS[status];
      }
    });
  }

  function updateRules(payload) {
    const rows = all('.cp-second-row > .cp-panel:first-child .cp-table tbody tr');
    rows.forEach((row, index) => {
      const axis = AXES[index];
      const rule = payload.configuredRules?.[axis];
      if (!rule) return;
      const cells = [...row.querySelectorAll('td')];
      if (cells[1]) cells[1].textContent = rule.approved ? fmt(rule.warning, 3) : '—';
      if (cells[2]) cells[2].textContent = rule.approved ? fmt(rule.critical, 3) : '—';
      if (cells[3]) cells[3].textContent = rule.approved
        ? `${fmt(rule.hysteresis_percent)}%` : '—';
      if (cells[4]) {
        const badge = document.createElement('span');
        badge.className = rule.approved ? 'cp-state cp-normal' : 'cp-state cp-not_configured';
        badge.textContent = rule.approved ? 'APPROVED' : 'NOT CONFIGURED';
        cells[4].replaceChildren(badge);
      }
    });
  }

  function updateThresholdEvents(payload) {
    const table = one('.cp-last-row > .cp-panel:first-child .cp-table tbody');
    if (!table) return;
    const events = Array.isArray(payload.thresholdEvents) ? payload.thresholdEvents : [];
    table.replaceChildren();
    if (events.length === 0) {
      const row = document.createElement('tr');
      const cell = document.createElement('td');
      cell.colSpan = 5;
      cell.textContent = 'Belum ada Threshold Event yang tercatat. ' +
        'Kondisi ini tidak otomatis membuktikan robot sehat; pastikan threshold sudah dikonfigurasi dan telemetry masih Fresh.';
      row.append(cell);
      table.append(row);
    } else {
      for (const event of events) {
        const row = document.createElement('tr');
        const eventStatus = String(event.status || '—').toUpperCase();
        const fields = [event.time || '—', event.axis || '—', null,
          eventStatus, fmt(event.peak_magnitude, 4)];
        fields.forEach((field, index) => {
          const cell = document.createElement('td');
          if (index === 2) {
            const severity = ['warning', 'critical'].includes(event.severity)
              ? event.severity : 'unavailable';
            const badge = document.createElement('span');
            badge.className = `cp-state cp-${severity}`;
            badge.textContent = severity === 'critical' ? 'CRITICAL' :
              severity === 'warning' ? 'WARNING' : 'UNKNOWN';
            cell.append(badge);
          } else {
            cell.textContent = field;
          }
          row.append(cell);
        });
        table.append(row);
      }
    }
    const subtitle = one('.cp-last-row > .cp-panel:first-child .cp-panel-sub');
    if (subtitle) subtitle.textContent = `${events.length} latest records`;
  }

  function updateEvidence(payload) {
    const controller = payload.controller_state || null;
    const indicators = {
      running: controller?.running, servo_on: controller?.servo_on,
      alarming: controller?.alarming, hold: controller?.hold, error: controller?.error
    };
    for (const [key, value] of Object.entries(indicators)) {
      const badge = one(`[data-cockpit-state="${key}"]`, root);
      if (!badge) continue;
      badge.textContent = typeof value === 'boolean' ? (value ? 'ON' : 'OFF') : 'UNKNOWN';
      badge.className = `cp-state ${typeof value !== 'boolean' ? 'cp-unavailable' : value ? 'cp-warning' : 'cp-normal'}`;
    }
    const modes = {control: controller?.control_mode, cycle: controller?.cycle_mode};
    for (const [key, value] of Object.entries(modes)) {
      const cell = one(`[data-cockpit-mode="${key}"]`, root);
      if (cell) cell.textContent = typeof value === 'string' && value ? value : '—';
    }

    const job = payload.job_detail || {};
    for (const key of ['name', 'line', 'step', 'speed_override_percent']) {
      const cell = one(`[data-cockpit-job="${key}"]`, root);
      if (cell) cell.textContent = job[key] === null || job[key] === undefined || job[key] === '' ? '—' : String(job[key]);
    }

    const info = payload.controller_identity;
    if (info && typeof info === 'object') {
      for (const key of ['robot_r1', 'station_s1', 'application_a1']) {
        const model = one(`[data-cockpit-system="${key}"]`, root);
        const version = one(`[data-cockpit-version="${key}"]`, root);
        if (model) model.textContent = info[key]?.model_name || 'Belum terbaca / tidak tersedia';
        if (version) version.textContent = info[key]?.software_version || 'Belum terbaca';
      }
      const stamp = one('[data-cockpit-info-time]', root);
      if (stamp) stamp.textContent = info.sampled_at || '—';
    }

    const counters = payload.operating_counters;
    if (counters && typeof counters === 'object') {
      for (const key of ['ControllerOnTime', 'ServoPowerOnTimeRobot', 'PlayBackTimeRobot', 'MovingTimeRobot']) {
        const value = counters[key];
        const cell = one(`[data-cockpit-counter="${key}"]`, root);
        const time = one(`[data-cockpit-counter-time="${key}"]`, root);
        if (cell) cell.textContent = parsed(value?.hours) === null ? '—' : `${fmt(value.hours, 2)} h`;
        if (time) time.textContent = value?.sampled_at ? `${value.sampled_at} WIB` : 'Belum terbaca';
      }
    }
  }

  function updateQuality(payload) {
    const fields = all('.cp-quality > div > span');
    if (fields[0]) fields[0].textContent = payload.fresh
      ? 'Fresh data' : 'Stale or unavailable';
    if (fields[1]) fields[1].textContent =
      `${payload.quality?.approved_rules ?? 0} / 6 axis`;
    if (fields[2]) fields[2].textContent =
      `${payload.quality?.verified_reducers ?? 0} / 6 axis`;
    const state = payload.controller_state;
    if (fields[3]) fields[3].textContent = state
      ? `Mode ${state.control_mode ?? '?'} · RUN ${state.running === null ? '?' : state.running ? 'ON' : 'OFF'} · SERVO ${state.servo_on === null ? '?' : state.servo_on ? 'ON' : 'OFF'}`
      : 'Current controller state unavailable';
    if (fields[4]) {
      const job = payload.job_detail;
      if (job?.name) {
        fields[4].textContent = `Job: ${job.name} · I/O belum dinormalisasi`;
      } else {
        fields[4].textContent = 'Job belum terbaca · I/O belum dinormalisasi';
      }
    }
    const lastUpdate = one('#rms-last-update');
    const timestamp = Date.parse(payload.server_time);
    if (lastUpdate && Number.isFinite(timestamp)) {
      const formatted = new Intl.DateTimeFormat('id-ID', {
        timeZone: 'Asia/Jakarta', day: '2-digit', month: 'short', year: 'numeric',
        hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: false
      }).format(new Date(timestamp));
      lastUpdate.textContent = `Data diperiksa: ${formatted} WIB`;
    }
  }

  // ---------- Scheduled AJAX; no page reload, no fabricated readings ----------
  let loading = false;
  let queueRefresh = false;
  let lastRequest = 0;
  let activeController = null;
  let fetchTimer = null;

  async function refresh() {
    if (loading || document.hidden || !root.isConnected) return;
    loading = true;
    lastRequest = Date.now();
    const requestedPeriod = state.period;
    const url = new URL(endpoint, location.origin);
    url.searchParams.set('period', requestedPeriod);
    const controller = new AbortController();
    activeController = controller;
    const timeout = setTimeout(() => controller.abort(), 8000);
    try {
      const response = await fetch(url, {
        method: 'GET',
        headers: {'Accept': 'application/json'},
        credentials: 'same-origin', cache: 'no-store', signal: controller.signal
      });
      if (!response.ok) throw new Error(`HTTP ${response.status}`);
      const payload = await response.json();
      if (requestedPeriod !== state.period || !root.isConnected) return;
      if (!Array.isArray(payload.series) || !payload.axisStatus) {
        throw new Error('Invalid Cockpit JSON payload');
      }
      data.series = payload.series;
      window.dispatchEvent(new CustomEvent('rms:servo-current', {detail: payload.servoCurrent || null}));
      data.axisStatus = payload.axisStatus;
      data.reducerTrend = payload.reducerTrend || {};
      data.reducerLatest = payload.reducerLatest || {};
      updateMetrics(payload);
      updateAxisRows(payload);
      updateRules(payload);
      updateThresholdEvents(payload);
      updateEvidence(payload);
      updateQuality(payload);
      renderTorque();
      renderReducer();
      indicator(payload.fresh ? 'LIVE UPDATE · AUTO 5s' : 'STALE DATA · AUTO 5s',
        payload.fresh ? 'ok' : 'stale');
    } catch (error) {
      // Cancelling an old history range is expected when switching 1h / 8h / 24h.
      if (error.name === 'AbortError' && requestedPeriod !== state.period) return;
      window.dispatchEvent(new CustomEvent('rms:servo-current-failed'));
      // A failed API request does not prove that the robot is healthy or live.
      // Hide instant readings and retain past chart data only as history.
      if (error.name !== 'AbortError') {
        console.warn('[RMS Cockpit] JSON live fetch failed:', error);
        const stalePayload = {
          fresh: false,
          axisStatus: Object.fromEntries(AXES.map(axis => [axis, {
            value: null, pulse: null, status: 'unavailable'
          }]))
        };
        updateAxisRows(stalePayload);
        updateEvidence({controller_state: null, job_detail: null});
        const pill = one('.cp-tags .cp-pill:last-child');
        if (pill) {
          pill.textContent = 'TELEMETRY STALE / UNKNOWN';
          pill.classList.remove('cp-ok');
          pill.classList.add('cp-muted');
        }
      }
      indicator('DATA UPDATE FAILED · RETRYING', 'error');
    } finally {
      clearTimeout(timeout);
      if (activeController === controller) activeController = null;
      loading = false;
      if (queueRefresh) {
        queueRefresh = false;
        setTimeout(() => { if (!document.hidden) void refresh(); }, 0);
      }
    }
  }

  function requestSoon() {
    if (document.hidden) return;
    if (loading) { queueRefresh = true; return; }
    // Throttle focus and periodic checks only; never discard a Reverb signal.
    if (Date.now() - lastRequest < 800) return;
    void refresh();
  }

  function requestOnSignal() {
    if (document.hidden) return;
    // If an older request is still in flight, reread after it completes.
    if (loading) { queueRefresh = true; return; }
    void refresh();
  }

  // ---------- Original chart interactions ----------
  root.querySelectorAll('[data-mode]').forEach(button => button.addEventListener('click', () => {
    state.mode = button.dataset.mode;
    root.querySelectorAll('[data-mode]').forEach(item =>
      item.classList.toggle('selected', item === button));
    renderTorque();
  }));
  root.querySelectorAll('[data-axis]').forEach(button => button.addEventListener('click', () => {
    const axis = button.dataset.axis;
    if (!AXES.includes(axis)) return;
    if (state.visible.has(axis) && state.visible.size > 1) state.visible.delete(axis);
    else state.visible.add(axis);
    button.classList.toggle('is-selected', state.visible.has(axis));
    renderTorque();
  }));
  one('#cp-focus-axis')?.addEventListener('change', event => {
    state.focus = event.target.value;
    renderTorque();
  });
  one('#cp-reducer-axis')?.addEventListener('change', event => {
    state.reducer = event.target.value;
    renderReducer();
  });
  let resizeId = 0;
  window.addEventListener('resize', () => {
    cancelAnimationFrame(resizeId);
    resizeId = requestAnimationFrame(() => { renderTorque(); renderReducer(); });
  });

  // Period switching requests new PostgreSQL history WITHOUT a page refresh.
  all('.cp-periods a', root).forEach(link => link.addEventListener('click', event => {
    const wanted = new URL(link.href, location.origin).searchParams.get('period');
    if (!PERIODS.includes(wanted)) return;
    event.preventDefault();
    state.period = wanted;
    all('.cp-periods a', root).forEach(item =>
      item.classList.toggle('selected', item === link));
    const newUrl = new URL(location.href);
    newUrl.searchParams.set('period', wanted);
    history.replaceState(null, '', newUrl);
    // Don't wait for a slow request from the previous period to complete.
    activeController?.abort();
    if (loading) queueRefresh = true;
    else void refresh();
  }));

  // The Reverb bridge sends an invalidation as soon as the ORM write commits.
  window.addEventListener('rms:cockpit-changed', requestOnSignal);
  window.addEventListener('focus', requestSoon);
  document.addEventListener('visibilitychange', () => {
    if (!document.hidden) requestSoon();
  });
  window.addEventListener('popstate', () => {
    const selected = new URL(location.href).searchParams.get('period');
    state.period = PERIODS.includes(selected) ? selected : '8h';
    all('.cp-periods a', root).forEach(link => link.classList.toggle('selected',
      new URL(link.href, location.origin).searchParams.get('period') === state.period));
    activeController?.abort();
    if (loading) queueRefresh = true;
    else void refresh();
  });

  indicator('STARTING LIVE UPDATE');
  renderTorque();
  renderReducer();
  void refresh();
  fetchTimer = setInterval(requestSoon, 5000);
  window.addEventListener('pagehide', () => {
    clearInterval(fetchTimer);
    activeController?.abort();
  });
})();
