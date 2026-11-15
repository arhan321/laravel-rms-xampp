(() => {
  'use strict';

  const panel = document.querySelector('[data-speed-reducer-url]');
  if (!panel) return;

  const find = key => panel.querySelector('[data-sr-' + key + ']');
  const group = find('group');
  const axis = find('axis');
  const status = find('status');
  const buttons = [...panel.querySelectorAll('[data-sr-mode]')];
  const colors = {
    S: '#2565ac', L: '#bb5b12', U: '#268053', R: '#bb3546', B: '#7854ae', T: '#795b38',
    1: '#2565ac', 2: '#bb5b12', 3: '#268053', 4: '#bb3546', 5: '#7854ae', 6: '#795b38',
    7: '#15788d', 8: '#365369',
  };
  const order = ['S', 'L', 'U', 'R', 'B', 'T', '1', '2', '3', '4', '5', '6', '7', '8'];
  const numbers = new Intl.NumberFormat('id-ID', {maximumFractionDigits: 6});
  const dates = new Intl.DateTimeFormat('id-ID', {day: 'numeric', month: 'short', year: '2-digit', timeZone: 'UTC'});
  const DAY = 86400000;
  const NS = 'http://www.w3.org/2000/svg';
  let rows = [];
  let mode = 'measurement_pct';
  let pending = null;

  function svgEl(name, attrs, label) {
    const node = document.createElementNS(NS, name);
    Object.entries(attrs).forEach(([key, value]) => node.setAttribute(key, value));
    if (label !== undefined) node.textContent = label;
    return node;
  }

  const number = value => value == null || value === '' || !Number.isFinite(Number(value)) ? null : Number(value);
  const format = value => number(value) === null ? '—' : numbers.format(Number(value));
  const day = value => Date.parse(String(value).slice(0, 10) + 'T00:00:00Z');
  const dateLabel = value => dates.format(new Date(value));

  function draw(chart, empty, selected, opts) {
    const points = selected.flatMap(row => {
      const x = opts.life ? number(row.operating_hours) : day(row.date_wib);
      const y = number(row[opts.field]);
      return x !== null && Number.isFinite(x) && y !== null ? [{x, y, row}] : [];
    });
    chart.replaceChildren();
    chart.toggleAttribute('hidden', !points.length);
    empty.hidden = points.length > 0;
    if (!points.length) {
      empty.textContent = 'Belum ada hasil diagnosis untuk pilihan ini.';
      return;
    }

    const capturedLimits = axis.value && opts.limits
      ? points[points.length - 1].row
      : null;
    const limits = capturedLimits ? opts.limits.map(([key, label]) => [number(capturedLimits[key]), label])
      .filter(([value]) => value !== null) : [];
    const xValues = points.map(p => p.x);
    let xMin = opts.life ? 0 : Math.min(...xValues);
    let xMax = Math.max(...xValues);
    if (xMin === xMax) {
      if (!opts.life) xMin -= DAY;
      xMax += opts.life ? 1 : DAY;
    }
    const yValues = points.map(p => p.y).concat(limits.map(([value]) => value));
    const yMin = opts.field === 'variation_pp' ? Math.min(...yValues, 0) : 0;
    const highest = Math.max(...yValues, 0);
    const yMax = highest === yMin ? yMin + (opts.life ? 1 : .02) : highest;
    const yStep = (yMax - yMin) / 4;
    const yCeiling = yMax + yStep * .35;
    const pad = {left: 70, top: 31, right: 21, bottom: 54};
    const w = 560 - pad.left - pad.right;
    const h = 275 - pad.top - pad.bottom;
    const px = value => pad.left + w * ((value - xMin) / (xMax - xMin));
    const py = value => pad.top + h * (1 - (value - yMin) / (yCeiling - yMin));
    const line = (x1, y1, x2, y2, color, extra = {}) => svgEl('line', {x1, y1, x2, y2, stroke: color, ...extra});
    const label = (x, y, labelText, align = 'start') => svgEl('text', {
      x, y, 'text-anchor': align, 'font-size': '11', fill: '#3b556a',
    }, labelText);

    chart.append(label(pad.left, 16, opts.yLabel), label(280, 264, opts.life ? 'Jam operasi (h)' : 'Tanggal (WIB)', 'middle'));
    for (let i = 0; i <= 4; i++) {
      const value = yMin + (yMax - yMin) * i / 4;
      chart.append(line(pad.left, py(value), pad.left + w, py(value), '#dbe5ed'),
        label(pad.left - 8, py(value) + 4, numbers.format(value), 'end'));
    }
    const ticks = opts.life ? 4 : Math.min(4, Math.floor((xMax - xMin) / DAY));
    for (let i = 0; i <= ticks; i++) {
      let value = xMin + (xMax - xMin) * i / ticks;
      if (!opts.life) value = Math.round(value / DAY) * DAY;
      chart.append(label(px(value), 241, opts.life ? numbers.format(value) : dateLabel(value), 'middle'));
    }

    for (const [value, name] of limits) {
      const guideline = line(pad.left, py(value), pad.left + w, py(value), '#ae5530', {'stroke-dasharray': '5 4'});
      guideline.append(svgEl('title', {}, name + ': ' + format(value) + ' — ' + String(capturedLimits.date_wib).slice(0, 10)));
      chart.append(guideline, label(pad.left + w - 3, py(value) - 4, name, 'end'));
    }

    for (const key of order) {
      const readings = points.filter(p => p.row.axis === key);
      if (!readings.length) continue;
      if (!opts.life && readings.length > 1) {
        let previous = null;
        const d = readings.map(point => {
          const verb = previous !== null && point.x - previous <= DAY ? 'L' : 'M';
          previous = point.x;
          return verb + px(point.x) + ' ' + py(point.y);
        }).join(' ');
        chart.append(svgEl('path', {d, fill: 'none', stroke: colors[key], 'stroke-width': '1.5'}));
      }
      for (const p of readings) {
        const marker = svgEl('circle', {cx: px(p.x), cy: py(p.y), r: '2.7', fill: colors[key]});
        const xLabel = opts.life ? format(p.x) + ' jam operasi' : dateLabel(p.x);
        marker.append(svgEl('title', {}, [key, xLabel, format(p.y) + ' ' + opts.unit, p.row.source_reference].join(' · ')));
        chart.append(marker);
      }
    }
  }

  function render() {
    const selected = rows.filter(row => axis.value === '' || row.axis === axis.value);
    const variation = mode === 'variation_pp';
    draw(find('trend'), find('trend-empty'), selected, {
      field: mode, yLabel: variation ? 'Variation (poin %)' : 'Torque (% rated)',
      unit: variation ? 'poin %' : '%',
      limits: variation ? [['variation_lower_pp', 'Batas bawah'], ['variation_upper_pp', 'Batas atas']] : null,
    });
    draw(find('life'), find('life-empty'), selected, {
      life: true, field: 'remaining_life_hours', yLabel: 'Sisa umur (h)', unit: 'jam',
      limits: [['life_lower_hours', 'Batas bawah'], ['life_upper_hours', 'Batas atas']],
    });

    const latest = new Map(selected.map(row => [row.axis, row]));
    find('evidence').hidden = latest.size === 0;
    find('legend').replaceChildren();
    find('evidence-rows').replaceChildren();
    for (const key of order) {
      const row = latest.get(key);
      if (!row) continue;
      const legend = document.createElement('span');
      const swatch = document.createElement('i');
      swatch.style.backgroundColor = colors[key];
      legend.append(swatch, key);
      find('legend').append(legend);
      const tr = document.createElement('tr');
      for (const value of [
        key, String(row.date_wib).slice(0, 10), format(row.measurement_pct), format(row.variation_pp),
        format(row.operating_hours), format(row.remaining_life_hours), row.source_reference,
      ]) {
        const td = document.createElement('td');
        td.textContent = value;
        tr.append(td);
      }
      find('evidence-rows').append(tr);
    }
  }

  async function load(selectedGroup) {
    pending?.abort();
    const request = new AbortController();
    pending = request;
    const url = new URL(panel.dataset.speedReducerUrl, window.location.href);
    if (selectedGroup) url.searchParams.set('group', selectedGroup);
    status.textContent = 'Memuat hasil diagnosis…';
    rows = [];
    axis.disabled = true;
    render();
    try {
      const response = await fetch(url, {headers: {'Accept': 'application/json'}, signal: request.signal});
      if (!response.ok) throw new Error('HTTP ' + response.status);
      const data = await response.json();
      if (pending !== request) return;
      group.replaceChildren(...data.groups.map(key => new Option(key, key)));
      group.value = data.group;
      group.disabled = data.groups.length < 2;
      rows = data.rows;
      const available = new Set(rows.map(row => row.axis));
      axis.replaceChildren(new Option('Semua axis', ''), ...order.filter(key => available.has(key)).map(key => new Option(key, key)));
      axis.disabled = available.size === 0;
      status.textContent = rows.length ? format(rows.length) + ' catatan diagnosis · ' + data.group : 'Belum ada hasil diagnosis Yaskawa untuk grup ini.';
      render();
    } catch (error) {
      if (pending !== request || error.name === 'AbortError') return;
      status.textContent = 'Hasil diagnosis tidak dapat dimuat. Muat ulang halaman untuk mencoba lagi.';
      find('trend-empty').textContent = 'Grafik tidak dapat dimuat.';
      find('life-empty').textContent = 'Grafik tidak dapat dimuat.';
    }
  }

  for (const button of buttons) {
    button.addEventListener('click', () => {
      mode = button.dataset.srMode;
      for (const candidate of buttons) {
        candidate.classList.toggle('selected', candidate === button);
        candidate.setAttribute('aria-pressed', String(candidate === button));
      }
      render();
    });
  }
  group.addEventListener('change', () => load(group.value));
  axis.addEventListener('change', render);
  load();
})();
