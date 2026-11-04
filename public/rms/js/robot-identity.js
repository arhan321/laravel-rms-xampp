'use strict';

// Existing home-position capture (preserved). Read-only data access; never sends a robot command.
document.querySelectorAll('[data-rms-home-form]').forEach(form => {
    const axes = ['S', 'L', 'U', 'R', 'B', 'T'];
    const button = form.querySelector('[data-rms-capture-home]');
    const status = form.querySelector('[data-rms-capture-status]');
    const sampleId = form.querySelector('[data-rms-captured-sample]');
    const confirmation = form.querySelector('[name="home_verified"]');
    if (!button || !status || !sampleId || !confirmation) return;
    const say = (text, state) => { status.textContent = text; status.dataset.state = state; };
    form.querySelectorAll('[data-rms-home-axis]').forEach(input => input.addEventListener('input', () => {
        sampleId.value = '';
        confirmation.checked = false;
        say('Input manual. Periksa kembali acuan pada pendant sebelum menyimpan.', 'manual');
    }));
    button.addEventListener('click', async () => {
        button.disabled = true;
        sampleId.value = '';
        confirmation.checked = false;
        say('Mengambil posisi controller…', 'pending');
        const abort = new AbortController();
        const timeout = setTimeout(() => abort.abort(), 8000);
        try {
            const url = new URL(form.dataset.snapshotUrl, location.origin);
            const response = await fetch(url, {headers: {Accept: 'application/json'}, credentials: 'same-origin', cache: 'no-store', signal: abort.signal});
            if (!response.ok) throw new Error('Posisi belum tersedia. Periksa koneksi dan aktifkan RMS Live.');
            const data = await response.json();
            const robot = data.robots?.find(r => String(r.id) === url.searchParams.get('robot_id'));
            const age = Date.parse(data.server_time) - Date.parse(robot?.last_sampled_at);
            const receiveAge = Date.parse(data.server_time) - Date.parse(robot?.last_received_at);
            if (!robot || robot.is_demo || robot.connectivity !== 'connected' || robot.position_ok !== true || !robot.sample_id
                || !Number.isFinite(age) || age < -5000 || age > 60000
                || !Number.isFinite(receiveAge) || receiveAge < -5000 || receiveAge > (data.stale_seconds ?? 30) * 1000
                || !axes.every(axis => Number.isInteger(robot.axes?.[axis]?.pulse)
                    && robot.axes[axis].pulse >= -2147483648 && robot.axes[axis].pulse <= 2147483647)) {
                throw new Error('Posisi enam axis belum segar/lengkap. Ambil ulang atau isi acuan pendant secara manual.');
            }
            axes.forEach(axis => { form.querySelector(`[data-rms-home-axis="${axis}"]`).value = robot.axes[axis].pulse; });
            sampleId.value = robot.sample_id;
            say(`Posisi diambil: ${robot.last_sampled_wib ?? robot.last_sampled_at}. Pastikan ini titik home yang benar, lalu konfirmasi.`, 'ok');
        } catch (error) {
            say(error.name === 'AbortError' ? 'Permintaan melewati batas waktu. Silakan ambil ulang.' : error.message, 'error');
        } finally {
            clearTimeout(timeout);
            button.disabled = false;
        }
    });
});

// New master-data edit form: warns about controller/network changes before saving.
// The PHP controller enforces all restrictions independently; JS is only a UX aid.
document.querySelectorAll('[data-rms-edit-identity]').forEach(form => {
    const fields = ['ip_address', 'control_group', 'axis_count']
        .map(name => form.querySelector(`[name="${name}"]`)).filter(Boolean);
    const warning = form.querySelector('[data-rms-hardware-warning]');
    const confirmation = form.querySelector('[data-rms-confirm-hardware]');
    const editFields = form.querySelector('[data-rms-edit-fields]');
    const editButtons = form.querySelectorAll('[data-rms-edit-enter]');
    const saveButtons = form.querySelectorAll('[data-rms-edit-save]');
    const cancelButtons = form.querySelectorAll('[data-rms-edit-cancel]');
    const editStatus = form.querySelector('[data-rms-edit-status]');
    const enhanced = Boolean(editFields);
    const allowed = form.dataset.editAllowed === '1';

    function hasHardwareChange() {
        return fields.some(input => String(input.value).trim().toUpperCase() !==
            String(input.dataset.originalValue || '').trim().toUpperCase());
    }
    function updateWarning() {
        if (warning) warning.hidden = (enhanced && form.dataset.editMode !== 'edit') || !hasHardwareChange();
    }
    function setEditing(editing, focus = false, fieldName = '') {
        if (!enhanced) return;
        const active = allowed && editing;
        form.dataset.editMode = active ? 'edit' : 'view';
        editFields.disabled = !active;
        editButtons.forEach(button => { button.hidden = active || !allowed; });
        saveButtons.forEach(button => { button.hidden = !active; });
        cancelButtons.forEach(button => { button.hidden = !active; });
        if (editStatus) editStatus.textContent = active
            ? 'Mode edit aktif. Simpan perubahan atau pilih Batal edit untuk kembali ke data tersimpan.'
            : allowed ? 'Klik Edit data untuk mengubah IP, informasi controller, dan identitas robot.'
                : 'Pengeditan data dinonaktifkan pada environment ini.';
        updateWarning();
        if (active && focus) {
            const target = fieldName ? editFields.elements.namedItem(fieldName) : null;
            const first = target && !target.disabled && !target.readOnly ? target : editFields.querySelector('input:not([type="hidden"]):not([readonly]):not([disabled]), textarea:not([readonly]):not([disabled]), select:not([disabled])');
            first?.focus({preventScroll: true});
        }
    }
    editButtons.forEach(button => button.addEventListener('click', event => {
        if (!enhanced || !allowed) return;
        event.preventDefault();
        setEditing(true, true, button.dataset.rmsEditFocus || '');
    }));
    fields.forEach(input => input.addEventListener('input', () => {
        if (confirmation && !hasHardwareChange()) confirmation.checked = false;
        updateWarning();
    }));
    setEditing(form.dataset.startEditing === '1');
    updateWarning();

    form.addEventListener('submit', event => {
        if (enhanced && form.dataset.editMode !== 'edit') {
            event.preventDefault();
            return;
        }
        if (!hasHardwareChange()) return;
        if (!confirmation?.checked) {
            event.preventDefault();
            if (warning) warning.hidden = false;
            confirmation?.focus();
            alert('Centang konfirmasi perubahan jaringan/control group/jumlah axis sebelum menyimpan.');
        }
    });
});

