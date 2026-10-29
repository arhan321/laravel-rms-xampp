'use strict';

import { updateRobotCard, updateRobotDetail, expireRobotSnapshot, invalidateInitialMonitor } from './robot-monitor.js';

import { syncTelemetryHistory } from './telemetry-history.js';

const one = (selector, root = document) => root.querySelector(selector);
const all = (selector, root = document) => [...root.querySelectorAll(selector)];
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
function updateDetail(robot) { updateRobotDetail(robot); }

export function initRmsLive(Echo, sharedEcho = undefined) {
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
    let connected=false, inflight=false, pending=false, timer=null, latest=null, lastFetch=0;
    let notifyTimeout=null;
    let serverOffset=0;
    const status = (txt,kind) => {if(indicator){indicator.textContent=txt;indicator.dataset.state=kind;}};
    const fetchUrl=new URL(endpoint,location.origin);
    if(robotId) fetchUrl.searchParams.set('robot_id',String(robotId));
    if(page==='detail') fetchUrl.searchParams.set('include_history','1');
    async function reconcile() {
        if(document.hidden) return;
        if(inflight) { pending=true; return; }
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
            const lastUpdate = one('#rms-last-update');
            if (lastUpdate && data.server_time) {
                const stamp = new Intl.DateTimeFormat('id-ID', {
                    timeZone: 'Asia/Jakarta', day: '2-digit', month: 'short',
                    year: 'numeric', hour: '2-digit', minute: '2-digit',
                    second: '2-digit', hour12: false,
                }).format(new Date(Date.parse(data.server_time)));
                lastUpdate.textContent = `Data diperiksa: ${stamp} WIB`;
            }
            if(page==='overview') updateOverview(data);
            if(page==='monitor') updateCards(data.robots,'monitor');
            if(page==='detail' && data.robots.length){
                const robot=data.robots[0];
                updateDetail(robot);
                syncTelemetryHistory(data.telemetry_history);
            }
            const demo = data.robots.some(r=>r.is_demo);
            status(connected ? (demo?'LIVE UPDATE ACTIVE · DEMO':'LIVE UPDATE ACTIVE') :
                (demo?'AUTO UPDATE ACTIVE · DEMO':'AUTO UPDATE ACTIVE'),connected?'connected':'fallback');
        } catch(err) {
            console.warn('[RMS Live] Monitoring update failed:', err);
            if (!latest) invalidateInitialMonitor(root);
            status('MONITORING DATA UNAVAILABLE · RETRYING','error');
        } finally {
            inflight=false;
            if(pending){pending=false;void reconcile();}
        }
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
        const echo = sharedEcho === undefined ? new Echo({broadcaster:'reverb',key,cluster:'mt1',wsHost:location.hostname,
            wsPort:port,wssPort:port,forceTLS:secure,enabledTransports:['ws','wss'],disableStats:true}) : sharedEcho;
        if (!echo) throw new Error('Reverb is not ready');
        const conn=echo.connector.pusher.connection;
        conn.bind('connected',()=>{connected=true;reconcile();});
        conn.bind('disconnected',()=>{connected=false;status('LIVE UPDATE RECONNECTING · AUTO UPDATE ACTIVE','fallback');reconcile();});
        conn.bind('unavailable',()=>{connected=false;status('LIVE UPDATE OFFLINE · AUTO UPDATE ACTIVE','fallback');reconcile();});
        connected=conn.state==='connected';
        echo.channel('rms.fleet').subscribed(()=>{
            // Subscription can finish after the connection's first snapshot.
            if(page!=='cockpit') reconcile();
        }).listen('.rms.robot.changed',event=>{
            if(robotId && Number(event.robot_id)!==robotId) return;
            if(page==='cockpit') {
                // Cockpit's dedicated endpoint refreshes metrics and graphs in one request.
                window.dispatchEvent(new Event('rms:cockpit-changed'));
                return;
            }
            if(notifyTimeout===null) notifyTimeout=setTimeout(()=>{notifyTimeout=null;reconcile();},250);
        });
    } catch(e) { status('AUTO UPDATE ACTIVE','fallback'); }
}
