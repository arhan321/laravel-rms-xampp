'use strict';
/** Browser renderer only. All estimates and validity decisions come from Laravel. */
(() => {
  const root = document.querySelector('[data-servo-current]');
  if (!root) return;
  const axes = ['S', 'L', 'U', 'R', 'B', 'T'];
  const colors = ['#2588c8', '#ee8842', '#4aaf78', '#c14d56', '#8068ba', '#a67d48'];
  const visible = new Set(axes);
  const one = selector => root.querySelector(selector);
  const numeric = value => typeof value === 'number' && Number.isFinite(value);
  const format = value => numeric(value) ? value.toLocaleString('id-ID', {minimumFractionDigits: 2, maximumFractionDigits: 2}) : '—';
  const external = root.dataset.servoCurrentExternal === '1';
  let payload = null;
  let deadlines = {};
  let loading = false;
  let activeController = null;
  let destroyed = false;
  let resizeFrame = 0;
  const states = {estimated: 'ESTIMASI', stale: 'STALE', disabled: 'NONAKTIF', pending_sample: 'MENUNGGU DATA'};

  function invalidate(message) {
    for (const axis of axes) {
      const card = one('[data-sc-axis="' + axis + '"]');
      if (!card) continue;
      card.dataset.state = 'stale';
      card.querySelector('[data-sc-amps]').textContent = '—';
      card.querySelector('[data-sc-torque]').textContent = 'Torque: —';
      card.querySelector('[data-sc-state]').textContent = 'STALE';
      card.querySelector('[data-sc-reason]').textContent = message;
    }
    one('[data-sc-live]').textContent = 'Pembaruan terputus; menunggu data baru';
    deadlines = {};
  }

  function render(next) {
    if (!next || Number(next.robot_id) !== Number(window.__RMS_SERVO_CURRENT__?.robot_id) || !next.axes) {
      invalidate('Data estimasi belum tersedia dari server.');
      return;
    }
    payload = next;
    const serverAt = Date.parse(next.server_time);
    const transitSeconds = Number.isFinite(serverAt) ? Math.max(0, (Date.now() - serverAt) / 1000) : 0;
    const sampled = one('[data-sc-sampled]');
    sampled.textContent = 'Torque terakhir: ' + (next.sampled_wib ? next.sampled_wib + ' WIB' : 'Belum tersedia');
    let available = 0;
    for (const axis of axes) {
      const value = next.axes[axis] || {status: 'unavailable', reason: 'Data axis belum tersedia.'};
      const card = one('[data-sc-axis="' + axis + '"]');
      if (!card) continue;
      const lifetime = numeric(value.expires_in_seconds) ? value.expires_in_seconds - transitSeconds : 0;
      const expired = value.status === 'estimated' && lifetime <= 0;
      const status = expired ? 'stale' : value.status;
      const ok = status === 'estimated' && numeric(value.amps);
      card.dataset.state = status || 'not_configured';
      card.querySelector('[data-sc-amps]').textContent = ok ? format(value.amps) : '—';
      card.querySelector('[data-sc-torque]').textContent = 'Torque: ' + (ok ? format(value.torque_percent) + '%' : '—');
      card.querySelector('[data-sc-state]').textContent = states[status] || 'BELUM TERSEDIA';
      card.querySelector('[data-sc-reason]').textContent = expired ? 'Data torque kedaluwarsa; menunggu pembacaan baru.' : value.reason || 'Parameter atau data belum tersedia.';
      deadlines[axis] = ok ? performance.now() + lifetime * 1000 : 0;
      if (ok) available++;
    }
    one('[data-sc-live]').textContent = available + '/6 axis tersedia · estimasi';
    draw();
  }

  function draw() {
    const canvas = one('[data-sc-chart]');
    if (!canvas || !canvas.getContext) return;
    const ctx = canvas.getContext('2d');
    if (!ctx) return;
    const rect = canvas.getBoundingClientRect();
    const width = Math.max(260, rect.width || 900);
    const height = rect.height || 270;
    const ratio = Math.min(3, window.devicePixelRatio || 1);
    canvas.width = Math.round(width * ratio);
    canvas.height = Math.round(height * ratio);
    ctx.setTransform(ratio, 0, 0, ratio, 0, 0);
    ctx.clearRect(0, 0, width, height);
    const points = Array.isArray(payload?.series) ? payload.series.filter(p => numeric(p.at_ms)) : [];
    const values = points.flatMap(p => axes.filter(a => visible.has(a)).map(a => p.axes?.[a])).filter(numeric);
    one('[data-sc-empty]').hidden = values.length > 0;
    if (!values.length) return;
    const left = 57, right = 18, top = 25, bottom = 35;
    const max = Math.max(1, ...values) * 1.12;
    const first = points[0].at_ms;
    const last = points[points.length - 1].at_ms;
    const x = at => left + (at - first) / Math.max(1, last - first) * (width - left - right);
    const y = value => top + (1 - value / max) * (height - top - bottom);
    ctx.font = '10px system-ui, sans-serif';
    for (let n = 0; n <= 4; n++) {
      const value = max * n / 4;
      const yy = y(value);
      ctx.beginPath(); ctx.strokeStyle = '#e4ece6';
      ctx.moveTo(left, yy); ctx.lineTo(width - right, yy); ctx.stroke();
      ctx.fillStyle = '#789080'; ctx.textAlign = 'right';
      ctx.fillText(format(value), left - 7, yy + 3);
    }
    ctx.textAlign = 'left'; ctx.fillText('Arms · estimasi', left, 14);
    const maxGap = (payload?.max_gap_seconds || 30) * 1000;
    for (let i = 0; i < axes.length; i++) {
      const axis = axes[i];
      if (!visible.has(axis)) continue;
      ctx.strokeStyle = colors[i]; ctx.fillStyle = colors[i]; ctx.lineWidth = 2;
      let previous = null;
      for (const point of points) {
        const value = point.axes?.[axis];
        if (!numeric(value)) { previous = null; continue; }
        if (previous && point.at_ms - previous.at_ms <= maxGap &&
            point.profile_ids?.[axis] === previous.profile_ids?.[axis]) {
          ctx.beginPath(); ctx.moveTo(x(previous.at_ms), y(previous.axes[axis]));
          ctx.lineTo(x(point.at_ms), y(value)); ctx.stroke();
        }
        ctx.beginPath(); ctx.arc(x(point.at_ms), y(value), 2, 0, Math.PI * 2); ctx.fill();
        previous = point;
      }
    }
    ctx.fillStyle = '#789080';
    const labels = width < 480 ? 2 : 4;
    for (let i = 0; i < Math.min(points.length, labels); i++) {
      const index = Math.round(i * (points.length - 1) / Math.max(1, Math.min(points.length, labels) - 1));
      const point = points[index];
      ctx.textAlign = i === 0 ? 'left' : i === labels - 1 ? 'right' : 'center';
      ctx.fillText(point.time || '', x(point.at_ms), height - 12);
    }
  }

  async function refresh() {
    if (destroyed || external || loading || document.hidden) return;
    loading = true;
    const controller = new AbortController();
    activeController = controller;
    const timeout = setTimeout(() => controller.abort(), 8000);
    try {
      const url = new URL(root.dataset.servoCurrentUrl, location.origin);
      if (url.origin !== location.origin) throw new Error('Invalid origin');
      const response = await fetch(url, {headers: {Accept: 'application/json'}, credentials: 'same-origin', cache: 'no-store', signal: controller.signal});
      if (!response.ok) throw new Error('HTTP ' + response.status);
      const next = await response.json();
      if (!destroyed) render(next);
    } catch (error) {
      if (!destroyed) invalidate('Pembaruan data gagal; menunggu koneksi dan pembacaan baru.');
    } finally {
      clearTimeout(timeout); loading = false;
      if (activeController === controller) activeController = null;
    }
  }

  root.querySelectorAll('[data-sc-toggle]').forEach(button => button.addEventListener('click', () => {
    const axis = button.dataset.scToggle;
    if (visible.has(axis)) visible.delete(axis); else visible.add(axis);
    button.setAttribute('aria-pressed', visible.has(axis) ? 'true' : 'false');
    draw();
  }));
  window.addEventListener('rms:servo-current', event => { if (external) render(event.detail); });
  window.addEventListener('rms:servo-current-failed', () => { if (external) invalidate('Pembaruan cockpit gagal; menunggu pembacaan baru.'); });
  window.addEventListener('rms:cockpit-changed', () => { if (!external) void refresh(); });
  window.addEventListener('resize', () => {
    cancelAnimationFrame(resizeFrame);
    resizeFrame = requestAnimationFrame(draw);
  });
  const watchdog = setInterval(() => {
    for (const axis of axes) {
      if (!deadlines[axis] || performance.now() < deadlines[axis]) continue;
      const card = one('[data-sc-axis="' + axis + '"]');
      card.dataset.state = 'stale';
      card.querySelector('[data-sc-amps]').textContent = '—';
      card.querySelector('[data-sc-torque]').textContent = 'Torque: —';
      card.querySelector('[data-sc-state]').textContent = 'STALE';
      card.querySelector('[data-sc-reason]').textContent = 'Data torque kedaluwarsa; menunggu pembacaan baru.';
      deadlines[axis] = 0;
      one('[data-sc-live]').textContent = 'Sebagian data kedaluwarsa; menunggu pembaruan';
    }
  }, 500);
  document.addEventListener('visibilitychange', () => { if (!document.hidden) void refresh(); });
  window.addEventListener('focus', () => { void refresh(); });
  const timer = external ? null : setInterval(refresh, 5000);
  window.addEventListener('pagehide', () => {
    destroyed = true; clearInterval(timer); clearInterval(watchdog);
    cancelAnimationFrame(resizeFrame); activeController?.abort();
  });
  render(window.__RMS_SERVO_CURRENT__);
  void refresh();
})();
