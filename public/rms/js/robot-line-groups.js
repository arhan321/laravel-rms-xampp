const emptySummary = () => ({registered: 0, fresh: 0, attention: 0});

export function summarizeRobotLineGroups(robots) {
    const groups = new Map([['all', emptySummary()]]);
    for (const robot of robots) {
        const key = robot.monitor_group_key;
        if (typeof key !== 'string' || !key) continue;
        if (!groups.has(key)) groups.set(key, emptySummary());
        for (const target of [groups.get('all'), groups.get(key)]) {
            target.registered += 1;
            target.fresh += robot.connectivity === 'connected' ? 1 : 0;
            target.attention += ['warning', 'upnormal'].includes(robot.health) ? 1 : 0;
        }
    }
    return groups;
}

/** Reuses the fleet snapshot and its freshness checks; opens no extra connection. */
export function syncRobotLineGroups(payload, documentRoot = document) {
    const root = documentRoot.querySelector('[data-rms-line-monitor]');
    if (!root || !Array.isArray(payload?.robots)) return;
    if (payload.robots.some(robot => typeof robot.monitor_group_key !== 'string')) {
        invalidateRobotLineGroups(documentRoot);
        return;
    }
    const robots = payload.robots;
    const summaries = summarizeRobotLineGroups(robots);
    root.querySelectorAll('[data-rms-line-summary]').forEach(node => {
        const counts = summaries.get(node.dataset.rmsLineSummary) || emptySummary();
        node.querySelectorAll('[data-rms-group-stat]').forEach(element => {
            element.textContent = String(counts[element.dataset.rmsGroupStat] ?? 0);
        });
        const status = node.querySelector('[data-rms-group-status]');
        if (status) {
            status.textContent = counts.registered === 0 ? 'Belum ada robot terdaftar'
                : counts.fresh > 0 ? 'Data monitoring tersedia' : 'Menunggu data fresh';
        }
    });

    const selected = root.dataset.rmsSelectedGroup;
    const filter = root.dataset.rmsConnectivityFilter || 'all';
    const shells = [...root.querySelectorAll('[data-rms-robot-shell]')];
    let visible = 0;
    for (const shell of shells) {
        const robot = robots.find(row => Number(row.id) === Number(shell.dataset.rmsRobotId));
        const inGroup = robot && (selected === 'all' || selected === robot.monitor_group_key);
        const matches = inGroup && (filter === 'all' || filter === robot.connectivity);
        shell.hidden = !matches;
        const card = shell.querySelector('.rms-robot-card');
        if (card) card.hidden = false;
        if (matches) visible += 1;
    }
    const count = root.querySelector('[data-rms-visible-robot-count]');
    if (count) count.textContent = String(visible);
    const empty = root.querySelector('[data-rms-line-empty]');
    if (empty) empty.hidden = visible > 0;

    const notice = root.querySelector('[data-rms-line-data-notice]');
    if (!notice) return;
    if (selected) {
        const expected = robots.filter(robot => selected === 'all' || robot.monitor_group_key === selected);
        const ids = new Set(shells.map(shell => Number(shell.dataset.rmsRobotId)));
        notice.hidden = expected.length === ids.size && expected.every(robot => ids.has(Number(robot.id)));
    } else {
        const keys = new Set([...root.querySelectorAll('.rms-line-card')]
            .map(card => card.dataset.rmsLineSummary));
        notice.hidden = robots.every(robot => keys.has(robot.monitor_group_key));
    }
}

export function invalidateRobotLineGroups(documentRoot = document) {
    const root = documentRoot.querySelector('[data-rms-line-monitor]');
    if (!root) return;
    root.querySelectorAll('[data-rms-group-stat="fresh"], [data-rms-group-stat="attention"]')
        .forEach(element => { element.textContent = '—'; });
    root.querySelectorAll('[data-rms-group-status]')
        .forEach(element => { element.textContent = 'Pembaruan monitoring belum tersedia'; });
}
