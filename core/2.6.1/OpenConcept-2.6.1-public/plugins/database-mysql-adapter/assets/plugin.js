(() => {
    'use strict';
    const id = 'database-mysql-adapter';
    const endpoint = 'plugin-database-mysql-adapter';
    const t = (key, parameters = {}) => window.OpenConceptI18n?.t(id, key, parameters, key) || key;
    const esc = value => String(value ?? '').replace(/[&<>"']/g, char => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[char]));
    let overlay = null;
    let setupDraft = null;
    let relocationDraft = null;
    let migrationPollTimer = null;
    const request = async (action, options = {}) => {
        const response = await fetch(`api.php?action=${encodeURIComponent(endpoint + '-' + action)}`, {
            method: options.method || 'GET',
            headers: { 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-Token': window.OPENCONCEPT_BOOT?.csrf || '' },
            body: options.body ? JSON.stringify(options.body) : undefined,
        });
        const data = await response.json().catch(() => ({}));
        if (!response.ok) throw new Error(data.error || 'Database adapter request failed.');
        return data;
    };
    const controlRequest = async (action, options = {}) => {
        const response = await fetch(`api.php?action=database-migration-${encodeURIComponent(action)}`, {
            method: options.method || 'GET',
            headers: { 'Accept': 'application/json', 'X-CSRF-Token': window.OPENCONCEPT_BOOT?.csrf || '' },
        });
        const data = await response.json().catch(() => ({}));
        if (!response.ok) {
            const error = new Error(data.error || t('ui.databaseError'));
            error.code = data.code || '';
            throw error;
        }
        return data;
    };
    const close = () => { overlay?.remove(); overlay = null; };
    const formatTimestamp = value => {
        if (!value) return '-';
        const date = new Date(value);
        return Number.isNaN(date.getTime()) ? String(value) : date.toLocaleString();
    };
    const operationName = value => {
        const operation = value || 'database_operation';
        const key = `operation.${operation}`;
        const translated = t(key);
        return translated === key ? operation : translated;
    };
    const migrationStageName = stage => {
        const key = `migrationStage.${stage || 'unknown'}`;
        const translated = t(key);
        return translated === key ? t('migrationStage.unknown') : translated;
    };
    const ensureMigrationProgressDialog = () => {
        if (!overlay) return null;
        let dialog = overlay.querySelector('[data-migration-progress-dialog]');
        if (dialog) return dialog;
        overlay.innerHTML = `<section class="dialog database-adapter-panel" data-migration-progress-dialog role="dialog" aria-modal="true" aria-label="${esc(t('ui.migrationControlTitle'))}">
            <header class="dialog-header"><div class="dialog-title">${esc(t('ui.migrationControlTitle'))}</div><button class="btn secondary compact" data-close>${esc(t('ui.close'))}</button></header>
            <div class="dialog-body"><section class="adapter-migration-progress" role="status" aria-live="polite">
                <strong data-migration-stage></strong>
                <span data-migration-elapsed></span>
                <div class="adapter-migration-table-progress" data-migration-table-progress hidden></div>
            </section>
            <button class="btn danger" type="button" data-cancel-migration hidden></button>
            <p data-migration-message></p></div></section>`;
        dialog = overlay.querySelector('[data-migration-progress-dialog]');
        overlay.querySelector('[data-close]').onclick = close;
        overlay.onclick = event => { if (event.target === overlay) close(); };
        const cancel = dialog.querySelector('[data-cancel-migration]');
        cancel.onclick = async () => {
            cancel.disabled = true;
            const message = dialog.querySelector('[data-migration-message]');
            if (message) message.textContent = t('ui.cancelRequesting');
            try {
                const data = await controlRequest('cancel', { method: 'POST' });
                renderMigrationProgress(data.status);
            } catch (error) {
                if (message) message.textContent = error.message;
                cancel.disabled = false;
            }
        };
        return dialog;
    };
    const renderMigrationProgress = status => {
        if (!overlay || !status?.active) return;
        const dialog = ensureMigrationProgressDialog();
        if (!dialog) return;
        const details = status.details && typeof status.details === 'object' ? status.details : {};
        const completed = Number(details.completed_tables ?? details.validated_tables ?? 0);
        const total = Number(details.total_tables || 0);
        const cancelVisible = status.owned_by_session && status.cancellable;
        const stage = dialog.querySelector('[data-migration-stage]');
        const elapsed = dialog.querySelector('[data-migration-elapsed]');
        const progress = dialog.querySelector('[data-migration-table-progress]');
        const cancel = dialog.querySelector('[data-cancel-migration]');
        const message = dialog.querySelector('[data-migration-message]');
        stage.textContent = migrationStageName(status.stage);
        elapsed.textContent = t('ui.elapsed', { seconds: Number(status.elapsed_seconds || 0) });
        progress.hidden = total <= 0;
        progress.textContent = total > 0 ? t('ui.tableProgress', { completed, total }) : '';
        cancel.hidden = !cancelVisible;
        cancel.disabled = Boolean(status.cancel_requested);
        cancel.textContent = status.cancel_requested ? t('ui.cancelRequested') : t('ui.cancelMigration');
        message.textContent = status.cancel_requested ? t('ui.cancelRequestedDetail') : t('ui.migrationControlHelp');
    };
    const stopMigrationMonitor = () => {
        clearInterval(migrationPollTimer);
        migrationPollTimer = null;
    };
    const pollMigrationStatus = async () => {
        const data = await controlRequest('status');
        if (!data.status?.active) {
            stopMigrationMonitor();
            window.dispatchEvent(new CustomEvent('openconcept:database-migration-end'));
            return data.status;
        }
        renderMigrationProgress(data.status);
        return data.status;
    };
    const startMigrationMonitor = () => {
        stopMigrationMonitor();
        migrationPollTimer = setInterval(() => pollMigrationStatus().catch(() => {}), 500);
    };
    const errorPanel = status => {
        const current = status.last_error || (status.last_error_code ? {
            code: status.last_error_code,
            message: t('ui.databaseError'),
            operation: 'database_operation',
            timestamp: status.last_checked_at,
        } : null);
        if (!current) return '';
        const entries = Array.isArray(status.error_log) ? [...status.error_log].reverse() : [];
        const lines = entries.map(entry => [
            formatTimestamp(entry.timestamp),
            operationName(entry.operation),
            entry.code || 'adapter_error',
            entry.message || t('ui.databaseError'),
        ].join(' | '));
        return `<section class="adapter-error-state" role="alert">
            <strong>${esc(t('ui.errorDetected'))}</strong>
            <div>${esc(current.message || t('ui.databaseError'))}</div>
            <dl><div><dt>${esc(t('ui.errorCode'))}</dt><dd>${esc(current.code || status.last_error_code || 'adapter_error')}</dd></div><div><dt>${esc(t('ui.errorOperation'))}</dt><dd>${esc(operationName(current.operation))}</dd></div><div><dt>${esc(t('ui.errorTime'))}</dt><dd>${esc(formatTimestamp(current.timestamp || status.last_checked_at))}</dd></div></dl>
            <details open><summary>${esc(t('ui.errorLog'))}</summary><pre>${esc(lines.join('\n') || t('ui.noErrorLog'))}</pre></details>
        </section>`;
    };
    const render = status => {
        if (!overlay) return;
        const managedEndpoint = status.canonical_backend === 'mysql'
            && !status.manual_backend
            && status.endpoint;
        const value = item => esc(item || '―');
        const retiredAfterPostgreSqlMigration = status.adapter === 'mysql'
            && status.canonical_backend === 'postgresql'
            && status.available === false;
        if (retiredAfterPostgreSqlMigration) {
            overlay.innerHTML = `<section class="dialog database-adapter-panel" role="dialog" aria-modal="true" aria-label="${esc(t('plugin.name'))}">
                <header class="dialog-header"><div class="dialog-title">${esc(t('plugin.name'))}</div><button class="btn secondary compact" data-close>${esc(t('ui.close'))}</button></header>
                <div class="dialog-body"><div class="adapter-status"><strong>${esc(t('ui.status'))}: ${value(status.lifecycle)}</strong><br>${esc(t('ui.canonicalBackend'))}: ${value(status.canonical_backend)}</div>
                <section class="adapter-retired-state" role="alert"><strong>${esc(t('ui.unavailableAfterPostgreSqlMigration'))}</strong></section></div></section>`;
            overlay.querySelector('[data-close]').onclick = close;
            overlay.onclick = event => { if (event.target === overlay) close(); };
            return;
        }
        if (managedEndpoint) {
            const originalHost = String(status.endpoint.host || '');
            const originalPort = Number(status.endpoint.port || 3306);
            const retainedRelocation = relocationDraft || {
                host: originalHost,
                port: String(originalPort),
                username: '',
            };
            const operationalPanel = status.operational
                ? `<section class="adapter-operational-state" role="status"><strong>${esc(t('ui.operatingNormally'))}</strong><span>${esc(t('ui.operatingNormallyDetail'))}</span>${status.last_checked_at ? `<small>${esc(t('ui.lastChecked'))}: ${esc(formatTimestamp(status.last_checked_at))}</small>` : ''}</section>`
                : '';
            overlay.innerHTML = `<section class="dialog database-adapter-panel" role="dialog" aria-modal="true" aria-label="${esc(t('plugin.name'))}">
                <header class="dialog-header"><div class="dialog-title">${esc(t('plugin.name'))}</div><button class="btn secondary compact" data-close>${esc(t('ui.close'))}</button></header>
                <div class="dialog-body">${operationalPanel}${errorPanel(status)}<p>${esc(t('ui.relocationHelp'))}</p><form class="adapter-grid" data-relocation-form>
                    <label>${esc(t('ui.host'))}<input class="input" name="host" required value="${esc(retainedRelocation.host)}" readonly></label>
                    <label>${esc(t('ui.port'))}<input class="input" name="port" type="number" min="1" max="65535" required value="${esc(retainedRelocation.port)}" readonly></label>
                    <div class="wide adapter-relocation-credentials">
                        <label>${esc(t('ui.userId'))}<input class="input" name="username" required autocomplete="username" value="${esc(retainedRelocation.username)}"></label>
                        <label>${esc(t('ui.password'))}<input class="input" name="password" type="password" required autocomplete="current-password"></label>
                    </div>
                    <div class="wide adapter-relocation-actions"><button class="btn primary" type="button" data-change ${status.can_relocate_endpoint ? '' : 'disabled'}>${esc(t('ui.change'))}</button><button class="btn secondary" type="button" data-cancel>${esc(t('ui.cancel'))}</button><button class="btn secondary" type="button" data-health>${esc(t('ui.healthCheck'))}</button></div>
                </form><p data-message></p></div></section>`;
            overlay.querySelector('[data-close]').onclick = close;
            overlay.onclick = event => { if (event.target === overlay) close(); };
            const form = overlay.querySelector('[data-relocation-form]');
            const host = form.elements.host;
            const port = form.elements.port;
            const username = form.elements.username;
            const password = form.elements.password;
            const change = overlay.querySelector('[data-change]');
            const cancel = overlay.querySelector('[data-cancel]');
            const health = overlay.querySelector('[data-health]');
            const message = overlay.querySelector('[data-message]');
            let editing = relocationDraft !== null;
            const retainRelocationInput = () => {
                relocationDraft = {
                    host: host.value,
                    port: port.value,
                    username: username.value,
                };
            };
            const changed = () => host.value.trim() !== originalHost || Number(port.value) !== originalPort;
            const refreshControls = () => {
                host.readOnly = !editing;
                port.readOnly = !editing;
                cancel.disabled = !editing;
                change.disabled = !status.can_relocate_endpoint;
                change.textContent = editing && changed() ? t('ui.confirm') : t('ui.change');
            };
            host.addEventListener('input', () => { retainRelocationInput(); refreshControls(); });
            port.addEventListener('input', () => { retainRelocationInput(); refreshControls(); });
            username.addEventListener('input', retainRelocationInput);
            cancel.onclick = () => {
                editing = false;
                relocationDraft = null;
                host.value = originalHost;
                port.value = String(originalPort);
                username.value = '';
                password.value = '';
                message.textContent = '';
                refreshControls();
            };
            change.onclick = async () => {
                if (!editing) {
                    editing = true;
                    refreshControls();
                    host.focus();
                    return;
                }
                if (!changed()) {
                    message.textContent = t('ui.endpointChangeRequired');
                    return;
                }
                if (!form.reportValidity()) return;
                retainRelocationInput();
                change.disabled = true;
                cancel.disabled = true;
                message.textContent = t('ui.testingEndpoint');
                try {
                    await request('relocate', { method: 'POST', body: {
                        host: host.value.trim(),
                        port: Number(port.value),
                        username: username.value.trim(),
                        password: password.value,
                    } });
                    message.textContent = t('ui.endpointChanged');
                    setTimeout(() => location.reload(), 500);
                } catch (error) {
                    password.value = '';
                    try {
                        render((await request('status')).status);
                    } catch (_) {
                        message.textContent = error.message;
                        change.disabled = false;
                        cancel.disabled = false;
                    }
                }
            };
            health.onclick = async () => {
                health.disabled = true;
                message.textContent = t('ui.checkingHealth');
                try {
                    await request('health', { method: 'POST' });
                    render((await request('status')).status);
                } catch (error) {
                    try { render((await request('status')).status); }
                    catch (_) { message.textContent = error.message; health.disabled = false; }
                }
            };
            refreshControls();
            return;
        }
        const disabled = status.manual_mysql_detected || status.canonical_backend !== 'sqlite';
        if (setupDraft === null) {
            const retainedSetup = status.setup_input && typeof status.setup_input === 'object'
                ? status.setup_input
                : {};
            setupDraft = {
                host: String(retainedSetup.host || '127.0.0.1'),
                port: String(retainedSetup.port || 3306),
                database: String(retainedSetup.database || ''),
                username: '',
            };
        }
        overlay.innerHTML = `<section class="dialog wide database-adapter-panel" role="dialog" aria-modal="true" aria-label="${esc(t('plugin.name'))}">
            <header class="dialog-header"><div class="dialog-title">${esc(t('plugin.name'))}</div><button class="btn secondary compact" data-close>${esc(t('ui.close'))}</button></header>
            <div class="dialog-body">${errorPanel(status)}<p class="adapter-compatibility-note"><strong>${esc(t('ui.mariaDbCompatibility'))}</strong></p><div class="adapter-status"><strong>${esc(t('ui.status'))}: ${value(status.lifecycle)}</strong><br>${esc(t('ui.canonicalBackend'))}: ${value(status.canonical_backend)}<br>${esc(t('ui.connection'))}: ${value(status.connection)}<br>${esc(t('ui.environment'))}: ${value(status.environment)}<br>${esc(t('ui.migration'))}: ${value(status.migration)}<br>${esc(t('ui.automaticFallback'))}: ${esc(t('ui.disabled'))}${status.last_error_code ? `<br>${esc(t('ui.error'))}: ${esc(status.last_error_code)}` : ''}</div>
            ${status.manual_mysql_detected ? `<p>${esc(t('ui.manualMySql'))}</p>` : ''}
            ${status.lifecycle === 'ACTIVE' && status.canonical_backend === 'mysql' ? `<p><button class="btn secondary compact" type="button" data-health>${esc(t('ui.healthCheck'))}</button></p>` : ''}
            ${!disabled ? `<section class="adapter-migration-warning" role="alert"><strong>${esc(t('ui.irreversibleWarning', { source: 'SQLite', destination: 'MySQL' }))}</strong></section>` : ''}
            <form class="adapter-grid"><label>${esc(t('ui.host'))}<input class="input" name="host" required value="${esc(setupDraft.host)}"></label><label>${esc(t('ui.port'))}<input class="input" name="port" type="number" min="1" max="65535" required value="${esc(setupDraft.port)}"></label><label>${esc(t('ui.database'))}<input class="input" name="database" required value="${esc(setupDraft.database)}"></label><label>${esc(t('ui.userId'))}<input class="input" name="username" required autocomplete="username" value="${esc(setupDraft.username)}"></label><label>${esc(t('ui.password'))}<input class="input" name="password" type="password" required autocomplete="new-password"></label><div class="wide"><button class="btn primary" type="submit" ${disabled ? 'disabled' : ''}>${esc(t('ui.runMigration'))}</button></div></form><p data-message></p></div></section>`;
        overlay.querySelector('[data-close]').onclick = close;
        overlay.onclick = event => { if (event.target === overlay) close(); };
        const health = overlay.querySelector('[data-health]');
        if (health) health.onclick = async () => {
            const message = overlay.querySelector('[data-message]');
            health.disabled = true; message.textContent = t('ui.checkingHealth');
            try { await request('health', { method: 'POST' }); message.textContent = t('ui.healthOk'); }
            catch (error) { message.textContent = error.message; }
            finally { health.disabled = false; }
        };
        const setupForm = overlay.querySelector('form');
        const retainSetupInput = form => {
            setupDraft = {
                host: form.elements.host.value,
                port: form.elements.port.value,
                database: form.elements.database.value,
                username: form.elements.username.value,
            };
        };
        ['host', 'port', 'database', 'username'].forEach(name => {
            setupForm.elements[name].addEventListener('input', () => retainSetupInput(setupForm));
        });
        setupForm.onsubmit = async event => {
            event.preventDefault();
            const form = event.currentTarget;
            const message = overlay.querySelector('[data-message]');
            const button = form.querySelector('button[type=submit]');
            if (!form.reportValidity()) return;
            if (!window.confirm(t('ui.irreversibleConfirm', { source: 'SQLite', destination: 'MySQL' }))) return;
            button.disabled = true; message.textContent = t('ui.running');
            retainSetupInput(form);
            const values = Object.fromEntries(new FormData(form));
            values.port = Number(values.port);
            window.dispatchEvent(new CustomEvent('openconcept:database-migration-start'));
            startMigrationMonitor();
            try {
                await request('setup', { method: 'POST', body: values });
                stopMigrationMonitor();
                window.dispatchEvent(new CustomEvent('openconcept:database-migration-end'));
                if (overlay) overlay.innerHTML = `<section class="dialog database-adapter-panel"><div class="dialog-body">${esc(t('ui.completed'))}</div></section>`;
                setTimeout(() => location.reload(), 500);
            }
            catch (error) {
                stopMigrationMonitor();
                window.dispatchEvent(new CustomEvent('openconcept:database-migration-end'));
                values.password = '';
                form.elements.password.value = '';
                try {
                    render((await request('status')).status);
                    const nextMessage = overlay?.querySelector('[data-message]');
                    if (nextMessage) nextMessage.textContent = error.message;
                    const nextButton = overlay?.querySelector('button[type=submit]');
                    if (nextButton) nextButton.disabled = false;
                    const passwordInput = overlay?.querySelector('input[name=password]');
                    if (passwordInput) passwordInput.value = '';
                } catch (_) {
                    if (overlay) overlay.textContent = error.message;
                }
            }
        };
    };
    const open = async () => {
        close(); overlay = document.createElement('div'); overlay.className = 'dialog-backdrop'; document.body.appendChild(overlay);
        overlay.innerHTML = `<section class="dialog database-adapter-panel"><div class="dialog-body">${esc(t('ui.loading'))}</div></section>`;
        try {
            const migration = await controlRequest('status');
            if (migration.status?.active) {
                window.dispatchEvent(new CustomEvent('openconcept:database-migration-start'));
                renderMigrationProgress(migration.status);
                startMigrationMonitor();
                return;
            }
            render((await request('status')).status);
        } catch (error) { overlay.textContent = error.message; }
    };
    window.OpenConceptPlugins?.register?.(id, open);
    window.addEventListener('openconcept:locale-change', () => { if (overlay) open(); });
})();
