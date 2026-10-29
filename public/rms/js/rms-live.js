'use strict';

import { updateRobotCard, updateRobotDetail, expireRobotSnapshot, invalidateInitialMonitor } from './robot-monitor.js';

const AXES = ['S','L','U','R','B','T'];
const STATUS_CLASSES = ['connected','offline','stale','unknown','healthy','warning','critical'];
const COCKPIT_STATUS_LABELS = {
    unavailable: 'UNAVAILABLE',
    not_configured: 'NOT CONFIGURED',
    normal: 'NORMAL',
    warning: 'WARNING',
    critical: 'CRITICAL'
};
const one = (selector, root = document) => root.querySelector(selector);
const all = (selector, root = document) => [...root.querySelectorAll(selector)];
const fmt = (value, digits = 2) => value === null || value === undefined || !Number.isFinite(Number(value))
    ? '—' : Number(value).toLocaleString('id-ID', { minimumFractionDigits: 0, maximumFractionDigits: digits });
const readClass = (element, state) => {
    if (!element) return;
    element.classList.remove(...STATUS_CLASSES);
    element.classList.add(STATUS_CLASSES.includes(state) ? state : 'unknown');
};
function addBadgeText(el, text, state) { if (el) { el.textContent = text; readClass(el, state); } }

function robotFromPath() {
    const m = window.location.pathname.match(/^\/rms\/robots\/(\d+)(?:\/cockpit)?\/?$/);
    return m ? Number(m[1]) : null;
}
function currentPage() {
    if (window.location.pathname.match(/^\/rms\/robots\/\d+\/cockpit\/?$/)) return 'cockpit';
    if (window.location.pathname.match(/^\/rms\/robots\/\d+\/?$/)) return 'detail';
    if (window.location.pathname.match(/^\/rms\/robots\/?$/)) return 'monitor';
    if (window.location.pathname.match(/^\/rms\/?$/)) return 'overview';
    return 'other';
}
function ensureIndicator() {
    const toolbar = one('.top-actions') || one('.topbar');
    if (!toolbar) return null;
    let indicator = one('#rms-live-status');
    if (!indicator) {
        indicator = document.createElement('span');
        indicator.id = 'rms-live-status';
        indicator.className = 'rms-live-indicator';
        indicator.setAttribute('role','status');
        indicator.setAttribute('aria-live','polite');
        toolbar.prepend(indicator);
    }
    return indicator;
}
function badgeValue(r) {
    if (!r) return 'UNKNOWN';
    if (r.is_demo) return `DEMO · ${r.connectivity.toUpperCase()}`;
    return r.connectivity === 'connected' ? 'LIVE DATA' : r.connectivity.toUpperCase();
}
function updateCard(card, robot) { updateRobotCard(card, robot); }

