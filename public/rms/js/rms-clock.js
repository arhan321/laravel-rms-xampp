'use strict';

/**
 * Render the RMS clock every second without reloading the page.
 * The initial server timestamp keeps the displayed WIB time aligned with
 * Laravel even when the browser computer has a different clock/timezone.
 */
(() => {
    const root = document.querySelector('.rms-app');
    const clock = document.querySelector('#rms-clock');
    if (!root || !clock) return;

    const serverEpoch = Date.parse(root.dataset.rmsServerTime || '');
    const serverOffset = Number.isFinite(serverEpoch) ? serverEpoch - Date.now() : 0;
    const formatter = new Intl.DateTimeFormat('id-ID', {
        timeZone: 'Asia/Jakarta',
        day: '2-digit',
        month: 'short',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit',
        hour12: false,
    });

    const render = () => {
        clock.textContent = `Waktu sekarang: ${formatter.format(new Date(Date.now() + serverOffset))} WIB`;
    };

    render();
    window.setInterval(render, 1000);
})();
