(() => {
    'use strict';
    const root = document.querySelector('.fpl-wrap');
    if (!root || !window.fplAdmin) return;
    const feedback = document.getElementById('fpl-feedback');
    let busy = false;
    const name = id => fplAdmin.names[id] || id;
    const show = (message, error = false) => {
        feedback.textContent = message;
        feedback.classList.toggle('fpl-error', error);
        feedback.hidden = !message;
    };
    const call = async (operation, values = {}) => {
        const body = new URLSearchParams({ action: 'fpl_action', operation, nonce: fplAdmin.nonce });
        Object.entries(values).forEach(([key, value]) => Array.isArray(value) ? value.forEach(item => body.append(`${key}[]`, item)) : body.set(key, value));
        const response = await fetch(fplAdmin.url, { method: 'POST', credentials: 'same-origin', body });
        let result;
        try { result = await response.json(); }
        catch (_) { throw new Error('WordPress returned an invalid response. Reload and try again.'); }
        if (!response.ok || !result.success) throw new Error(result.data?.message || 'The action could not be completed.');
        return result.data;
    };
    const selection = () => {
        const boxes = [...root.querySelectorAll('[name="fpl-selected"]:not(:disabled)')];
        const selected = boxes.filter(box => box.checked);
        const all = document.getElementById('fpl-select-all');
        if (all) {
            all.disabled = boxes.length === 0;
            all.checked = boxes.length > 0 && selected.length === boxes.length;
            all.indeterminate = selected.length > 0 && selected.length < boxes.length;
        }
        const update = document.getElementById('fpl-update-selected');
        if (update) update.disabled = selected.length === 0 || busy;
    };
    const runBatch = async job => {
        while (job.status === 'running') {
            const done = job.items.filter(item => ['success', 'failed'].includes(item.status)).length;
            const next = job.items.find(item => ['pending', 'working'].includes(item.status));
            show(`${done} of ${job.items.length} completed${next ? ` · ${job.kind === 'install' ? 'Installing' : 'Updating'} ${name(next.id)}…` : ''}`);
            job = await call('step', { job: job.id });
        }
        const failed = job.items.filter(item => item.status === 'failed');
        if (failed.length) {
            show(`Finished with ${failed.length} error${failed.length === 1 ? '' : 's'}: ${failed.map(item => `${name(item.id)} — ${item.message}`).join('; ')}`, true);
            busy = false;
            selection();
            return;
        }
        show(`${job.items.length} plugin${job.items.length === 1 ? '' : 's'} ${job.kind === 'install' ? 'installed' : 'updated'}. Reloading…`);
        window.location.reload();
    };
    const execute = async action => {
        if (busy) return;
        busy = true;
        selection();
        try { await action(); }
        catch (error) { show(error.message || 'The action failed.', true); }
        finally { busy = false; selection(); }
    };
    root.addEventListener('click', event => {
        const button = event.target.closest('[data-fpl-check], [data-fpl-package], [data-fpl-resume], #fpl-update-selected');
        if (!button) return;
        event.preventDefault();
        if (button.hasAttribute('data-fpl-check')) return void execute(async () => {
            show('Checking the Fusion mirror…');
            const result = await call('check');
            show(result.message + ' Reloading…');
            window.location.reload();
        });
        if (button.hasAttribute('data-fpl-resume')) return void execute(async () => {
            const result = await call('status');
            if (result.batch?.id !== button.dataset.fplResume) throw new Error('The batch has changed. Reload this page.');
            await runBatch(result.batch);
        });
        const kind = button.dataset.fplKind || 'update';
        const ids = button.dataset.fplPackage ? [button.dataset.fplPackage] : [...root.querySelectorAll('[name="fpl-selected"]:checked:not(:disabled)')].map(box => box.value);
        if (!ids.length) return;
        void execute(async () => {
            show('Preparing plugins…');
            const job = await call('start', { ids, kind, year: fplAdmin.year });
            await runBatch(job);
        });
    });
    root.addEventListener('change', event => {
        if (event.target.id === 'fpl-select-all') root.querySelectorAll('[name="fpl-selected"]:not(:disabled)').forEach(box => { box.checked = event.target.checked; });
        if (event.target.id === 'fpl-select-all' || event.target.name === 'fpl-selected') selection();
    });
    show('');
    selection();
})();
