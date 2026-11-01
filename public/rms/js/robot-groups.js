(() => {
    'use strict';
    const form = document.querySelector('[data-rms-group-form]');
    if (!form) return;
    const rows = [...form.querySelectorAll('[data-rms-member-row]')];
    const count = form.querySelector('[data-rms-member-count]');
    const updateCount = () => {
        if (count) count.textContent = String(form.querySelectorAll('input[name="robot_ids[]"]:checked').length);
    };
    form.addEventListener('change', updateCount);
    form.querySelector('[data-rms-member-search]')?.addEventListener('input', event => {
        const query = event.target.value.trim().toLocaleLowerCase();
        for (const row of rows) row.hidden = !row.textContent.toLocaleLowerCase().includes(query);
        const empty = form.querySelector('[data-rms-member-empty]');
        if (empty) empty.hidden = rows.some(row => !row.hidden);
    });
    updateCount();
})();
