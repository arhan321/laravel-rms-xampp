import Echo from 'laravel-echo';
import Pusher from 'pusher-js';
import { initRmsLive } from './rms-live.js';

// RMS Blade pages work as before when RMS_LIVE_ENABLED=false.
const root = document.querySelector('.rms-app');
if (root?.dataset.rmsLiveEnabled === '1') {
    window.Pusher = Pusher;
    initRmsLive(Echo);
}
