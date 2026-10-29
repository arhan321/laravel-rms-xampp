// The snapshot is the authoritative, bounded window, including samples missed
// between WebSocket events. Database IDs stay strings to preserve bigint IDs.
export function syncTelemetryHistory(payload, root = document) {
    const section = root.querySelector('[data-rms-telemetry-history]');
    if (!section || !Array.isArray(payload?.rows)
        || String(payload.robot_id) !== section.dataset.rmsRobotId) return;

    const body = section.querySelector('[data-rms-history-rows]');
    if (!body) return;
    const doc = body.ownerDocument;
    const existing = new Map([...body.querySelectorAll('[data-rms-telemetry-id]')]
        .map(row => [row.dataset.rmsTelemetryId, row]));
    const seen = new Set();
    const rows = [];
    for (const sample of payload.rows) {
        if (sample?.id === null || sample?.id === undefined) continue;
        const id = String(sample.id);
        if (seen.has(id)) continue;
        seen.add(id);
        const row = existing.get(id) ?? doc.createElement('tr');
        row.dataset.rmsTelemetryId = id;
        const cells = [
            sample.time_wib ?? '—', sample.position_ok, sample.torque_ok,
            sample.sequence ?? '—', sample.torque_s_label ?? '—', sample.torque_l_label ?? '—',
        ].map((value, index) => {
            const cell = doc.createElement('td');
            if (index === 1 || index === 2) {
                const badge = doc.createElement('span');
                badge.className = `badge rms-badge ${value ? 'connected' : 'unknown'}`;
                badge.textContent = value ? 'OK' : 'FAILED';
                cell.append(badge);
            } else {
                cell.textContent = String(value);
            }
            return cell;
        });
        row.replaceChildren(...cells);
        rows.push(row);
        if (rows.length === 120) break;
    }
    if (rows.length === 0) {
        const row = doc.createElement('tr');
        const cell = doc.createElement('td');
        cell.colSpan = 6;
        cell.textContent = 'Belum ada data';
        row.append(cell);
        body.replaceChildren(row);
    } else {
        body.replaceChildren(...rows);
    }
    const count = section.querySelector('[data-rms-history-count]');
    if (count) count.textContent = `${rows.length} record`;
}
