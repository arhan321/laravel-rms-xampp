const AXES = ['S', 'L', 'U', 'R', 'B', 'T'];
const STATES = ['connected', 'offline', 'stale', 'unknown', 'healthy', 'critical', 'normal', 'warning', 'upnormal'];
const one = (root, selector) => root.querySelector(selector);
const all = (root, selector) => [...root.querySelectorAll(selector)];
const numeric = (value, digits = 0) => value === null || value === undefined || !Number.isFinite(Number(value))
    ? '—' : Number(value).toLocaleString('id-ID', {maximumFractionDigits: digits});

function badge(element, text, state, family = '', reason = '') {
    if (!element) return;
    element.classList.remove(...STATES, 'servo-on', 'servo-off', 'servo-unknown', 'home-at_home', 'home-away', 'home-unknown');
    element.classList.add(family ? `${family}-${state}` : state);
    element.textContent = text;
    element.title = reason;
}

function counter(root, selector, display, captionSelector) {
    const element = one(root, selector);
    if (!element) return;
    element.textContent = display?.text ?? 'Belum tersedia';
    element.title = display?.caption ?? '';
    element.dataset.rmsOperatingStatus = display?.status ?? 'unavailable';
    const caption = one(element.parentElement, captionSelector);
    if (caption) caption.textContent = display?.caption ?? '';
}

function shared(root, robot, detail = false) {
    const connectivity = STATES.includes(robot.connectivity) ? robot.connectivity : 'unknown';
    const connectionText = robot.is_demo ? `DEMO · ${connectivity.toUpperCase()}`
        : connectivity === 'connected' ? 'LIVE DATA' : connectivity.toUpperCase();
    badge(one(root, '[data-rms-connectivity-label]'), connectionText, connectivity);
    const health = ['normal', 'upnormal', 'warning'].includes(robot.health) ? robot.health : 'unknown';
    badge(one(root, '[data-rms-health-label]'), `${detail ? 'HEALTH: ' : ''}${health.toUpperCase()}`, health, '', robot.health_reason);
    const servo = ['on', 'off'].includes(robot.servo?.state) ? robot.servo.state : 'unknown';
    badge(one(root, '[data-rms-servo]'), `SERVO ${servo.toUpperCase()}`, servo, 'servo', robot.servo?.reason);
    const home = ['at_home', 'away'].includes(robot.home_position?.status) ? robot.home_position.status : 'unknown';
    badge(one(root, '[data-rms-home]'), home === 'at_home' ? 'AT HOME' : home === 'away' ? 'OUTSIDE HOME' : 'UNKNOWN', home, 'home', robot.home_position?.reason);
    counter(root, '[data-rms-servo-hours]', robot.operating_display, '[data-rms-operating-caption]');
    counter(root, '[data-rms-control-hours]', robot.control_power_display, '[data-rms-control-caption]');
    all(root, '[data-rms-identity]').forEach(element => {
        const key = element.dataset.rmsIdentity;
        const value = robot.identity?.[key];
        element.textContent = value ? (key === 'nameplate_date' ? value.replace('-', '.') : value) : 'Belum diisi';
    });
    const alarm = one(root, '[data-rms-alarm-count]');
    if (alarm) alarm.textContent = `${detail ? '' : 'Alarm aktif: '}${robot.alarm_count ?? '—'}`;
}

export function updateRobotCard(card, robot) {
    if (!card || !robot) return;
    shared(card, robot);
    card.classList.remove(...STATES);
    card.classList.add(STATES.includes(robot.connectivity) ? robot.connectivity : 'unknown');
    card.dataset.rmsHealth = robot.health;
    card.dataset.rmsConnectivity = robot.connectivity;
    const stamp = one(card, '.robot-bottom .between strong');
    if (stamp) stamp.textContent = robot.last_sampled_wib?.split(' ').at(-1) || 'Belum ada';
}