function updateCards(robots, page) {
    for (const card of all('.rms-robot-card')) {
        const robot = robots.find(r => Number(r.id) === Number(card.dataset.rmsRobotId));
        if (!robot) continue;
        updateCard(card, robot);
        if (page === 'monitor') {
            const filter = new URLSearchParams(location.search).get('connectivity') || 'all';
            card.hidden = filter !== 'all' && robot.connectivity !== filter;
        }
    }
}
function updateOverview(payload) {
    const { summary, robots } = payload;
    const notice = one('.rms-status-notice strong');
    if (notice) notice.textContent = `${summary.connected} dari ${summary.total} robot menerima telemetry segar`;
    const holder = one('.rms-status-notice');
    if (holder) {
        const span = all('span',holder)[1];
        if (span) {
            const textNode = [...span.childNodes].find(n => n.nodeType === Node.TEXT_NODE);
            if (textNode) textNode.textContent = ` · ${summary.stale_or_unknown} stale/unknown · ${summary.offline} offline`;
        }
    }
    const statValues = all('.stats .stat-value');
    if (statValues[0]) changeStat(statValues[0], summary.total);
    if (statValues[1]) changeStat(statValues[1], summary.connected);
    if (statValues[2]) changeStat(statValues[2], summary.identified_active_alarms);
    if (statValues[3]) changeStat(statValues[3], summary.open_recommendations);
    updateCards(robots, 'overview');
}
function changeStat(stat, value) {
    const firstText = [...stat.childNodes].find(n => n.nodeType === Node.TEXT_NODE);
    if (firstText) firstText.textContent = `${value} `;
}
function makeAxisRow(axis, value, isPulse) {
    const row = document.createElement('div'); row.className='rms-axis';
    const badge = document.createElement('span'); badge.className='axis-name'; badge.textContent=axis;
    const val = document.createElement('span'); val.className='mono';
    val.textContent = value === null ? '—' : (isPulse ? `${fmt(value,0)} pulses` : fmt(value,4));
    row.append(badge,val); return row;
}
function applyAxisPanel(panel, robot, isPulse) {
    if (!panel) return;
    const good = isPulse ? robot.position_ok : robot.torque_ok;
    addBadgeText(one('.rms-badge',panel), good ? 'READ OK · FRESH' : 'UNAVAILABLE / STALE', good ? 'connected':'unknown');
    let table = one('.rms-axis-table', panel);
    if (!table) {
        table = document.createElement('div'); table.className = 'rms-axis-table';
        one('.rms-empty',panel)?.remove();
        panel.append(table);
    }
    table.replaceChildren(...AXES.map(ax => makeAxisRow(ax,good ? robot.axes[ax]?.[isPulse?'pulse':'torque'] : null,isPulse)));
}
function addHistoryRow(robot, seenHistory) {
    if (!robot?.sample_id || robot.sample_id === seenHistory.id) return seenHistory;
    const table = one('[data-rms-history-rows]');
    if (!table) return {id:robot.sample_id};
    const tr = document.createElement('tr');
    const cells = [robot.last_sampled_wib ?? '—',robot.position_ok ? 'OK':'FAILED',robot.torque_ok?'OK':'FAILED',
        '—',fmt(robot.axes.S?.torque,4),fmt(robot.axes.L?.torque,4)];
    for (const v of cells) { const td=document.createElement('td');td.textContent=v;tr.append(td); }
    table.prepend(tr);
    while (table.children.length > 120) table.lastElementChild.remove();
    return {id:robot.sample_id};
}
function updateDetail(robot) { updateRobotDetail(robot); }

function updateCockpit(robot) {
    const pill = one('.cp-tags .cp-pill:last-child');
    if (pill) {
        pill.textContent = robot.connectivity === 'connected' ? 'TELEMETRY FRESH' : 'TELEMETRY STALE / UNKNOWN';
        pill.classList.toggle('cp-ok', robot.connectivity === 'connected');
        pill.classList.toggle('cp-muted', robot.connectivity !== 'connected');
    }
    const metrics = all('.cp-metrics > div > strong');
    if (metrics[0]) metrics[0].textContent = robot.last_sampled_wib ?? 'Belum tersedia';
    if (metrics[1]) metrics[1].textContent = robot.operating_hours === null ? 'Belum terverifikasi' : `${fmt(robot.operating_hours)} h`;
    all('.cp-axis-item').forEach((row,i)=>{
        const ax=AXES[i]; const value=robot.axes[ax]; if (!value) return;
        const readable=robot.torque_ok;
        const major=one('.cp-axis-measure strong',row);
        const detail=one('.cp-axis-measure small',row);
        const state=one('.cp-state',row);
        if (major) major.textContent=readable?fmt(value.torque,4):'—';
        if (detail) detail.textContent=`Servo Monitor - Torq · Servo Monitor ${robot.position_ok ? fmt(value.pulse,0) : '—'} pulse`;
        if (state) {
            state.className='cp-state cp-'+value.status;
            state.textContent=COCKPIT_STATUS_LABELS[value.status] || 'STATUS TIDAK DIKETAHUI';
        }
    });
    // Cockpit owns graph rendering and UI state; do not recreate its canvas.
    window.dispatchEvent(new CustomEvent('rms:cockpit-sample',{detail:robot}));
}

