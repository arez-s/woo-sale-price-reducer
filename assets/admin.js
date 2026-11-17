/* global WSPR */
(() => {
    'use strict';
    const form = document.getElementById('wspr-form');
    if (!form) return;
    const error = document.getElementById('wspr-error');
    const progress = document.getElementById('wspr-progress');
    const bar = document.getElementById('wspr-bar');
    const title = document.getElementById('wspr-progress-title');
    const detail = document.getElementById('wspr-progress-text');
    const pause = document.getElementById('wspr-pause');
    let running = false;
    let paused = false;
    let activeId = 0;
    // Reuse this key on network retries, even after refresh, until start is acknowledged.
    const storageKey = 'wspr-pending-start';
    let requestKey = null;
    const readKey = () => { try { return sessionStorage.getItem(storageKey); } catch (_) { return null; } };
    const storeKey = (key) => { try { if (key) sessionStorage.setItem(storageKey, key); else sessionStorage.removeItem(storageKey); } catch (_) { /* Memory key is enough within this page. */ } };
    const disable = (value) => document.querySelectorAll('#wspr-form button, #wspr-form input, .wspr-command').forEach((node) => { node.disabled = value; });
    const showError = (message) => { error.textContent = message; error.hidden = false; };

    async function api(command, data = {}) {
        const body = new URLSearchParams({action: 'wspr_action', nonce: WSPR.nonce, command, ...data});
        const controller = new AbortController();
        const timer = setTimeout(() => controller.abort(), 60000);
        let response;
        try {
            response = await fetch(WSPR.url, {method: 'POST', body, credentials: 'same-origin', signal: controller.signal});
        } catch (_) {
            throw new Error('ارتباط قطع شد. این درخواست ممکن است انجام شده باشد؛ صفحه را تازه کنید و از تاریخچه «ادامه» را بزنید.');
        } finally {
            clearTimeout(timer);
        }
        const text = await response.text();
        let json;
        try { json = JSON.parse(text); } catch (_) {
            throw new Error('پاسخ سرور قابل خواندن نیست. صفحه را تازه کنید و وضعیت تاریخچه را بررسی کنید.');
        }
        if (!json.success) throw new Error(json.data?.message || 'دسترسی یا اعتبار درخواست منقضی شده؛ صفحه را تازه کنید.');
        return json.data;
    }

    function draw(run) {
        const counts = run.counts || {};
        const restoring = run.status === 'restoring';
        const left = restoring ? (counts.applied || 0) + (counts.prepared || 0) + (counts.restoring || 0) : (counts.pending || 0) + (counts.prepared || 0);
        const total = Number(run.total);
        bar.value = total ? ((total - left) / total) * 100 : 100;
        title.textContent = `نوبت #${run.id} — ${restoring ? 'بازگردانی قیمت‌ها' : 'کاهش قیمت‌ها'}`;
        detail.textContent = `کل نامزدها: ${total} | قیمت جدید: ${counts.applied || 0} | بازگردانده‌شده: ${counts.restored || 0} | بدون تغییر: ${(counts.skipped || 0) + (counts.cancelled || 0)} | نیازمند بررسی: ${(counts.apply_conflict || 0) + (counts.restore_conflict || 0)} | باقی‌مانده: ${left}`;
    }

    async function execute(command, data) {
        if (running) return;
        running = true;
        paused = false;
        error.hidden = true;
        progress.hidden = false;
        pause.disabled = false;
        disable(true);
        try {
            let run = await api(command, data);
            activeId = run.id;
            if (command === 'start') { requestKey = null; storeKey(null); }
            draw(run);
            while (['applying', 'restoring'].includes(run.status) && !paused) {
                run = await api('step', {run_id: run.id});
                draw(run);
            }
            running = false;
            window.location.assign(`${WSPR.page}&run_id=${encodeURIComponent(run.id)}`);
        } catch (exception) {
            showError(exception.message);
            if (activeId) {
                const link = document.createElement('a');
                link.href = `${WSPR.page}&run_id=${encodeURIComponent(activeId)}`;
                link.textContent = ' مشاهدهٔ تاریخچه و ادامهٔ عملیات';
                error.appendChild(link);
            }
        } finally {
            running = false;
            pause.disabled = true;
            disable(false);
        }
    }

    form.addEventListener('submit', (event) => {
        event.preventDefault();
        const percent = document.getElementById('wspr-percent').value.trim();
        const normalized = percent.replace(/[۰-۹٠-٩]/g, (digit) => String('۰۱۲۳۴۵۶۷۸۹'.includes(digit) ? '۰۱۲۳۴۵۶۷۸۹'.indexOf(digit) : '٠١٢٣٤٥٦٧٨٩'.indexOf(digit))).replace('٫', '.');
        if (!/^\d{1,2}(?:\.\d{1,2})?$/.test(normalized) || Number(normalized) <= 0 || Number(normalized) >= 100) {
            showError('درصد باید بیشتر از صفر و کمتر از ۱۰۰ و حداکثر دارای دو رقم اعشار باشد.');
            return;
        }
        requestKey = requestKey || readKey() || (crypto.randomUUID ? crypto.randomUUID() : `wspr-${Date.now()}-${Math.random().toString(36).slice(2)}`);
        storeKey(requestKey);
        execute('start', {percent, request_key: requestKey});
    });
    document.querySelectorAll('.wspr-command').forEach((button) => {
        button.addEventListener('click', () => execute(button.dataset.command, {run_id: button.dataset.run}));
    });
    pause.addEventListener('click', () => { paused = true; pause.disabled = true; });
    window.addEventListener('beforeunload', (event) => {
        if (running && !paused) { event.preventDefault(); event.returnValue = ''; }
    });
})();