export function updateRobotDetail(robot, root = document) {
    if (!robot) return;
    shared(root, robot, true);
    const stamp = one(root, '[data-rms-last-sampled]');
    if (stamp) stamp.textContent = robot.last_sampled_wib ?? 'Belum ada';
    const panels = all(root, '.rms-detail-columns > section.panel');
    panels.slice(0, 2).forEach((panel, index) => {
        const isPosition = index === 1;
        const good = isPosition ? robot.position_ok : robot.torque_ok;
        badge(one(panel, '.rms-badge'), good ? 'READ OK · FRESH' : 'UNAVAILABLE / STALE', good ? 'connected' : 'unknown');
        const table = one(panel, '.rms-axis-table');
        if (!table) return;
        table.replaceChildren(...AXES.map(axis => {
            const row = document.createElement('div'); row.className = 'rms-axis';
            const label = document.createElement('span'); label.className = 'axis-name'; label.textContent = axis;
            const value = document.createElement('span'); value.className = 'mono';
            value.textContent = numeric(good ? robot.axes?.[axis]?.[isPosition ? 'pulse' : 'torque'] : null, isPosition ? 0 : 4);
            row.append(label, value); return row;
        }));
    });
    const reason = one(root, '[data-rms-home-reason]');
    if (reason) reason.textContent = robot.home_position?.reason ?? 'Home position belum ditetapkan.';
    const body = one(root, '[data-rms-home-axes]');
    if (body) body.replaceChildren(...AXES.map(axis => {
        const reading = robot.home_position?.axes?.[axis];
        const row = document.createElement('tr');
        [axis, numeric(reading?.target), numeric(reading?.current), numeric(reading?.delta), numeric(robot.home_position?.tolerance_pulses)].forEach(value => {
            const cell = document.createElement('td'); cell.textContent = value; row.append(cell);
        });
        row.classList.toggle('rms-home-delta-outside', reading?.within_tolerance === false);
        return row;
    }));
}

/** Expire status even when both WebSocket and HTTP stop delivering new data. */
export function expireRobotSnapshot(robot, now, staleSeconds = 30) {
    const olderThan = (value, seconds) => {
        const age = now - Date.parse(value);
        return !Number.isFinite(age) || age < -5000 || age > seconds * 1000;
    };
    let changed = false;
    const expired = robot.connectivity === 'connected'
        && (olderThan(robot.last_received_at, staleSeconds) || olderThan(robot.last_sampled_at, 60));
    if (expired) {
        robot.connectivity = 'stale'; robot.torque_ok = false; robot.position_ok = false;
        robot.alarm_count = null;
        for (const axis of Object.values(robot.axes ?? {})) { axis.torque = null; axis.pulse = null; axis.status = 'unavailable'; }
        const home = robot.home_position ?? {};
        robot.home_position = {...home, status: 'unknown', label: 'UNKNOWN', reason: 'Pembacaan posisi sudah tidak segar.', sampled_at: null,
            axes: Object.fromEntries(Object.entries(home.axes ?? {}).map(([axis, value]) => [axis, {...value, current: null, delta: null, within_tolerance: null}]))};
        changed = true;
    }
    if ((expired || (robot.servo?.valid_until ? now > Date.parse(robot.servo.valid_until) : olderThan(robot.servo?.received_at ?? robot.servo?.sampled_at, 30))) && robot.servo?.state !== 'unknown') {
        robot.servo = {state: 'unknown', label: 'SERVO UNKNOWN', reason: 'Status controller belum tersedia atau tidak segar.'}; changed = true;
    }
    if ((expired || (robot.health_valid_until ? now > Date.parse(robot.health_valid_until) : olderThan(robot.last_health_at, 60))) && robot.health !== 'unknown') {
        robot.health = 'unknown'; robot.health_label = 'UNKNOWN';
        robot.health_reason = 'Data atau assessment health belum segar dan lengkap.'; changed = true;
    }
    return changed;
}

/** The first HTTP request can fail before there is a snapshot to age out. */
export function invalidateInitialMonitor(root = document) {
    const reason = 'Pembaruan monitoring belum tersedia. Menunggu pembacaan yang segar.';
    all(root, '[data-rms-servo]').forEach(element => badge(element, 'SERVO UNKNOWN', 'unknown', 'servo', reason));
    all(root, '[data-rms-health-label]').forEach(element => badge(element, 'UNKNOWN', 'unknown', '', reason));
    all(root, '[data-rms-home]').forEach(element => badge(element, 'UNKNOWN', 'unknown', 'home', reason));
    all(root, '[data-rms-home-reason]').forEach(element => { element.textContent = reason; });
    all(root, '.rms-detail-columns .rms-axis .mono').forEach(element => { element.textContent = '—'; });
    all(root, '.rms-detail-columns .rms-badge').forEach(element => badge(element, 'UNAVAILABLE / STALE', 'unknown'));
    all(root, '[data-rms-home-axes] tr').forEach(row => {
        [2, 3].forEach(index => { if (row.children[index]) row.children[index].textContent = '—'; });
    });
}