export function initRmsLive(Echo) {
    const root=one('.rms-app');
    const page=currentPage();
    if (!root || page==='other' || root.dataset.rmsLiveInitialized === '1') return;
    root.dataset.rmsLiveInitialized = '1';
    const endpoint=root.dataset.rmsLiveEndpoint;
    const key=root.dataset.rmsLiveKey;
    const staleSeconds=Number(root.dataset.rmsStaleSeconds || 30);
    const reconcileSeconds=Number(root.dataset.rmsReconcileSeconds || 20);
    const fallbackSeconds=Number(root.dataset.rmsFallbackSeconds || 4);
    const robotId=robotFromPath();
    const indicator=ensureIndicator();
    let connected=false, inflight=false, timer=null, latest=null, lastFetch=0, lastHistory={id:null};
    let notifyTimeout=null;
    let serverOffset=0;
    const status = (txt,kind) => {if(indicator){indicator.textContent=txt;indicator.dataset.state=kind;}};
    const fetchUrl=new URL(endpoint,location.origin);
    if(robotId) fetchUrl.searchParams.set('robot_id',String(robotId));
    async function reconcile() {
        if(inflight || document.hidden) return;
        inflight=true;
        try {
            const controller=new AbortController();
            const timeout=setTimeout(()=>controller.abort(),8000);
            let response;
            try {response=await fetch(fetchUrl,{headers:{Accept:'application/json'},credentials:'same-origin',cache:'no-store',signal:controller.signal});}
            finally {clearTimeout(timeout);}
            if (!response.ok) throw new Error(`HTTP ${response.status}`);
            const data=await response.json();
            serverOffset=Date.parse(data.server_time)-Date.now();
            lastFetch=Date.now();latest=data;
            if(page==='overview') updateOverview(data);
            if(page==='monitor') updateCards(data.robots,'monitor');
            if((page==='detail'||page==='cockpit') && data.robots.length){
                const robot=data.robots[0];
                if(page==='detail') {updateDetail(robot);lastHistory=addHistoryRow(robot,lastHistory);}
                if(page==='cockpit') updateCockpit(robot);
            }
            const demo = data.robots.some(r=>r.is_demo);
            status(connected ? (demo?'LIVE UPDATE ACTIVE · DEMO':'LIVE UPDATE ACTIVE') :
                (demo?'AUTO UPDATE ACTIVE · DEMO':'AUTO UPDATE ACTIVE'),connected?'connected':'fallback');
        } catch(err) {
            console.warn('[RMS Live] Monitoring update failed:', err);
            if (!latest) invalidateInitialMonitor(root);
            status('MONITORING DATA UNAVAILABLE · RETRYING','error');
        } finally {inflight=false;}
    }
    function checkAge() {
        if(!latest) return;
        const now=Date.now()+serverOffset;
        let anyStale=false;
        for(const r of latest.robots) {
            anyStale = expireRobotSnapshot(r, now, staleSeconds) || anyStale;
        }
        if(anyStale){
            latest.summary.connected=latest.robots.filter(r=>r.connectivity==='connected').length;
            latest.summary.stale_or_unknown=latest.robots.filter(r=>['stale','unknown'].includes(r.connectivity)).length;
            if(page==='overview')updateOverview(latest);
            if(page==='monitor')updateCards(latest.robots,page);
            if(page==='detail')updateDetail(latest.robots[0]);
            if(page==='cockpit')updateCockpit(latest.robots[0]);
            if (latest.robots.some(r => r.connectivity === 'stale')) status('TELEMETRY STALE · WAITING FOR DATA','stale');
        }
    }
    reconcile();
    // Lost WebSocket events are corrected by occasional snapshots. No page reload.
    timer=setInterval(()=>{
        checkAge();
        const elapsed=(Date.now()-lastFetch)/1000;
        if(elapsed>=(connected?reconcileSeconds:fallbackSeconds))reconcile();
    },1000);
    document.addEventListener('visibilitychange',()=>{if(!document.hidden)reconcile();});
    window.addEventListener('beforeunload',()=>clearInterval(timer));
    if(!key){status('AUTO UPDATE ACTIVE','fallback');return;}
    try {
        const secure=location.protocol==='https:';
        const port=Number(location.port)||(secure?443:80);
        const echo = new Echo({broadcaster:'reverb',key,cluster:'mt1',wsHost:location.hostname,
            wsPort:port,wssPort:port,forceTLS:secure,enabledTransports:['ws','wss'],disableStats:true});
        const conn=echo.connector.pusher.connection;
        conn.bind('connected',()=>{connected=true;reconcile();});
        conn.bind('disconnected',()=>{connected=false;status('LIVE UPDATE RECONNECTING · AUTO UPDATE ACTIVE','fallback');reconcile();});
        conn.bind('unavailable',()=>{connected=false;status('LIVE UPDATE OFFLINE · AUTO UPDATE ACTIVE','fallback');reconcile();});
        echo.channel('rms.fleet').listen('.rms.robot.changed',event=>{
            if(robotId && Number(event.robot_id)!==robotId) return;
            if(notifyTimeout===null) notifyTimeout=setTimeout(()=>{notifyTimeout=null;reconcile();},250);
        });
    } catch(e) { status('AUTO UPDATE ACTIVE','fallback'); }
}
