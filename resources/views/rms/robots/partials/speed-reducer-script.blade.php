<script>
@verbatim
(() => {
  'use strict';

  const panel = document.querySelector('[data-speed-reducer-url]');
  if (!panel) return;

  const find = key => panel.querySelector('[data-sr-' + key + ']');
  const group = find('group');
  const axis = find('axis');
  const source = find('source');
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
  let telemetryRows = [];
  let oemRows = [];
  let inspectionRows = [];
  let telemetryUnavailable = false;
  let oemUnavailable = false;
  let inspectionUnavailable = false;
  let mode = 'measurement_pct';
  let pending = null;
  const pageParams = new URL(window.location.href).searchParams;
  const requestedGroup = pageParams.get('reducer_group');
  const initialGroup = /^[RS][1-9][0-9]?$/.test(requestedGroup ?? '') ? requestedGroup : undefined;
  let openOemOnLoad = pageParams.get('reducer_source') === 'oem';

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
      const x = opts.hoursAxis ? number(row.operating_hours) : day(row.date_wib);
      const y = number(row[opts.field]);
      return x !== null && Number.isFinite(x) && y !== null ? [{x, y, row}] : [];
    });
    chart.replaceChildren();
    chart.toggleAttribute('hidden', !points.length);
    empty.hidden = points.length > 0;
    if (!points.length) {
      empty.textContent = opts.empty;
      return;
    }

    const capturedLimits = axis.value && opts.limits
      ? points[points.length - 1].row
      : null;
    const limits = capturedLimits ? opts.limits.map(([key, label]) => [number(capturedLimits[key]), label])
      .filter(([value]) => value !== null) : [];
    const xValues = points.map(p => p.x);
    let xMin = opts.hoursAxis ? 0 : Math.min(...xValues);
    let xMax = Math.max(...xValues);
    if (xMin === xMax) {
      if (!opts.hoursAxis) xMin -= DAY;
      xMax += opts.hoursAxis ? 1 : DAY;
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

    chart.append(label(pad.left, 16, opts.yLabel), label(280, 264, opts.hoursAxis ? 'Jam operasi (h)' : 'Tanggal (WIB)', 'middle'));
    for (let i = 0; i <= 4; i++) {
      const value = yMin + (yMax - yMin) * i / 4;
      chart.append(line(pad.left, py(value), pad.left + w, py(value), '#dbe5ed'),
        label(pad.left - 8, py(value) + 4, numbers.format(value), 'end'));
    }
    const ticks = opts.hoursAxis ? 4 : Math.min(4, Math.floor((xMax - xMin) / DAY));
    for (let i = 0; i <= ticks; i++) {
      let value = xMin + (xMax - xMin) * i / ticks;
      if (!opts.hoursAxis) value = Math.round(value / DAY) * DAY;
      chart.append(label(px(value), 241, opts.hoursAxis ? numbers.format(value) : dateLabel(value), 'middle'));
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
        const xLabel = opts.hoursAxis ? format(p.x) + ' jam operasi' : dateLabel(p.x);
        const details = [key, xLabel, format(p.y) + ' ' + opts.unit, p.row.source_reference];
        if (p.row.sample_count != null) details.push(format(p.row.sample_count) + ' sampel');
        marker.append(svgEl('title', {}, details.join(' · ')));
        chart.append(marker);
      }
    }
  }

  function render() {
    const isOem = source.value === 'oem';
    const forAxis = row => axis.value === '' || row.axis === axis.value;
    const selected = (isOem ? oemRows : telemetryRows).filter(forAxis);
    const variation = mode === 'variation_pp';
    const measurementField = isOem ? 'measurement_pct' : 'measurement_sdk_raw';
    const variationField = isOem ? 'variation_pp' : 'variation_sdk_raw';
    const measurementUnit = isOem ? '%' : 'SDK raw';
    const variationUnit = isOem ? 'poin %' : 'SDK raw';
    find('trend-caption').textContent = isOem
      ? 'Measurement dan Variation dari diagnosis Yaskawa yang tersimpan.'
      : '30 hari WIB · Measurement = rata-rata |torque| · Variation = maksimum − minimum |torque| (min. 2 sampel).';
    draw(find('trend'), find('trend-empty'), selected, {
      field: variation ? variationField : measurementField,
      yLabel: variation ? 'Variation (' + variationUnit + ')' : 'Torque (' + measurementUnit + ')',
      unit: variation ? variationUnit : measurementUnit,
      limits: isOem && variation ? [['variation_lower_pp', 'Batas bawah'], ['variation_upper_pp', 'Batas atas']] : null,
      empty: isOem ? (oemUnavailable ? 'Histori Diagnosis Yaskawa gagal dibaca.' : 'Belum ada hasil diagnosis untuk pilihan ini.')
        : telemetryUnavailable ? 'Telemetry RMS gagal dimuat. Data Diagnosis Yaskawa tetap dapat dipilih jika tersedia.'
        : variation ? 'Variation membutuhkan minimal dua sampel torque valid pada satu tanggal.'
          : 'Belum ada telemetry torque valid untuk pilihan ini dalam 30 hari.',
    });
    const oemLife = oemRows.filter(row =>
      forAxis(row) && number(row.operating_hours) !== null && number(row.remaining_life_hours) !== null);
    const inspectionLife = inspectionRows.filter(forAxis);
    const hoursAxis = oemLife.length > 0 && (isOem || inspectionLife.length === 0);
    const life = hoursAxis ? oemLife : inspectionLife;
    find('life-caption').textContent = hoursAxis
      ? 'Sisa umur terhadap jam operasi dari diagnosis Yaskawa.'
      : 'Sisa umur terhadap tanggal pemeriksaan dari bukti inspeksi terverifikasi.';
    draw(find('life'), find('life-empty'), life, {
      life: true, hoursAxis, field: 'remaining_life_hours', yLabel: 'Sisa umur (h)', unit: 'jam',
      limits: hoursAxis ? [['life_lower_hours', 'Batas bawah'], ['life_upper_hours', 'Batas atas']] : null,
      empty: oemUnavailable || inspectionUnavailable
        ? 'Sebagian sumber sisa umur gagal dibaca.'
        : 'Belum ada data sisa umur terverifikasi untuk pilihan ini.',
    });

    const latest = new Map(selected.map(row => [row.axis, row]));
    const plottedAxes = new Set([...latest.keys(), ...life.map(row => row.axis)]);
    find('evidence').hidden = latest.size === 0;
    find('measurement-heading').textContent = 'Measurement (' + measurementUnit + ')';
    find('variation-heading').textContent = 'Variation (' + variationUnit + ')';
    find('count-heading').hidden = isOem;
    find('operating-heading').hidden = !isOem;
    find('life-heading').hidden = !isOem;
    find('legend').replaceChildren();
    find('evidence-rows').replaceChildren();
    for (const key of order) {
      if (plottedAxes.has(key)) {
        const legend = document.createElement('span');
        const swatch = document.createElement('i');
        swatch.style.backgroundColor = colors[key];
        legend.append(swatch, key);
        find('legend').append(legend);
      }
      const row = latest.get(key);
      if (!row) continue;
      const tr = document.createElement('tr');
      for (const value of [
        key, String(row.date_wib).slice(0, 10), format(row[measurementField]), format(row[variationField]),
        ...(isOem ? [format(row.operating_hours), format(row.remaining_life_hours)] : [format(row.sample_count)]),
        row.source_reference,
      ]) {
        const td = document.createElement('td');
        td.textContent = value;
        tr.append(td);
      }
      find('evidence-rows').append(tr);
    }
  }

  async function load(selectedGroup, refresh = false, selectedSource = openOemOnLoad ? 'oem' : null) {
    if (refresh && pending) return;
    pending?.abort();
    const request = new AbortController();
    pending = request;
    const timeout = window.setTimeout(() => request.abort(), 15000);
    try {
      if (!refresh) {
        status.textContent = 'Memuat data reducer…';
        axis.disabled = true;
        source.disabled = true;
        telemetryRows = [];
        oemRows = [];
        inspectionRows = [];
        telemetryUnavailable = false;
        oemUnavailable = false;
        inspectionUnavailable = false;
        render();
      }
      const url = new URL(panel.dataset.speedReducerUrl, window.location.href);
      if (selectedGroup) url.searchParams.set('group', selectedGroup);
      if (selectedSource) url.searchParams.set('source', selectedSource);
      const response = await fetch(url, {headers: {'Accept': 'application/json'}, signal: request.signal});
      if (!response.ok) throw new Error('HTTP ' + response.status);
      const data = await response.json();
      if (pending !== request) return;
      if (!refresh || [...group.options].map(option => option.value).join(',') !== data.groups.join(',')) {
        group.replaceChildren(...data.groups.map(key => new Option(key, key)));
      }
      group.value = data.group;
      group.disabled = data.groups.length < 2;
      const previousSource = source.value;
      telemetryRows = data.telemetry_rows ?? [];
      telemetryUnavailable = data.telemetry_unavailable === true;
      oemUnavailable = data.oem_unavailable === true;
      inspectionUnavailable = data.inspection_unavailable === true;
      oemRows = data.rows ?? [];
      inspectionRows = data.inspection_life_rows ?? [];
      const telemetryAvailable = data.group === 'R1';
      const onlyOemAvailable = oemRows.length > 0 && telemetryUnavailable;
      source.options[0].disabled = !telemetryAvailable || onlyOemAvailable;
      source.options[1].disabled = !oemRows.length && telemetryAvailable && selectedSource !== 'oem';
      source.disabled = source.options[0].disabled || source.options[1].disabled;
      source.value = selectedSource === 'oem' || !telemetryAvailable || onlyOemAvailable
        || (!selectedSource && oemRows.length && (refresh ? previousSource === 'oem' : telemetryRows.length === 0))
        ? 'oem' : 'telemetry';
      openOemOnLoad = false;
      const available = new Set([...telemetryRows, ...oemRows, ...inspectionRows].map(row => row.axis));
      const axisOptions = order.filter(key => available.has(key));
      if (!refresh || [...axis.options].slice(1).map(option => option.value).join(',') !== axisOptions.join(',')) {
        const previousAxis = refresh ? axis.value : '';
        axis.replaceChildren(new Option('Semua axis', ''), ...axisOptions.map(key => new Option(key, key)));
        axis.value = available.has(previousAxis) ? previousAxis : '';
      }
      axis.disabled = available.size === 0;
      const failedSources = [];
      if (oemUnavailable) failedSources.push('histori CSV');
      if (telemetryUnavailable) failedSources.push('telemetry');
      if (inspectionUnavailable) failedSources.push('inspeksi');
      status.textContent = failedSources.length
        ? data.group + ' · Gagal membaca ' + failedSources.join(', ')
        : data.group;
      render();
    } catch (error) {
      if (pending !== request) return;
      const detail = error.name === 'AbortError' ? 'waktu tunggu habis' : error.message;
      status.textContent = 'Data reducer gagal dimuat (' + detail + '). RMS akan mencoba lagi.';
    } finally {
      window.clearTimeout(timeout);
      if (pending === request) pending = null;
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
  group.addEventListener('change', () => load(group.value, false, source.value));
  axis.addEventListener('change', render);
  source.addEventListener('change', () => {
    render();
    if (source.value === 'telemetry') load(group.value, false, 'telemetry');
  });
  load(initialGroup);
  window.setInterval(() => {
    if (!document.hidden) {
      load(group.value && group.value !== '—' ? group.value : initialGroup, true, openOemOnLoad ? 'oem' : source.value);
    }
  }, 30000);
})();
@endverbatim
</script>
