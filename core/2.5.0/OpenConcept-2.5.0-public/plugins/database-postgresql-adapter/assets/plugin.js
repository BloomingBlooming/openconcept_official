(() => {
    'use strict';
    const id = 'database-postgresql-adapter';
    const endpoint = 'plugin-database-postgresql-adapter';
    const t = (key, parameters = {}) => window.OpenConceptI18n?.t(id, key, parameters, key) || key;
    const esc = value => String(value ?? '').replace(/[&<>"']/g, char => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
    }[char]));
    let overlay = null;
    let setupDraft = null;

    const request = async (action, options = {}) => {
        const response = await fetch(`api.php?action=${encodeURIComponent(endpoint + '-' + action)}`, {
            method: options.method || 'GET',
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-Token': window.OPENCONCEPT_BOOT?.csrf || '',
            },
            body: options.body ? JSON.stringify(options.body) : undefined,
        });
        const data = await response.json().catch(() => ({}));
        if (!response.ok) {
            const error = new Error(data.error || 'Database adapter request failed.');
            error.code = data.code || '';
            error.details = data.details && typeof data.details === 'object' ? data.details : {};
            throw error;
        }
        return data;
    };

    const close = () => {
        overlay?.remove();
        overlay = null;
    };

    const errorPanel = status => {
        const current = status.last_error || (status.last_error_code ? {
            code: status.last_error_code,
            message: status.last_error_code,
        } : null);
        if (!current) return '';
        return `<section class="adapter-error-state" role="alert">
            <strong>${esc(t('ui.error'))}: ${esc(current.code || status.last_error_code || 'adapter_error')}</strong>
            ${current.message ? `<div>${esc(current.message)}</div>` : ''}
        </section>`;
    };

    const showInlineError = (message, error) => {
        message.className = 'adapter-inline-error';
        const code = error.code ? `${error.code}: ` : '';
        message.textContent = `${t('ui.error')}: ${code}${error.message}`;
    };

    const pgvectorState = (source = {}, failureCode = '', requiredOverride = null) => {
        const nested = source.pgvector && typeof source.pgvector === 'object' ? source.pgvector : source;
        const installed = nested.installed_optional_extensions && typeof nested.installed_optional_extensions === 'object'
            ? nested.installed_optional_extensions.vector
            : null;
        const available = Array.isArray(nested.available_optional_extensions)
            && nested.available_optional_extensions.includes('vector');
        let state = String(nested.status || nested.pgvector_status || '').toLowerCase();
        state = ({
            installed: 'ready',
            available: 'available_not_enabled',
            missing: 'package_missing',
        })[state] || state;
        if (failureCode === 'pgvector_package_missing') state = 'package_missing';
        if (failureCode === 'pgvector_permission_denied') state = 'permission_denied';
        if (failureCode === 'pgvector_activation_failed') state = 'activation_failed';
        if (!['ready', 'available_not_enabled', 'package_missing', 'permission_denied', 'activation_failed'].includes(state)) {
            state = nested.vector_search === true || installed
                ? 'ready'
                : (available ? 'available_not_enabled' : 'unknown');
        }
        const requiredValue = requiredOverride === null
            ? (nested.required ?? nested.pgvector_required ?? source.pgvector_required)
            : requiredOverride;
        return {
            state,
            version: String(nested.version || nested.pgvector_version || installed || ''),
            required: requiredValue !== false,
        };
    };

    const pgvectorCard = (
        source = {},
        failureCode = '',
        requiredOverride = null,
        canEnable = false,
        cardId = 'destination'
    ) => {
        const readiness = pgvectorState(source, failureCode, requiredOverride);
        let state = readiness.state;
        if (state === 'unknown' && !readiness.required) state = 'database_only';
        const copy = {
            ready: ['ui.pgvectorReady', 'ui.pgvectorReadyHelp', 'ui.ready'],
            available_not_enabled: ['ui.pgvectorAvailable', 'ui.pgvectorAvailableHelp', 'ui.actionRequired'],
            package_missing: ['ui.pgvectorMissing', 'ui.pgvectorMissingHelp', 'ui.notInstalled'],
            permission_denied: ['ui.pgvectorPermission', 'ui.pgvectorPermissionHelp', 'ui.permissionRequired'],
            activation_failed: ['ui.pgvectorActivationFailed', 'ui.pgvectorActivationFailedHelp', 'ui.actionRequired'],
            database_only: ['ui.pgvectorNotRequired', 'ui.pgvectorNotRequiredHelp', 'ui.advanced'],
            unknown: ['ui.pgvectorUnknown', 'ui.pgvectorUnknownHelp', 'ui.notChecked'],
        }[state];
        const showEnable = canEnable && ['available_not_enabled', 'permission_denied', 'activation_failed'].includes(state);
        const version = readiness.version
            ? `<div class="adapter-pgvector-version"><span>${esc(t('ui.pgvectorVersion'))}</span><strong>${esc(readiness.version)}</strong></div>`
            : '';
        const missingSteps = state === 'package_missing' ? `<ol class="adapter-pgvector-steps">
            <li>${esc(t('ui.pgvectorMissingStep1'))}</li>
            <li>${esc(t('ui.pgvectorMissingStep2'))}</li>
            <li>${esc(t('ui.pgvectorMissingStep3'))}</li>
        </ol>` : '';
        const sqlGuidance = ['available_not_enabled', 'permission_denied', 'activation_failed'].includes(state)
            ? `<div class="adapter-pgvector-sql"><span>${esc(t('ui.pgvectorManualAction'))}</span><code>CREATE EXTENSION IF NOT EXISTS vector;</code></div>`
            : '';
        return `<section class="adapter-pgvector-state ${esc(state)}" data-pgvector-card="${esc(cardId)}" data-pgvector-state="${esc(state)}" aria-live="polite">
            <header><div><span class="adapter-pgvector-eyebrow">${esc(t('ui.pgvectorTitle'))}</span><strong>${esc(t(copy[0]))}</strong></div><span class="adapter-pgvector-badge">${esc(t(copy[2]))}</span></header>
            <p>${esc(t(copy[1]))}</p>${version}${missingSteps}${sqlGuidance}
            ${showEnable ? `<button class="btn secondary compact" type="button" data-enable-pgvector>${esc(t('ui.enablePgvector'))}</button>` : ''}
        </section>`;
    };

    const render = status => {
        if (!overlay) return;
        const value = item => esc(item || '—');
        const replacingPostgreSql = status.canonical_backend === 'postgresql';
        const sourceBackend = replacingPostgreSql
            ? 'PostgreSQL'
            : (status.canonical_backend === 'mysql' ? 'MySQL' : 'SQLite');
        const disabled = status.can_configure !== true;
        const sourcePgvectorReady = replacingPostgreSql && pgvectorState(status).state === 'ready';
        const retainedSetup = status.setup_input && typeof status.setup_input === 'object'
            ? status.setup_input
            : {};
        if (setupDraft === null) {
            const currentPgvector = status.pgvector && typeof status.pgvector === 'object' ? status.pgvector : {};
            setupDraft = {
                host: String(retainedSetup.host || '127.0.0.1'),
                port: String(retainedSetup.port || 5432),
                database: String(retainedSetup.database || 'openconcept'),
                username: '',
                sslmode: String(retainedSetup.sslmode || 'prefer'),
                requirePgvector: retainedSetup.require_pgvector
                    ?? currentPgvector.required
                    ?? status.pgvector_required
                    ?? true,
                copyCanonical: retainedSetup.copy_canonical ?? true,
            };
        }

        const currentEndpoint = status.endpoint || status.connection_info || {};
        const sourcePanel = replacingPostgreSql ? `<section class="adapter-current-postgresql">
            <h3>${esc(t('ui.currentPostgresql'))}</h3>
            <p>${esc(t('ui.currentEndpoint', {
                host: currentEndpoint.host || '—',
                port: currentEndpoint.port || '—',
                database: currentEndpoint.database || '—',
            }))}</p>
            ${pgvectorCard(status, '', null, true, 'source')}
            <p><button class="btn secondary compact" type="button" data-health>${esc(t('ui.healthCheck'))}</button></p>
            <p data-health-message></p>
        </section>` : '';
        const rebuild = status.postgresql_replacement?.rag_rebuild;
        const rebuildStatus = rebuild && rebuild.status === 'building'
            ? `<section class="adapter-runtime-requirement" role="status"><strong>${esc(t('ui.ragRebuildQueued', {
                count: rebuild.queued_documents || 0,
            }))}</strong></section>`
            : rebuild && rebuild.status === 'waiting_for_embedding'
            ? `<section class="adapter-runtime-requirement" role="status"><strong>${esc(t('ui.ragEmbeddingWaiting', {
                code: rebuild.reason || 'embedding_unreachable',
            }))}</strong></section>`
            : '';
        const destinationPgvectorSource = replacingPostgreSql ? {} : status;
        const migrationWarning = replacingPostgreSql
            ? t('ui.postgresqlReplacementWarning')
            : t('ui.irreversibleWarning', { source: sourceBackend, destination: 'PostgreSQL' });
        const replacementSettingsStart = replacingPostgreSql ? `<details class="adapter-replacement-settings">
            <summary>${esc(t('ui.replacementSettings'))}</summary>
            <div class="adapter-replacement-settings-body">
                <section class="adapter-migration-warning adapter-replacement-notice" role="alert">
                    <p data-pgvector-copy-warning ${sourcePgvectorReady ? '' : 'hidden'}><strong>${esc(t('ui.pgvectorCopyUnavailable'))}</strong></p>
                    <p><strong>${esc(t('ui.postgresqlReplacementWarning'))}</strong></p>
                </section>` : '';
        const replacementSettingsEnd = replacingPostgreSql ? '</div></details>' : '';

        overlay.innerHTML = `<section class="dialog wide database-adapter-panel" role="dialog" aria-modal="true" aria-label="${esc(t('plugin.name'))}">
            <header class="dialog-header"><div class="dialog-title">${esc(t('plugin.name'))}</div><button class="btn secondary compact" data-close>${esc(t('ui.close'))}</button></header>
            <div class="dialog-body">${errorPanel(status)}
                <p class="adapter-compatibility-note"><strong>${esc(t('ui.connectionTargetHelp'))}</strong></p>
                <section class="adapter-runtime-requirement" role="note"><strong>${esc(t('ui.pdoPgsqlRequirement'))}</strong></section>
                <div class="adapter-status"><strong>${esc(t('ui.status'))}: ${value(status.lifecycle)}</strong><br>${esc(t('ui.canonicalBackend'))}: ${value(status.canonical_backend)}<br>${esc(t('ui.connection'))}: ${value(status.connection)}<br>${esc(t('ui.environment'))}: ${value(status.environment)}<br>${esc(t('ui.migration'))}: ${value(status.migration)}<br>${esc(t('ui.requiredSource'))}: ${esc(sourceBackend)}<br>${esc(t('ui.automaticFallback'))}: ${esc(t('ui.disabled'))}</div>
                ${sourcePanel}${rebuildStatus}
                ${status.lifecycle !== 'ACTIVE' && status.can_configure !== true ? `<p>${esc(t('ui.sourceRequired'))}</p>` : ''}
                ${replacementSettingsStart}
                ${!disabled && !replacingPostgreSql ? `<section class="adapter-migration-warning" role="alert"><strong>${esc(migrationWarning)}</strong></section>` : ''}
                <form class="adapter-grid">
                    ${replacingPostgreSql ? `<section class="adapter-rag-intent wide adapter-copy-choice">
                        <span class="adapter-pgvector-eyebrow">${esc(t('ui.canonicalCopyTitle'))}</span>
                        <label><input type="checkbox" name="copy_canonical" ${setupDraft.copyCanonical ? 'checked' : ''}><span><strong>${esc(t('ui.copyCanonical'))}</strong><small>${esc(t('ui.copyCanonicalHelp'))}</small></span></label>
                        <p data-empty-copy-warning ${setupDraft.copyCanonical ? 'hidden' : ''}>${esc(t('ui.emptyCanonicalWarning'))}</p>
                    </section>` : ''}
                    <section class="adapter-rag-intent wide"><span class="adapter-pgvector-eyebrow">${esc(t('ui.setupGoal'))}</span><label><input type="checkbox" name="require_pgvector" ${setupDraft.requirePgvector ? 'checked' : ''}><span><strong>${esc(t('ui.standardRagReadyDefault'))}</strong><small>${esc(t('ui.standardRagReadyDefaultHelp'))}</small></span></label><details><summary>${esc(t('ui.postgresqlOnlyAdvanced'))}</summary><p>${esc(t('ui.postgresqlOnlyAdvancedHelp'))}</p></details></section>
                    <div class="wide" data-pgvector-slot>${pgvectorCard(destinationPgvectorSource, replacingPostgreSql ? '' : String(status.last_error_code || ''), setupDraft.requirePgvector)}</div>
                    <label>${esc(t('ui.host'))}<input class="input" name="host" required value="${esc(setupDraft.host)}"></label>
                    <label>${esc(t('ui.port'))}<input class="input" name="port" type="number" min="1" max="65535" required value="${esc(setupDraft.port)}"></label>
                    <label>${esc(t('ui.database'))}<input class="input" name="database" required value="${esc(setupDraft.database)}"></label>
                    <label>${esc(t('ui.userId'))}<input class="input" name="username" required autocomplete="username" value="${esc(setupDraft.username)}"></label>
                    <label>${esc(t('ui.password'))}<input class="input" name="password" type="password" required autocomplete="new-password"></label>
                    <label class="wide">${esc(t('ui.tlsMode'))}<select class="select" name="sslmode"><option value="prefer" ${setupDraft.sslmode === 'prefer' ? 'selected' : ''}>${esc(t('ui.prefer'))}</option><option value="require" ${setupDraft.sslmode === 'require' ? 'selected' : ''}>${esc(t('ui.require'))}</option><option value="verify-full" ${setupDraft.sslmode === 'verify-full' ? 'selected' : ''}>${esc(t('ui.verifyFull'))}</option><option value="disable" ${setupDraft.sslmode === 'disable' ? 'selected' : ''}>${esc(t('ui.disable'))}</option></select></label>
                    <div class="wide"><button class="btn primary" type="submit" ${disabled ? 'disabled' : ''} data-submit>${esc(setupDraft.requirePgvector ? t('ui.runStandardRagMigration') : t('ui.runMigration'))}</button></div>
                </form>
                <p data-message></p>
                ${replacementSettingsEnd}
            </div>
        </section>`;

        overlay.querySelector('[data-close]').onclick = close;
        overlay.onclick = event => { if (event.target === overlay) close(); };
        const setupForm = overlay.querySelector('form');
        const retainSetupInput = form => {
            setupDraft = {
                host: form.elements.host.value,
                port: form.elements.port.value,
                database: form.elements.database.value,
                username: form.elements.username.value,
                sslmode: form.elements.sslmode.value,
                requirePgvector: form.elements.require_pgvector.checked,
                copyCanonical: replacingPostgreSql ? form.elements.copy_canonical.checked : true,
            };
        };
        const retainedFields = ['host', 'port', 'database', 'username', 'sslmode', 'require_pgvector'];
        if (replacingPostgreSql) retainedFields.push('copy_canonical');
        retainedFields.forEach(name => {
            setupForm.elements[name].addEventListener('input', () => retainSetupInput(setupForm));
        });

        const copyCanonical = setupForm.elements.copy_canonical;
        if (copyCanonical) {
            copyCanonical.addEventListener('change', () => {
                retainSetupInput(setupForm);
                overlay.querySelector('[data-empty-copy-warning]').hidden = copyCanonical.checked;
            });
        }
        const requirement = setupForm.elements.require_pgvector;
        requirement.addEventListener('change', () => {
            retainSetupInput(setupForm);
            const slot = setupForm.querySelector('[data-pgvector-slot]');
            slot.innerHTML = pgvectorCard(destinationPgvectorSource, '', requirement.checked);
            setupForm.querySelector('[data-submit]').textContent = requirement.checked
                ? t('ui.runStandardRagMigration')
                : t('ui.runMigration');
        });

        const replaceSourcePgvectorCard = (source, failureCode = '') => {
            const current = overlay.querySelector('[data-pgvector-card="source"]');
            if (current) current.outerHTML = pgvectorCard(source, failureCode, null, true, 'source');
            const copyWarning = overlay.querySelector('[data-pgvector-copy-warning]');
            if (copyWarning) {
                copyWarning.hidden = pgvectorState(source, failureCode).state !== 'ready';
            }
            bindEnablePgvector();
        };
        const bindEnablePgvector = () => {
            const enable = overlay.querySelector('[data-pgvector-card="source"] [data-enable-pgvector]');
            const healthMessage = overlay.querySelector('[data-health-message]');
            if (!enable || !healthMessage) return;
            enable.onclick = async () => {
                enable.disabled = true;
                healthMessage.className = '';
                healthMessage.textContent = t('ui.enablingPgvector');
                try {
                    const data = await request('enable-pgvector', { method: 'POST', body: {} });
                    replaceSourcePgvectorCard(data.result?.pgvector || { status: 'ready' });
                    healthMessage.textContent = t('ui.pgvectorEnabled');
                } catch (error) {
                    showInlineError(healthMessage, error);
                    replaceSourcePgvectorCard(error.details || {}, error.code || 'pgvector_activation_failed');
                }
            };
        };
        bindEnablePgvector();
        const health = overlay.querySelector('[data-health]');
        if (health) {
            health.onclick = async () => {
                const healthMessage = overlay.querySelector('[data-health-message]');
                health.disabled = true;
                healthMessage.className = '';
                healthMessage.textContent = t('ui.checkingHealth');
                try {
                    const data = await request('health', { method: 'POST' });
                    const diagnostics = data.result?.diagnostics || {};
                    const pgvector = data.result?.pgvector || diagnostics;
                    replaceSourcePgvectorCard(pgvector);
                    healthMessage.textContent = pgvectorState(pgvector).state === 'ready'
                        ? t('ui.healthStandardRagOk')
                        : t('ui.healthDatabaseOnly');
                } catch (error) {
                    showInlineError(healthMessage, error);
                    if (error.code?.startsWith('pgvector_')) {
                        replaceSourcePgvectorCard(error.details || {}, error.code);
                    }
                } finally {
                    health.disabled = false;
                }
            };
        }

        setupForm.onsubmit = async event => {
            event.preventDefault();
            const form = event.currentTarget;
            const message = overlay.querySelector('[data-message]');
            const button = form.querySelector('button[type=submit]');
            if (!form.reportValidity()) return;
            const requirePgvector = form.elements.require_pgvector.checked;
            const shouldCopyCanonical = replacingPostgreSql ? form.elements.copy_canonical.checked : true;
            let confirmation = replacingPostgreSql
                ? t('ui.postgresqlReplacementConfirm')
                : t('ui.irreversibleConfirm', { source: sourceBackend, destination: 'PostgreSQL' });
            if (replacingPostgreSql) {
                confirmation += '\n\n' + t(shouldCopyCanonical
                    ? 'ui.copyCanonicalConfirm'
                    : 'ui.emptyCanonicalConfirm');
                const sourceCard = overlay.querySelector('[data-pgvector-card="source"]');
                if (sourceCard?.dataset.pgvectorState === 'ready') {
                    confirmation += '\n\n' + t('ui.pgvectorCopyUnavailable');
                }
            }
            confirmation += '\n\n' + t(requirePgvector
                ? 'ui.standardRagReadyConfirm'
                : 'ui.postgresqlOnlyConfirm');
            if (!window.confirm(confirmation)) return;
            button.disabled = true;
            message.className = '';
            message.textContent = t('ui.running');
            retainSetupInput(form);
            const values = Object.fromEntries(new FormData(form));
            values.port = Number(values.port);
            values.require_pgvector = requirePgvector;
            values.copy_canonical = shouldCopyCanonical;
            try {
                const data = await request('setup', { method: 'POST', body: values });
                const ragStatus = data.result?.migration?.rag_rebuild?.status || '';
                message.textContent = ragStatus === 'building'
                    ? t('ui.completedWithRagRebuild')
                    : ragStatus === 'waiting_for_embedding'
                    ? t('ui.completedWaitingEmbedding', { code: data.result?.migration?.rag_rebuild?.reason || 'embedding_unreachable' })
                    : t('ui.completed');
                setTimeout(() => location.reload(), 800);
            } catch (error) {
                showInlineError(message, error);
                if (error.code?.startsWith('pgvector_')) {
                    const slot = form.querySelector('[data-pgvector-slot]');
                    slot.innerHTML = pgvectorCard(error.details || {}, error.code, requirePgvector);
                }
                button.disabled = false;
                form.elements.password.value = '';
            }
        };
    };

    const open = async () => {
        close();
        setupDraft = null;
        overlay = document.createElement('div');
        overlay.className = 'dialog-backdrop';
        document.body.appendChild(overlay);
        overlay.innerHTML = `<section class="dialog database-adapter-panel"><div class="dialog-body">${esc(t('ui.loading'))}</div></section>`;
        try {
            render((await request('status')).status);
        } catch (error) {
            overlay.textContent = error.message;
        }
    };

    window.OpenConceptPlugins?.register?.(id, open);
    window.addEventListener('openconcept:locale-change', () => { if (overlay) open(); });
})();
