const AXIS_LABELS = {normal:'NORMAL', warning:'WARNING', critical:'CRITICAL', unavailable:'UNKNOWN / data tidak tersedia', not_configured:'THRESHOLD BELUM DIATUR'};
const text = (root, selector, value) => {const element=root.querySelector(selector); if(element) element.textContent=String(value);};

export function summarizePlantGroups(robots, details) {
    const rows = new Map();
    const metadata = new Map(details.map(robot => [Number(robot.id),robot]));
    for (const robot of robots) {
        const key = robot.monitor_group_key;
        if (typeof key !== 'string') continue;
        if (!rows.has(key)) rows.set(key,{registered:0,fresh:0,attention:0,maintenance:0});
        const row=rows.get(key);
        row.registered++;
        row.fresh += robot.connectivity==='connected' ? 1 : 0;
        row.attention += ['warning','upnormal'].includes(robot.health) ? 1 : 0;
        row.maintenance += Number(metadata.get(Number(robot.id))?.open_recommendations)>0 ? 1 : 0;
    }
    return rows;
}

/** Reuses the existing fleet request and Echo connection. No additional polling. */
export function syncPlantOverview(payload, documentRoot=document) {
    const root=documentRoot.querySelector('[data-rms-plant-overview]');
    if (!root) return false;
    const board=payload?.plant_overview;
    if (!Array.isArray(board?.groups) || !Array.isArray(payload?.robots)) {
        invalidatePlantOverview(documentRoot);
        return true;
    }
    const details=board.groups.flatMap(group=>group.robots || []);
    const metadata=new Map(details.map(robot=>[Number(robot.id),robot]));
    const robots=new Map(payload.robots.map(robot=>[Number(robot.id),robot]));
    const summaries=summarizePlantGroups(payload.robots,details);
    root.querySelectorAll('[data-rms-plant-group-summary]').forEach(card=>{
        const counts=summaries.get(card.dataset.rmsPlantGroupSummary) || {registered:0,fresh:0,attention:0,maintenance:0};
        card.querySelectorAll('[data-rms-plant-stat]').forEach(element=>{
            element.textContent=String(counts[element.dataset.rmsPlantStat] ?? 0);
        });
    });
    root.querySelectorAll('[data-rms-plant-robot]').forEach(card=>{
        const robot=robots.get(Number(card.dataset.rmsPlantRobot));
        const info=metadata.get(Number(card.dataset.rmsPlantRobot));
        const state=['normal','warning','upnormal'].includes(robot?.health) ? robot.health : 'unknown';
        card.dataset.health=state;
        text(card,'[data-rms-p-health]',state.toUpperCase());
        text(card,'[data-rms-p-connection]',robot ? (robot.is_demo ? `DEMO · ${robot.connectivity.toUpperCase()}` : robot.connectivity.toUpperCase()) : 'UNKNOWN');
        text(card,'[data-rms-p-servo]',robot?.servo?.label || 'SERVO UNKNOWN');
        text(card,'[data-rms-p-hours]',robot?.operating_display?.text || 'Belum tersedia');
        text(card,'[data-rms-p-pm]',info ? `${info.open_recommendations} rekomendasi open` : 'Rekomendasi belum tersedia');
        card.querySelectorAll('[data-rms-plant-axis]').forEach(axis=>{
            const key=axis.dataset.rmsPlantAxis;
            const candidate=robot?.connectivity==='connected' ? robot.axes?.[key]?.status : 'unavailable';
            const status=Object.hasOwn(AXIS_LABELS,candidate) ? candidate : 'unavailable';
            axis.dataset.axisState=status;
            axis.title=`${key}: ${AXIS_LABELS[status]}`;
        });
    });
    const notice=root.querySelector('[data-rms-plant-reload]');
    if(notice) {
        const groups=[...root.querySelectorAll('[data-rms-plant-group]')].map(el=>el.dataset.rmsPlantGroup);
        const expected=board.groups.map(group=>group.key);
        const cards=[...root.querySelectorAll('[data-rms-plant-robot]')];
        notice.hidden=groups.length===expected.length && groups.every((key,index)=>key===expected[index])
            && cards.length===payload.robots.length && cards.every(card=>robots.get(Number(card.dataset.rmsPlantRobot))?.monitor_group_key===card.dataset.groupKey);
    }
    const holder=root.querySelector('[data-rms-plant-alarms]');
    if(holder && Array.isArray(board.latest_alarms)) {
        const doc=holder.ownerDocument;
        const rows=board.latest_alarms.map(alarm=>{
            const link=doc.createElement('a'); link.className='rms-p-alarm-row';
            const target=new URL(alarm.url,location.origin);
            link.href=target.pathname.startsWith('/rms/') ? target.pathname+target.search : '/rms/alarms';
            const code=doc.createElement('strong'); code.textContent=alarm.robot_code;
            const body=doc.createElement('span'); body.textContent=alarm.name;
            const when=doc.createElement('small'); when.textContent=`${alarm.time} · ${String(alarm.status).toUpperCase()}`;
            body.append(when); link.append(code,body); return link;
        });
        if(rows.length===0) {const empty=doc.createElement('p');empty.className='rms-p-empty';empty.textContent='Belum ada event alarm tersimpan.';rows.push(empty);}
        holder.replaceChildren(...rows);
    }
    return true;
}

export function invalidatePlantOverview(documentRoot=document) {
    const root=documentRoot.querySelector('[data-rms-plant-overview]');
    if(!root) return;
    root.querySelectorAll('[data-rms-plant-stat="fresh"], [data-rms-plant-stat="attention"]')
        .forEach(element=>{element.textContent='—';});
    root.querySelectorAll('[data-rms-plant-robot]').forEach(card=>{
        card.dataset.health='unknown';text(card,'[data-rms-p-health]','UNKNOWN');
        text(card,'[data-rms-p-connection]','DATA UNAVAILABLE');text(card,'[data-rms-p-servo]','SERVO UNKNOWN');
        card.querySelectorAll('[data-rms-plant-axis]').forEach(axis=>{axis.dataset.axisState='unavailable';axis.title='Pembaruan belum tersedia';});
    });
}
