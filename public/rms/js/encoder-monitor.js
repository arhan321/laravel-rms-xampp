(() => {
    'use strict';
    const root = document.querySelector('[data-encoder-monitor]');
    const initial = document.getElementById('rms-encoder-data');
    if (!root || !initial) return;
    const axes = ['S', 'L', 'U', 'R', 'B', 'T'];
    const one = selector => root.querySelector(selector);
    const fmt = value => value === null || value === undefined || !Number.isFinite(Number(value)) ? '—' : Number(value).toLocaleString('id-ID', {maximumFractionDigits: 0});
    const text = (selector, value) => { const element = one(selector); if (element) element.textContent = value ?? '—'; };
    const canvas = one('[data-encoder-chart]');
    const auto = one('[data-encoder-auto]');
    let snapshot, axis = 'S', expiresAt = 0, ageBase = 0, appliedAt = performance.now(), pollTimer, controller;
    try { snapshot = JSON.parse(initial.textContent); } catch { return; }

    function tableRow(values) {
        const row = document.createElement('tr');
        for (const value of values) {
            const cell = document.createElement('td');
            cell.textContent = value ?? '—';
            row.append(cell);
        }
        return row;
    }
    function renderCurrent() {
        const live = snapshot.status === 'ok';
        const badge = one('[data-encoder-status]');
        badge.dataset.state = snapshot.status;
        badge.textContent = snapshot.label;
        text('[data-encoder-time]', snapshot.sampled_wib);
        text('[data-encoder-code]', snapshot.read_code);
        text('[data-encoder-message]', snapshot.read_message || '');
        for (const name of axes) {
            const element = one(`[data-encoder-axis="${name}"]`);
            const data = snapshot.axes[name];
            element.querySelector('[data-pulse]').textContent = fmt(live ? data.pulse : null);
            element.querySelector('[data-delta]').textContent = fmt(live ? data.delta : null);
            element.querySelector('[data-last-pulse]').textContent = snapshot.status === 'stale' && data.last_pulse !== null ? `Terakhir tersimpan: ${fmt(data.last_pulse)}` : '';
        }
        const home = snapshot.home;
        text('[data-home-status]', live ? home.label : (home.configured ? 'UNKNOWN' : home.label));
        text('[data-home-reason]', live ? home.reason : (home.configured ? 'Menunggu pembacaan posisi yang segar dan lengkap.' : home.reason));
        for (const name of axes) {
            const row = one(`[data-home-axis="${name}"]`);
            row.querySelector('[data-home-target]').textContent = fmt(home.axes?.[name]?.target);
            row.querySelector('[data-home-delta]').textContent = fmt(live ? home.axes?.[name]?.delta : null);
        }
    }
    function apply(data) {
        if (Number(data.robot_id) !== Number(snapshot.robot_id) || !data.axes || !Array.isArray(data.rows)) throw new Error('Invalid snapshot');
        snapshot = data;
        appliedAt = performance.now();
        ageBase = data.age_seconds;
        expiresAt = appliedAt + Math.max(0, Number(data.expires_in_seconds) || 0) * 1000;
        renderCurrent();
        text('[data-count-total]', data.statistics.total);
        text('[data-count-valid]', data.statistics.valid);
        text('[data-count-invalid]', data.statistics.invalid);
        text('[data-encoder-window]', data.window_note);
        for (const name of axes) {
            const row = one(`[data-stat-axis="${name}"]`);
            row.querySelector('[data-min]').textContent = fmt(data.axes[name].min);
            row.querySelector('[data-max]').textContent = fmt(data.axes[name].max);
        }
        const history = one('[data-encoder-history]');
        history.replaceChildren();
        for (const row of [...data.rows].reverse()) history.append(tableRow([
            row.time_wib, row.sequence, row.label, ...axes.map(name => fmt(row.axes[name])), row.read_code
        ]));
        if (!data.rows.length) {
            const row = tableRow(['Belum ada sampel dalam 24 jam terakhir.']);
            row.firstChild.colSpan = 10;
            history.append(row);
        }
        const alarms = one('[data-encoder-alarms]');
        alarms.replaceChildren();
        for (const alarm of data.encoder_alarms) {
            const row = tableRow([alarm.time_wib, `${alarm.code ?? '—'} / ${alarm.subcode ?? '—'}`, alarm.name, alarm.status]);
            const cell = document.createElement('td');
            const link = document.createElement('a');
            const url = new URL(alarm.url, location.href);
            if (url.origin === location.origin && /^https?:$/.test(url.protocol)) link.href = url.href;
            link.textContent = 'Lihat penanganan →';
            cell.append(link); row.append(cell); alarms.append(row);
        }
        if (!data.encoder_alarms.length) {
            const row = tableRow(['Belum ada event berlabel encoder yang tersimpan. Ini bukan konfirmasi bahwa encoder bebas gangguan.']);
            row.firstChild.colSpan = 5; alarms.append(row);
        }
        tick(); draw();
    }
    function tick() {
        const elapsed = Math.max(0, Math.floor((performance.now() - appliedAt) / 1000));
        text('[data-encoder-age]', ageBase === null ? '—' : `${ageBase + elapsed} detik`);
        if (snapshot.status === 'ok' && performance.now() >= expiresAt) {
            snapshot.status = 'stale'; snapshot.label = 'DATA KEDALUWARSA'; renderCurrent();
        }
    }
    function draw() {
        const width = Math.max(280, canvas.getBoundingClientRect().width), height = canvas.getBoundingClientRect().height || 270;
        const ratio = window.devicePixelRatio || 1;
        canvas.width = Math.round(width * ratio); canvas.height = Math.round(height * ratio);
        const ctx = canvas.getContext('2d'); if (!ctx) return;
        ctx.scale(ratio, ratio); ctx.clearRect(0, 0, width, height);
        text('[data-encoder-chart-label]', `Axis ${axis} · pulse`);
        canvas.setAttribute('aria-label', `Trend posisi axis ${axis} dalam pulse`);
        const rows = snapshot.rows, valid = rows.filter(row => row.axes[axis] !== null && Number.isFinite(row.at_ms));
        one('[data-encoder-chart-empty]').hidden = valid.length > 0;
        if (!valid.length) return;
        const values = valid.map(row => row.axes[axis]);
        let min = Math.min(...values), max = Math.max(...values);
        const pad = Math.max(1, (max - min) * .12); min -= pad; max += pad;
        const times = rows.filter(row => Number.isFinite(row.at_ms)).map(row => row.at_ms);
        const start = Math.min(...times), end = Math.max(...times);
        const left = Math.min(105, Math.max(78, width * .13)), right = 24, top = 22, bottom = height - 35;
        const x = time => end === start ? (width + left - right) / 2 : left + (time - start) / (end - start) * (width - left - right);
        const y = value => bottom - (value - min) / (max - min) * (bottom - top);
        ctx.font = '11px sans-serif'; ctx.fillStyle = '#738a81'; ctx.strokeStyle = '#e2eae5'; ctx.lineWidth = 1;
        for (let i = 0; i < 5; i++) {
            const value = min + (max - min) * i / 4, py = y(value);
            ctx.beginPath(); ctx.moveTo(left, py); ctx.lineTo(width - right, py); ctx.stroke();
            ctx.textAlign = 'right'; ctx.fillText(fmt(value), left - 9, py + 4);
        }
        const timeLabel = time => new Date(time).toLocaleTimeString('id-ID', {timeZone:'Asia/Jakarta', hour12:false});
        ctx.textAlign = 'left'; ctx.fillText(timeLabel(start), left, height - 12);
        ctx.textAlign = 'right'; ctx.fillText(timeLabel(end), width - right, height - 12);
        let previous = null;
        ctx.strokeStyle = '#3d7b64'; ctx.fillStyle = '#3d7b64'; ctx.lineWidth = 2;
        for (const row of rows) {
            const value = row.axes[axis];
            if (value === null || !Number.isFinite(row.at_ms)) { previous = null; continue; }
            const point = [x(row.at_ms), y(value)];
            if (previous && row.continuous) {ctx.beginPath(); ctx.moveTo(...previous); ctx.lineTo(...point); ctx.stroke();}
            ctx.beginPath(); ctx.arc(...point, valid.length > 150 ? 1.3 : 2.3, 0, Math.PI * 2); ctx.fill();
            previous = point;
        }
    }
    async function poll() {
        clearTimeout(pollTimer);
        if (!auto.checked || document.hidden) return;
        controller = new AbortController();
        const request = controller;
        const timeout = setTimeout(() => request.abort(), 5000);
        try {
            const response = await fetch(root.dataset.liveUrl, {headers:{Accept:'application/json'}, credentials:'same-origin', cache:'no-store', signal:request.signal});
            if (!response.ok) throw new Error('Snapshot unavailable');
            const data = await response.json();
            if (auto.checked && controller === request) {apply(data); text('[data-encoder-connection]', 'Data diperiksa · pembaruan otomatis aktif.');}
        } catch {
            if (auto.checked) text('[data-encoder-connection]', 'Pembaruan gagal. Periksa koneksi ke server RMS.');
            tick();
        } finally {
            clearTimeout(timeout);
            if (controller === request) {
                controller = null;
                if (auto.checked && !document.hidden) pollTimer = setTimeout(poll, 5000);
            }
        }
    }
    root.querySelectorAll('[data-chart-axis]').forEach(button => button.addEventListener('click', () => {
        axis = button.dataset.chartAxis;
        root.querySelectorAll('[data-chart-axis]').forEach(item => item.setAttribute('aria-pressed', item === button ? 'true' : 'false'));
        draw();
    }));
    auto.addEventListener('change', () => {
        clearTimeout(pollTimer); controller?.abort();
        text('[data-encoder-connection]', auto.checked ? 'Memeriksa data…' : 'Pembaruan dijeda; angka akan kedaluwarsa sesuai usia data.');
        if (auto.checked) poll();
    });
    document.addEventListener('visibilitychange', () => {
        clearTimeout(pollTimer); if (document.hidden) controller?.abort(); else if (auto.checked) poll();
    });
    window.addEventListener('resize', draw);
    apply(snapshot);
    const ticker = setInterval(tick, 1000);
    pollTimer = setTimeout(poll, 5000);
    window.addEventListener('pagehide', () => { clearInterval(ticker); clearTimeout(pollTimer); controller?.abort(); }, {once:true});
})();
