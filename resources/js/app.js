import Echo from 'laravel-echo';
import Pusher from 'pusher-js';
import { initRmsLive } from './rms-live.js';
import { initRmsReverbStatus } from './rms-reverb-status.js';

// RMS Blade pages work as before when RMS_LIVE_ENABLED=false.
const root = document.querySelector('.rms-app');
if (root?.dataset.rmsReverbMonitorEnabled === '1') {
    window.Pusher = Pusher;
    // One shared Reverb/WebSocket connection across status + live data updates.
    const echo = initRmsReverbStatus(Echo);
    if (root.dataset.rmsLiveEnabled === '1') {
        initRmsLive(Echo, echo);
    }

    // Alarm Center: reuse the existing Reverb connection and dashboard.js HTML refresh.
    // No new WebSocket, API route, controller command, or database write is introduced.
    if (echo && /^\/rms\/alarms\/?$/.test(window.location.pathname)) {
        let alarmRefreshTimer = null;
        const alarmModels = new Set([
            'RobotAlarmSnapshot', 'RobotAlarmEvent', 'RobotAlarmHistoryEntry',
        ]);

        echo.channel('rms.fleet').listen('.rms.robot.changed', (event) => {
            if (!alarmModels.has(String(event?.kind || ''))) return;
            if (document.hidden) return;

            const region = document.getElementById('rms-dynamic-content');
            if (!region || region.contains(document.activeElement)) return;
            if (region.querySelector('dialog[data-rms-pareto-dialog][open]')) return;

            const chosenRobotIds = [...region.querySelectorAll(
                '#rms-alarm-robot-select input[name="robot_ids[]"]:checked'
            )].map((input) => Number(input.value));
            if (chosenRobotIds.length > 0 &&
                !chosenRobotIds.includes(Number(event?.robot_id))) return;

            if (alarmRefreshTimer !== null) clearTimeout(alarmRefreshTimer);
            alarmRefreshTimer = setTimeout(() => {
                alarmRefreshTimer = null;
                // Existing dashboard.js fetches and updates the whole Alarm Center.
                // Manual refresh is issued only without an active field/modal.
                const current = document.getElementById('rms-dynamic-content');
                if (!current || document.hidden || current.contains(document.activeElement) ||
                    current.querySelector('dialog[data-rms-pareto-dialog][open]')) return;
                document.getElementById('rms-refresh-button')?.click();
            }, 500);
        });
    }
}
