(() => {
    'use strict';
    const root = document.querySelector('.fpl-wrap');
    const dialog = document.getElementById('fpl-dialog');
    if (!root || !dialog || !window.fplAdmin) return;
    const title = document.getElementById('fpl-dialog-title');
    const message = document.getElementById('fpl-dialog-message');
    const activity = dialog.querySelector('.spinner');
    const area = document.getElementById('fpl-progress-area');
    const progress = document.getElementById('fpl-progress');
    const current = document.getElementById('fpl-current');
    const failures = document.getElementById('fpl-failures');
    const close = document.getElementById('fpl-close');
    const retry = document.getElementById('fpl-retry');
    let busy = false, retryAction = null, opener = null, reloadOnClose = false;
    const name = id => fplAdmin.names[id] || id;
    const selection = () => {
        const boxes = [...root.querySelectorAll('[name="fpl-selected"]:not(:disabled)')];
        const selected = boxes.filter(box => box.checked);
        const all = document.getElementById('fpl-select-all');
        if (all) {
            all.disabled = boxes.length === 0 || busy;
            all.checked = boxes.length > 0 && selected.length === boxes.length;
            all.indeterminate = selected.length > 0 && selected.length < boxes.length;
        }
        const update = document.getElementById('fpl-update-selected');
        if (update) update.disabled = selected.length === 0 || busy;
    };
    const setBusy = value => {
        busy = value;
        close.disabled = value;
        activity.hidden = !value;
        dialog.setAttribute('aria-busy', String(value));
        progress.classList.toggle('fpl-progress-active', value);
        selection();
    };
    const open = label => {
        if (!dialog.open) opener = document.activeElement;
        title.textContent = label;
        message.textContent = 'Preparing…';
        area.hidden = true;
        current.textContent = '';
        failures.hidden = true;
        failures.open = false;
        failures.querySelector('ul').replaceChildren();
        retry.hidden = true;
        retryAction = null;
        reloadOnClose = false;
        if (!dialog.open) dialog.showModal();
        setBusy(true);
        title.focus();
    };
    const call = async (operation, values = {}) => {
        const body = new URLSearchParams({ action: 'fpl_action', operation, nonce: fplAdmin.nonce });
        Object.entries(values).forEach(([key, value]) => Array.isArray(value) ? value.forEach(item => body.append(`${key}[]`, item)) : body.set(key, value));
        const response = await fetch(fplAdmin.url, { method: 'POST', credentials: 'same-origin', body });
        const responseText = await response.text();
        let result;
        try { result = JSON.parse(responseText); }
        catch (_) { throw new Error(`WordPress returned an invalid Fusion response (HTTP ${response.status}). Reload this page; if it continues, check the PHP error log.`); }
        if (!response.ok || !result.success) throw new Error(result.data?.message || 'The action could not be completed.');
        return result.data;
    };
    const refresh = async (targetTab = '') => {
        const url = new URL(window.location.href);
        const tab = targetTab || url.searchParams.get('tab') || 'installed';
        const result = await call('view', { tab });
        const page = new DOMParser().parseFromString(result.html, 'text/html');
        const view = page.getElementById('fpl-view');
        if (!view) throw new Error('Could not refresh the Fusion plugin list.');
        document.getElementById('fpl-view').replaceChildren(...view.childNodes);
        if (targetTab) { url.searchParams.set('tab', targetTab); window.history.replaceState(null, '', url); }
        selection();
    };
    const finish = async job => {
        area.hidden = true;
        current.textContent = '';
        const failed = job.items.filter(item => item.status === 'failed');
        const count = job.items.filter(item => item.status === 'success').length;
        title.textContent = failed.length ? 'Finished with errors' : 'Complete';
        message.textContent = `${count} plugin${count === 1 ? '' : 's'} ${job.kind === 'install' ? 'installed' : 'updated'}${failed.length ? `, ${failed.length} failed` : ''}.`;
        failures.querySelector('ul').replaceChildren();
        failed.forEach(item => {
            const li = document.createElement('li');
            li.textContent = `${name(item.id)}: ${item.message || 'Update failed.'}`;
            failures.querySelector('ul').append(li);
        });
        failures.hidden = failed.length === 0;
        try { await refresh(); }
        catch (_) { message.textContent += ' Close to reload the plugin list.'; reloadOnClose = true; }
        setBusy(false);
        close.focus();
    };
    const runBatch = async job => {
        area.hidden = false;
        while (job.status === 'running') {
            const done = job.items.filter(item => ['success', 'failed'].includes(item.status)).length;
            const next = job.items.find(item => ['pending', 'working'].includes(item.status));
            progress.max = Math.max(1, job.items.length);
            progress.value = done;
            message.textContent = `${done} of ${job.items.length} completed`;
            current.textContent = next ? `${job.kind === 'install' ? 'Installing' : 'Updating'} ${name(next.id)}…` : 'Finishing…';
            job = await call('step', { job: job.id });
        }
        progress.value = progress.max;
        await finish(job);
    };
    const execute = async (label, action, recover = action) => {
        open(label);
        try { await action(); }
        catch (error) {
            setBusy(false);
            title.textContent = 'Unable to complete';
            message.textContent = error.message || 'The request failed. Please try again.';
            retryAction = () => execute(label, recover, recover);
            retry.hidden = false;
            retry.focus();
        }
        finally { setBusy(false); }
    };
    root.addEventListener('click', event => {
        const button = event.target.closest('[data-fpl-check], [data-fpl-package], [data-fpl-resume], #fpl-update-selected');
        if (!button || busy) return;
        event.preventDefault();
        if (button.hasAttribute('data-fpl-check')) return void execute('Checking for updates', async () => {
            message.textContent = 'Checking the Fusion mirror…';
            const result = await call('check');
            await refresh('installed');
            title.textContent = 'Check complete';
            message.textContent = result.message;
            setBusy(false);
            dialog.close();
        });
        if (button.hasAttribute('data-fpl-resume')) return void execute('Updating plugins', async () => {
            const result = await call('status');
            if (result.batch?.id !== button.dataset.fplResume) throw new Error('The batch has changed. Reload this page.');
            await runBatch(result.batch);
        });
        const kind = button.dataset.fplKind || 'update';
        const ids = button.dataset.fplPackage ? [button.dataset.fplPackage] : [...root.querySelectorAll('[name="fpl-selected"]:checked:not(:disabled)')].map(box => box.value);
        if (!ids.length) return;
        let jobId = null, previousJobId = null, startAttempted = false;
        const start = async () => {
            message.textContent = 'Preparing plugins…';
            if (!startAttempted) previousJobId = (await call('status')).batch?.id || '';
            startAttempted = true;
            const job = await call('start', { ids, kind, year: fplAdmin.year });
            jobId = job.id;
            await runBatch(job);
        };
        const recover = async () => {
            if (!startAttempted) return await start();
            const { batch } = await call('status');
            const matches = batch?.kind === kind && batch.year === fplAdmin.year && batch.items.length === ids.length && batch.items.every(item => ids.includes(item.id));
            if (batch?.id && (batch.id === jobId || (!jobId && matches && batch.id !== previousJobId))) await runBatch(batch);
            else if (batch?.status === 'running') throw new Error('Another batch is running. Close and use Resume updates.');
            else await start();
        };
        void execute(kind === 'install' ? 'Installing plugins' : 'Updating plugins', start, recover);
    });
    root.addEventListener('change', event => {
        if (event.target.id === 'fpl-select-all') root.querySelectorAll('[name="fpl-selected"]:not(:disabled)').forEach(box => { box.checked = event.target.checked; });
        if (event.target.id === 'fpl-select-all' || event.target.name === 'fpl-selected') selection();
    });
    retry.addEventListener('click', () => { if (!busy && retryAction) void retryAction(); });
    close.addEventListener('click', () => { if (!busy) dialog.close(); });
    dialog.addEventListener('cancel', event => { if (busy) event.preventDefault(); });
    dialog.addEventListener('close', () => {
        if (reloadOnClose) { window.location.reload(); return; }
        if (opener?.isConnected) opener.focus();
        else root.querySelector('[data-fpl-check]')?.focus();
    });
    selection();
})();
