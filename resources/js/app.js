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
}
