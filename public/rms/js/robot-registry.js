'use strict';

(() => {
  const form = document.querySelector('[data-robot-registry-form]');
  if (!form) return;

  const ip = form.querySelector('[data-ip-input]');
  const button = form.querySelector('[data-probe-button]');
  const status = form.querySelector('[data-probe-status]');
  const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';

  const render = (text, kind = 'muted') => {
    if (!status) return;
    status.textContent = text;
    status.dataset.state = kind;
  };

  button?.addEventListener('click', async () => {
    const value = ip?.value.trim() || '';
    if (!value) {
      render('IP wajib diisi', 'error');
      ip?.focus();
      return;
    }
    button.disabled = true;
    render('Menguji koneksi dari komputer monitoring…', 'pending');
    try {
      const body = new URLSearchParams({ip_address: value});
      const response = await fetch(form.dataset.probeUrl, {
        method: 'POST',
        headers: {
          Accept: 'application/json',
          'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
          'X-CSRF-TOKEN': csrf,
          'X-Requested-With': 'XMLHttpRequest',
        },
        credentials: 'same-origin',
        cache: 'no-store',
        body,
      });
      let data;
      try {
        data = await response.json();
      } catch (_) {
        throw new Error('Hasil pemeriksaan tidak dapat dibaca. Coba lagi.');
      }
      if (!response.ok || !data.ok) throw new Error(data.message || 'Pemeriksaan IP gagal. Coba lagi.');
      render(`VALID · ${data.message}`, 'ok');
    } catch (error) {
      render(`GAGAL · ${error.message || error}`, 'error');
    } finally {
      button.disabled = false;
    }
  });
})();
