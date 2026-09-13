(() => {
    'use strict';

    const handlers = new Map();
    const renderers = new Map();
    let host = null;
    let active = null;
    let generation = 0;
    const t = (key, values = {}) => window.OpenConceptI18n?.t('plugin-api', key, values) || key;
    const text = value => value == null ? '' : String(value);
    const element = (tag, className, content) => {
        const node = document.createElement(tag);
        if (className) node.className = className;
        if (content !== undefined) node.textContent = text(content);
        return node;
    };
    const fieldName = value => typeof value === 'string' && /^[a-zA-Z][a-zA-Z0-9_.-]{0,79}$/.test(value)
        && !['constructor', 'prototype', '__proto__'].includes(value);

    function safeUrl(value) {
        try {
            const url = new URL(text(value), location.href);
            if (!['https:', 'http:'].includes(url.protocol) || url.username || url.password) return null;
            return url.href;
        } catch { return null; }
    }

    async function request(action, options = {}) {
        if (!host?.request) throw new Error(t('unavailable'));
        return host.request(action, options);
    }

    function normalizeAction(value) {
        if (!value || typeof value !== 'object') throw new TypeError('An action descriptor is required.');
        const scope = text(value.scope || value.plugin_id || '');
        const action = text(value.action);
        if (!/^(?:core:)?[a-z][a-z0-9-]{0,79}$/.test(scope) || !/^[a-z][a-z0-9_.-]{0,79}$/.test(action)) {
            throw new TypeError('The extension action identity is invalid.');
        }
        return { scope, action, payload: value.payload && typeof value.payload === 'object' ? value.payload : {} };
    }

    async function fetchAction(descriptor, mode = 'view', payload = {}, signal) {
        const action = normalizeAction(descriptor);
        const custom = handlers.get(`${action.scope}/${action.action}`);
        if (custom) {
            const dialog = active, token = generation;
            const isCurrent = () => active === dialog && token === generation && !signal?.aborted;
            return custom(mode, { ...action.payload, ...payload }, {
                signal, isCurrent,
                close() { if (isCurrent()) closeModal(); },
            });
        }
        if (mode === 'view') {
            const query = new URLSearchParams({ scope: action.scope, extension_action: action.action,
                payload: JSON.stringify({ ...action.payload, ...payload }) });
            return request('extension-action', { query: `&${query}`, signal });
        }
        return request('extension-action', { method: 'POST', body: {
            scope: action.scope, extension_action: action.action, mode,
            payload: { ...action.payload, ...payload },
        }, signal });
    }

    function closeModal() {
        const previous = active;
        if (!previous) return;
        active = null;
        generation++;
        previous.abort?.abort();
        for (const cleanup of previous.cleanup) {
            try { cleanup(); } catch { /* A plugin cleanup must not prevent closing. */ }
        }
        previous.backdrop.remove();
        document.body.classList.remove('extension-modal-open');
        if (previous.focus?.isConnected) previous.focus.focus();
    }

    function showError(message, dialog = active) {
        if (!dialog || dialog !== active) return;
        dialog.error.textContent = text(message);
        dialog.error.hidden = !message;
        if (message) showFeedback(null, dialog);
    }

    function showFeedback(feedback, dialog = active) {
        if (!dialog || dialog !== active) return;
        const message = text(feedback?.message);
        dialog.feedback.replaceChildren();
        dialog.feedback.hidden = !message;
        const tone = ['success', 'error', 'warning'].includes(feedback?.tone) ? feedback.tone : 'info';
        dialog.feedback.dataset.tone = tone;
        dialog.feedback.setAttribute('role', tone === 'error' ? 'alert' : 'status');
        dialog.feedback.setAttribute('aria-live', tone === 'error' ? 'assertive' : 'polite');
        if (!message) return;
        if (feedback.busy) {
            const spinner = element('span', 'extension-status-spinner');
            spinner.setAttribute('aria-hidden', 'true');
            dialog.feedback.append(spinner);
        }
        const content = element('div', 'extension-feedback-content');
        if (feedback.title) content.append(element('strong', '', feedback.title));
        content.append(element('p', '', message));
        dialog.feedback.append(content);
    }

    function valuesOf(dialog) {
        const values = {};
        dialog.form.querySelectorAll('[data-extension-field]').forEach(input => {
            if (!fieldName(input.name) || input.disabled) return;
            values[input.name] = input.type === 'checkbox' ? input.checked : input.value;
        });
        return values;
    }

    function setBusy(dialog, busy, trigger, label) {
        dialog.busy = busy;
        dialog.section.setAttribute('aria-busy', String(busy));
        if (busy && trigger) {
            dialog.busyButton = { node: trigger, label: trigger.textContent };
            trigger.textContent = text(label || t('processing'));
        } else if (!busy && dialog.busyButton) {
            dialog.busyButton.node.textContent = dialog.busyButton.label;
            dialog.busyButton = null;
        }
        dialog.footer.querySelectorAll('button').forEach(button => {
            button.disabled = busy || button.dataset.disabled === 'true';
        });
        dialog.body.querySelectorAll('[data-extension-invoke]').forEach(button => {
            button.disabled = busy || button.dataset.disabled === 'true';
        });
    }

    async function invoke(button, extra = {}, trigger) {
        const dialog = active;
        if (!dialog || dialog.busy || button.disabled) return;
        if (button.close === true) { closeModal(); return; }
        if (button.validate !== false && !dialog.form.reportValidity()) return;
        const descriptor = button.action && typeof button.action === 'object'
            ? button.action : { ...dialog.descriptor, action: button.action || dialog.descriptor?.action };
        if (!descriptor?.action) return;
        const token = generation;
        setBusy(dialog, true, trigger, button.busy_label);
        showError('');
        showFeedback({ tone: 'info', message: button.busy_message || t('processing'), busy: true }, dialog);
        try {
            const result = await fetchAction(descriptor, 'invoke', {
                ...dialog.model.payload, ...extra, button: text(button.id), values: valuesOf(dialog),
                version: dialog.model.version ?? null,
            }, dialog.abort.signal);
            if (active !== dialog || token !== generation) return;
            if (result.close === true) closeModal();
            else renderModel(result.modal || result, dialog);
            await host?.changed?.();
        } catch (error) {
            if (active === dialog && token === generation) showError(error.message || t('failed'), dialog);
        } finally {
            if (active === dialog) setBusy(dialog, false);
        }
    }

    function actionButton(spec, extra = {}) {
        if (spec.href) {
            const url = safeUrl(spec.href);
            if (!url) return element('span', 'extension-invalid-link', t('unavailable'));
            const link = element('a', 'btn secondary', spec.label || t('details'));
            link.href = url;
            link.target = '_blank';
            link.rel = 'noopener noreferrer';
            return link;
        }
        const style = ['primary', 'secondary', 'danger'].includes(spec.kind) ? spec.kind : 'secondary';
        const button = element('button', `btn ${style}`, spec.label || spec.id);
        button.type = 'button';
        button.dataset.extensionInvoke = 'true';
        button.dataset.disabled = String(Boolean(spec.disabled));
        button.disabled = Boolean(spec.disabled);
        button.addEventListener('click', () => invoke(spec, extra, button));
        return button;
    }

    function renderBlock(block, dialog, index) {
        if (!block || typeof block !== 'object') return;
        const container = element('section', 'extension-block');
        if (block.title) container.append(element('h3', 'extension-block-title', block.title));
        if (block.type === 'text' || block.type === 'notice') {
            container.append(element('p', `extension-text ${block.tone === 'warning' ? 'extension-warning' : ''}`, block.text ?? block.value));
        } else if (block.type === 'details') {
            const list = element('dl', 'extension-details');
            for (const item of (Array.isArray(block.items) ? block.items : [])) {
                list.append(element('dt', '', item.label));
                const value = element('dd', '', item.value);
                if (item.href && safeUrl(item.href)) {
                    value.textContent = '';
                    const link = actionButton({ href: item.href, label: item.value || t('details') });
                    value.append(link);
                }
                list.append(value);
            }
            container.append(list);
        } else if (block.type === 'table' || block.type === 'list') {
            const rows = Array.isArray(block.rows) ? block.rows : (Array.isArray(block.items) ? block.items : []);
            if (!rows.length) container.append(element('p', 'extension-empty', block.empty_text || t('empty')));
            else {
                const columns = Array.isArray(block.columns) && block.columns.length ? block.columns : [{ key: 'label', label: t('item') }];
                const scroll = element('div', 'extension-table-scroll');
                scroll.tabIndex = 0;
                const table = element('table', 'extension-table');
                const head = element('thead');
                const heading = element('tr');
                for (const column of columns) {
                    const th = element('th', '', column.label || column.key); th.scope = 'col'; heading.append(th);
                }
                const hasActions = rows.some(row => Array.isArray(row.actions) && row.actions.length);
                if (hasActions) heading.append(element('th', '', t('actions')));
                head.append(heading); table.append(head);
                const body = element('tbody');
                for (const row of rows) {
                    const tr = element('tr');
                    for (const column of columns) {
                        const raw = typeof row === 'string' ? row : row[column.key];
                        tr.append(element('td', '', raw && typeof raw === 'object' ? JSON.stringify(raw) : raw));
                    }
                    if (hasActions) {
                        const cell = element('td', 'extension-row-actions');
                        for (const action of (Array.isArray(row.actions) ? row.actions : [])) cell.append(actionButton(action, { row_id: row.id, ...action.payload }));
                        tr.append(cell);
                    }
                    body.append(tr);
                }
                table.append(body); scroll.append(table); container.append(scroll);
            }
            if (block.pagination) {
                const nav = element('nav', 'extension-pagination');
                nav.setAttribute('aria-label', t('pagination'));
                const loadPage = async (cursor, trigger) => {
                    if (dialog.busy) return;
                    const token = generation;
                    setBusy(dialog, true, trigger, t('loading'));
                    showError('');
                    showFeedback({ tone: 'info', message: t('loading'), busy: true }, dialog);
                    try {
                        const result = await fetchAction(dialog.descriptor, 'view', { ...dialog.model.payload, cursor }, dialog.abort.signal);
                        if (dialog === active && token === generation) renderModel(result.modal || result, dialog);
                    } catch (error) { showError(error.message, dialog); }
                    finally { if (dialog === active) setBusy(dialog, false); }
                };
                for (const [key, label] of [['previous_cursor', 'previous'], ['next_cursor', 'next']]) {
                    const button = element('button', 'btn secondary compact', t(label));
                    button.type = 'button';
                    button.disabled = block.pagination[key] == null;
                    button.dataset.disabled = String(button.disabled);
                    button.dataset.extensionInvoke = 'true';
                    button.addEventListener('click', () => loadPage(block.pagination[key], button));
                    nav.append(button);
                }
                container.append(nav);
            }
        } else if (['input', 'select', 'checkbox'].includes(block.type) && fieldName(block.name)) {
            const id = `extension-field-${generation}-${index}`;
            const label = element('label', 'extension-field-label', block.label || block.name);
            label.htmlFor = id;
            let input;
            if (block.type === 'select') {
                input = element('select', 'extension-field');
                for (const choice of (Array.isArray(block.options) ? block.options : [])) {
                    const option = element('option', '', choice.label ?? choice.value);
                    option.value = text(choice.value); input.append(option);
                }
            } else if (block.multiline === true) {
                input = element('textarea', 'extension-field'); input.rows = 5;
            } else {
                input = element('input', 'extension-field');
                input.type = block.type === 'checkbox' ? 'checkbox'
                    : (['text', 'password', 'number', 'email', 'url'].includes(block.input_type) ? block.input_type : 'text');
            }
            input.name = block.name; input.id = id; input.dataset.extensionField = 'true';
            if (input.type === 'checkbox') input.checked = Boolean(block.value);
            else input.value = text(block.value);
            input.required = Boolean(block.required); input.disabled = Boolean(block.disabled);
            input.autocomplete = input.type === 'password' ? 'new-password' : 'off';
            if (block.placeholder) input.placeholder = text(block.placeholder);
            if (Number.isFinite(block.min)) input.min = block.min;
            if (Number.isFinite(block.max)) input.max = block.max;
            if (Number.isInteger(block.max_length)) input.maxLength = block.max_length;
            if (input.type === 'checkbox') { label.prepend(input); container.append(label); }
            else container.append(label, input);
            if (block.help) {
                const help = element('p', 'extension-help', block.help); help.id = `${id}-help`;
                input.setAttribute('aria-describedby', help.id); container.append(help);
            }
        } else if (block.type === 'progress') {
            const progress = element('progress', 'extension-progress');
            progress.max = Math.max(1, Number(block.total) || 100);
            if (Number.isFinite(block.value)) progress.value = Math.max(0, block.value);
            progress.setAttribute('aria-label', text(block.label || t('progress')));
            container.append(progress, element('p', 'extension-help', block.label));
        } else if (block.type === 'custom') {
            const renderer = renderers.get(text(block.renderer));
            if (renderer) {
                const cleanup = renderer(container, block.data, { request, openAction, closeModal });
                if (typeof cleanup === 'function') dialog.cleanup.push(cleanup);
            } else container.append(element('p', '', t('unavailable')));
        }
        dialog.body.append(container);
    }

    function renderModel(model, dialog) {
        if (!model || typeof model !== 'object') throw new TypeError('The modal response is invalid.');
        for (const cleanup of dialog.cleanup.splice(0)) { try { cleanup(); } catch { /* Isolate plugin teardown. */ } }
        dialog.model = model;
        dialog.title.textContent = text(model.title || t('details'));
        dialog.body.replaceChildren(); dialog.footer.replaceChildren();
        showError('');
        showFeedback(model.feedback, dialog);
        if (model.description) dialog.body.append(element('p', 'extension-description', model.description));
        for (const [index, block] of (Array.isArray(model.blocks) ? model.blocks : []).entries()) renderBlock(block, dialog, index);
        const buttons = Array.isArray(model.buttons) ? model.buttons : [];
        for (const button of buttons) dialog.footer.append(actionButton(button));
        if (!buttons.some(button => button.close === true)) {
            const close = element('button', 'btn secondary', t('close'));
            close.type = 'button'; close.addEventListener('click', closeModal); dialog.footer.append(close);
        }
        if (model.error) showError(model.error);
        dialog.section.focus();
    }

    function openModal(model, options = {}) {
        const previousFocus = active?.focus || document.activeElement;
        closeModal();
        generation++;
        const backdrop = element('div', 'extension-modal-backdrop');
        const section = element('section', 'extension-modal');
        section.role = 'dialog'; section.setAttribute('aria-modal', 'true'); section.tabIndex = -1;
        section.setAttribute('aria-labelledby', `extension-title-${generation}`);
        const header = element('header', 'extension-modal-header');
        const title = element('h2', 'extension-modal-title'); title.id = `extension-title-${generation}`;
        const close = element('button', 'extension-modal-close', '×'); close.type = 'button';
        close.setAttribute('aria-label', t('close')); close.addEventListener('click', closeModal);
        header.append(title, close);
        const form = element('form', 'extension-modal-form');
        form.addEventListener('submit', event => event.preventDefault());
        const body = element('div', 'extension-modal-body');
        const error = element('p', 'extension-modal-error'); error.role = 'alert'; error.hidden = true;
        const feedback = element('div', 'extension-modal-feedback'); feedback.hidden = true;
        feedback.setAttribute('aria-atomic', 'true');
        const footer = element('footer', 'extension-modal-footer');
        form.append(body, feedback, error, footer); section.append(header, form); backdrop.append(section);
        const dialog = { backdrop, section, form, title, body, footer, feedback, error, model,
            descriptor: options.action ? normalizeAction(options.action) : null,
            focus: previousFocus, cleanup: [], busy: false, abort: new AbortController() };
        active = dialog;
        backdrop.addEventListener('click', event => { if (event.target === backdrop) closeModal(); });
        backdrop.addEventListener('keydown', event => {
            if (event.key === 'Escape') { event.preventDefault(); closeModal(); return; }
            if (event.key !== 'Tab') return;
            const focusables = [...section.querySelectorAll('a[href], button:not(:disabled), input:not(:disabled), select:not(:disabled), textarea:not(:disabled), [tabindex="0"]')]
                .filter(node => node.getClientRects().length > 0);
            if (!focusables.length) { event.preventDefault(); section.focus(); return; }
            const first = focusables[0], last = focusables.at(-1);
            if (event.shiftKey && (document.activeElement === first || document.activeElement === section)) { event.preventDefault(); last.focus(); }
            else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
        });
        document.body.append(backdrop); document.body.classList.add('extension-modal-open');
        renderModel(model, dialog);
        return { close: closeModal, update: next => { if (active === dialog) renderModel(next, dialog); } };
    }

    async function openAction(value) {
        const descriptor = normalizeAction(value);
        openModal({ title: t('loading'), blocks: [] }, { action: descriptor });
        const dialog = active, token = generation;
        try {
            const result = await fetchAction(descriptor, 'view', {}, dialog.abort.signal);
            if (active === dialog && token === generation) renderModel(result.modal || result, dialog);
        } catch (error) {
            if (active === dialog && token === generation && error.name !== 'AbortError') {
                dialog.title.textContent = t('notice');
                showError(error.message || t('failed'), dialog);
            }
        }
    }

    async function openNotification(id) {
        openModal({ title: t('loading'), blocks: [] });
        const dialog = active, token = generation;
        try {
            const result = await request('extension-notification', {
                query: `&id=${encodeURIComponent(id)}`, signal: dialog.abort.signal,
            });
            if (active !== dialog || token !== generation) return true;
            if (result.modal) { renderModel(result.modal, dialog); return true; }
            if (!result.action) { closeModal(); return false; }
            await openAction(result.action);
            return true;
        } catch (error) {
            if (active === dialog && token === generation && error.name !== 'AbortError') {
                dialog.title.textContent = t('notice');
                showError(error.message || t('failed'), dialog);
            }
            return true;
        }
    }

    window.OpenConceptPluginApi = Object.freeze({
        version: '1.0.0', request, openAction, openModal, closeModal, openNotification,
        configure(services) {
            if (host || typeof services?.request !== 'function') throw new Error('The extension host has already been configured or is invalid.');
            host = services;
        },
        registerAction(pluginId, name, handler) {
            const descriptor = normalizeAction({ plugin_id: pluginId, action: name });
            if (typeof handler !== 'function') throw new TypeError('An action handler is required.');
            handlers.set(`${descriptor.scope}/${descriptor.action}`, handler);
        },
        registerRenderer(pluginId, name, renderer) {
            const descriptor = normalizeAction({ plugin_id: pluginId, action: name });
            if (typeof renderer !== 'function') throw new TypeError('A renderer is required.');
            renderers.set(`${descriptor.scope}/${descriptor.action}`, renderer);
        },
        unregister(pluginId) {
            const prefix = `${pluginId}/`;
            for (const key of handlers.keys()) if (key.startsWith(prefix)) handlers.delete(key);
            for (const key of renderers.keys()) if (key.startsWith(prefix)) renderers.delete(key);
            if (active?.descriptor?.scope === pluginId) closeModal();
        },
    });
})();
