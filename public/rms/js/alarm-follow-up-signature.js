(() => {
    const initialized = new WeakSet();
    const main = document.getElementById('main');

    function mount() {
        const form = main?.querySelector('[data-fui-signature]');
        if (!form || initialized.has(form)) return;
        initialized.add(form);
        attachPad(form);
    }

    mount();
    if (main) new MutationObserver(mount).observe(main, { childList: true });

    function attachPad(form) {
        const canvas = form.querySelector('canvas');
        const context = canvas.getContext('2d');
        if (!context) return;

        const field = form.elements.namedItem('signature');
        const submit = form.querySelector('button[type="submit"]');
        const message = form.querySelector('[data-signature-message]');
        let pointer = null;
        let strokes = [];
        let points = 0;

        context.strokeStyle = '#19382c';
        context.lineWidth = 2.7;
        context.lineCap = 'round';
        context.lineJoin = 'round';

        function position(event) {
            const box = canvas.getBoundingClientRect();
            const normalize = (value, size) => Math.max(0, Math.min(1000, Math.round(1000 * value / size)));
            return [normalize(event.clientX - box.left, box.width), normalize(event.clientY - box.top, box.height)];
        }

        function draw(first, next) {
            context.beginPath();
            context.moveTo(first[0] * canvas.width / 1000, first[1] * canvas.height / 1000);
            context.lineTo(next[0] * canvas.width / 1000, next[1] * canvas.height / 1000);
            context.stroke();
        }

        canvas.addEventListener('pointerdown', event => {
            if (pointer !== null || event.button !== 0 || strokes.length >= 40 || points > 1498) return;
            pointer = event.pointerId;
            canvas.setPointerCapture(pointer);
            strokes.push([position(event)]);
            points++;
        });
        canvas.addEventListener('pointermove', event => {
            if (event.pointerId !== pointer || points >= 1500) return;
            const stroke = strokes.at(-1);
            const previous = stroke.at(-1);
            const next = position(event);
            if (Math.hypot(next[0] - previous[0], next[1] - previous[1]) < 3) return;
            stroke.push(next);
            points++;
            draw(previous, next);
        });

        for (const name of ['pointerup', 'pointercancel', 'lostpointercapture']) {
            canvas.addEventListener(name, event => {
                if (event.pointerId !== pointer) return;
                if (strokes.at(-1).length < 2) {
                    strokes.pop();
                    points--;
                }
                pointer = null;
                field.value = strokes.length ? JSON.stringify(strokes) : '';
                submit.disabled = strokes.length === 0;
                message.textContent = strokes.length ? 'Tanda tangan siap disimpan.' : 'Bubuhkan tanda tangan dengan mouse atau layar sentuh.';
            });
        }
        form.querySelector('[data-signature-clear]').addEventListener('click', () => {
            pointer = null;
            strokes = [];
            points = 0;
            field.value = '';
            submit.disabled = true;
            context.clearRect(0, 0, canvas.width, canvas.height);
            message.textContent = 'Kotak tanda tangan dikosongkan.';
        });
    }
})();
