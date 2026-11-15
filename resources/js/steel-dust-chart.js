(() => {
    const chart = document.querySelector('[data-steel-dust-chart]');
    if (!chart) return;

    const canvas = chart.querySelector('canvas');
    const context = canvas.getContext('2d');
    if (!context) return;

    const { axes, start, end, unit, points } = JSON.parse(chart.querySelector('[data-chart-data]').textContent);
    const palette = ['#295ea0', '#ae5324', '#28704e', '#8e3f68', '#644da2', '#707025'];
    const warningColor = '#ae3827';
    const day = 86400000;
    const dateFormat = new Intl.DateTimeFormat('id-ID', { day: 'numeric', month: 'short', timeZone: 'UTC' });
    const numberFormat = new Intl.NumberFormat('id-ID', { maximumFractionDigits: 7 });
    const startTime = Date.parse(start);
    const endTime = Date.parse(end);
    const series = axes.map((axis, index) => ({
        axis,
        color: palette[index],
        samples: points.map(point => ({
            time: Date.parse(point.date),
            value: point.readings[axis].value === null ? null : Number(point.readings[axis].value),
            warning: point.readings[axis].warning,
        })),
    }));
    let maximum = 0;
    for (const { samples } of series) {
        for (const sample of samples) {
            if (sample.value !== null) maximum = Math.max(maximum, sample.value);
        }
    }
    const topValue = maximum > 0 ? maximum * 1.1 : 1;

    const legend = chart.querySelector('[data-chart-legend]');
    for (const { axis, color } of series) {
        addLegend('Axis ' + axis, color);
    }
    if (series.some(({ samples }) => samples.some(sample => sample.warning))) {
        addLegend('Warning (cincin merah)', warningColor);
    }

    function addLegend(label, color) {
        const item = document.createElement('span');
        item.textContent = label;
        item.style.setProperty('--series-color', color);
        legend.append(item);
    }

    function draw() {
        const { width, height } = canvas.getBoundingClientRect();
        if (width < 1 || height < 1) return;

        const scale = window.devicePixelRatio || 1;
        canvas.width = Math.round(width * scale);
        canvas.height = Math.round(height * scale);
        context.setTransform(scale, 0, 0, scale, 0, 0);
        context.font = '12px system-ui, sans-serif';
        context.fillStyle = '#41554a';
        context.textBaseline = 'middle';

        const tickCount = 4;
        const labels = Array.from({ length: tickCount + 1 }, (_, index) => numberFormat.format(topValue * index / tickCount));
        const left = Math.max(...labels.map(label => context.measureText(label).width)) + 12;
        const right = width - 16;
        const top = 27;
        const bottom = height - 38;
        if (right <= left) return;

        const x = time => left + (time - startTime) / (endTime - startTime) * (right - left);
        const y = value => bottom - value / topValue * (bottom - top);

        context.textAlign = 'left';
        context.fillText(unit, left, 10, right - left);
        context.textAlign = 'right';
        labels.forEach((label, index) => {
            const row = y(topValue * index / tickCount);
            context.fillText(label, left - 8, row);
            context.strokeStyle = '#dce6df';
            context.beginPath();
            context.moveTo(left, row);
            context.lineTo(right, row);
            context.stroke();
        });

        const dateTicks = Math.max(1, Math.min(5, Math.floor((right - left) / 100)));
        for (let index = 0; index <= dateTicks; index++) {
            const time = startTime + Math.round((endTime - startTime) / day * index / dateTicks) * day;
            context.textAlign = index === 0 ? 'left' : index === dateTicks ? 'right' : 'center';
            context.fillText(dateFormat.format(time), x(time), bottom + 17);
        }

        for (const { color, samples } of series) {
            context.strokeStyle = color;
            context.lineWidth = 2;
            context.beginPath();
            let previous = false;
            for (const sample of samples) {
                if (sample.value === null) {
                    previous = false;
                    continue;
                }
                const pointX = x(sample.time);
                const pointY = y(sample.value);
                if (previous) context.lineTo(pointX, pointY);
                else context.moveTo(pointX, pointY);
                previous = true;
            }
            context.stroke();

            context.fillStyle = color;
            for (const { value, time } of samples) {
                if (value === null) continue;
                context.beginPath();
                context.arc(x(time), y(value), 3, 0, Math.PI * 2);
                context.fill();
            }
        }

        // Draw warning rings after every series so overlapping samples cannot hide them.
        context.strokeStyle = warningColor;
        for (const { samples } of series) {
            for (const { value, time, warning } of samples) {
                if (value === null || !warning) continue;
                context.beginPath();
                context.arc(x(time), y(value), 6, 0, Math.PI * 2);
                context.stroke();
            }
        }
    }

    draw();
    if ('ResizeObserver' in window) {
        new ResizeObserver(draw).observe(chart);
    } else {
        window.addEventListener('resize', draw);
    }
})();
