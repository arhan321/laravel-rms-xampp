'use strict';
// RMS Yaskawa web UI: only requests server-rendered Laravel views.
// No synthetic telemetry, no direct controller commands, no localStorage.
(() => {
  const root = document.querySelector('[data-rms-page]');
  if (!root) return;
  const menu = document.getElementById('rms-menu-button');
  const sidebar = document.getElementById('rms-sidebar');
  menu?.addEventListener('click', () => {
    const isOpen = sidebar.classList.toggle('open');
    menu.setAttribute('aria-expanded', String(isOpen));
  });
  const refreshBtn = document.getElementById('rms-refresh-button');
  const message = document.getElementById('rms-refresh-message');
  let pending = false;
  let paused = false;
  let lastError = '';

  async function refresh(manual = false) {
    if (pending || document.hidden || (!manual && paused)) return;
    pending = true;
    refreshBtn?.setAttribute('disabled', 'disabled');
    const controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), 12000);
    try {
      // Fetch same-origin Blade page through existing authenticated web route.
      const response = await fetch(window.location.href, {
        method: 'GET',
        credentials: 'same-origin',
        headers: { 'Accept': 'text/html', 'X-Requested-With': 'XMLHttpRequest' },
        cache: 'no-store',
        signal: controller.signal
      });
      if (!response.ok || response.redirected || !response.headers.get('content-type')?.includes('text/html')) {
        throw new Error(response.status === 401 || response.status === 403 || response.redirected ? 'Sesi login telah berakhir atau akses ditolak.' : 'Layanan monitoring sedang tidak tersedia.');
      }
      const html = await response.text();
      const doc = new DOMParser().parseFromString(html, 'text/html');
      const next = doc.getElementById('rms-dynamic-content');
      const current = document.getElementById('rms-dynamic-content');
      if (!next || !current) throw new Error('Data pembaruan tidak dapat dibaca.');
      // Avoid disrupting keyboard focus while user is typing in a filter.
      if (!current.contains(document.activeElement) || manual) {
        current.replaceWith(document.importNode(next, true));
      }
      const nextTime = doc.getElementById('rms-last-update');
      const currentTime = document.getElementById('rms-last-update');
      if (nextTime && currentTime) currentTime.textContent = nextTime.textContent;
      if (lastError || manual) message.textContent = 'Monitoring data berhasil diperbarui.';
      lastError = '';
    } catch (err) {
      const detail = err?.name === 'AbortError' ? 'Pembaruan melewati batas waktu.' : String(err.message || err);
      if (lastError !== detail || manual) message.textContent = `Pembaruan gagal: ${detail} Data terakhir tetap ditampilkan.`;
      lastError = detail;
    } finally {
      clearTimeout(timeout);
      pending = false;
      refreshBtn?.removeAttribute('disabled');
    }
  }
  refreshBtn?.addEventListener('click', () => refresh(true));
  document.addEventListener('visibilitychange', () => { paused = document.hidden; });
  // Refresh only where page declares an interval; initial render is always SSR.
  const sec = Number(document.getElementById('rms-dynamic-content')?.dataset.rmsRefresh);
  if (Number.isFinite(sec) && sec >= 10 && sec <= 300) {
    setInterval(() => refresh(false), sec * 1000);
  }
})();
