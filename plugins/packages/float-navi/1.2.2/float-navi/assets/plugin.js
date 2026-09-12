(() => {
    'use strict';

    const app = document.getElementById('app');
    if (!app || app.dataset.floatNaviMounted) return;
    app.dataset.floatNaviMounted = 'true';

    const fallbacks = {
        'window.title': 'Float Navi',
        'window.open': 'Open Float Navi',
        'window.close': 'Close Float Navi',
        'window.move': 'Drag or use arrow keys to move the window',
        'window.resize': 'Drag or use arrow keys to resize the window',
        'window.transparency': 'Background transparency',
        'window.menu': 'Float Navi menu',
        'window.closeTransparency': 'Close transparency settings',
        'menu.transparency': 'Transparency',
        'menu.background': 'Background mode',
        'menu.light': 'Light mode',
        'menu.dark': 'Dark mode',
        'menu.back': 'Back',
    };
    const PLUGIN_ID = 'float-navi';
    const t = key => window.OpenConceptI18n?.t(PLUGIN_ID, key, {}, fallbacks[key]) || fallbacks[key];
    const icon = '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><rect x="3" y="6" width="15" height="15" rx="2"/><path d="M10 3h11v11M21 3l-9 9"/></svg>';
    const panel = document.createElement('section');
    panel.id = 'float-navi-window';
    panel.className = 'float-navi-window';
    panel.setAttribute('role', 'dialog');
    panel.setAttribute('aria-modal', 'false');
    panel.setAttribute('aria-labelledby', 'float-navi-title');
    panel.hidden = true;
    panel.dataset.backgroundMode = 'light';
    panel.innerHTML = '<header class="float-navi-header"><button type="button" class="float-navi-move"><span aria-hidden="true">⠿</span><strong id="float-navi-title"></strong></button><button type="button" class="float-navi-settings" aria-haspopup="menu" aria-expanded="false" aria-controls="float-navi-menu"><span aria-hidden="true">⚙</span></button><button type="button" class="float-navi-close">×</button></header><div id="float-navi-menu" class="float-navi-menu" role="menu" hidden></div><div class="float-navi-appearance" hidden><label for="float-navi-transparency"></label><input id="float-navi-transparency" type="range" min="0" max="100" step="1" value="0"><output for="float-navi-transparency">0%</output><button type="button" class="float-navi-appearance-close">×</button></div><div class="float-navi-tools"></div><div class="float-navi-body"><ul class="page-tree float-navi-tree"></ul></div><button type="button" class="float-navi-resize"><span aria-hidden="true">◢</span></button>';
    const move = panel.querySelector('.float-navi-move');
    const close = panel.querySelector('.float-navi-close');
    const resize = panel.querySelector('.float-navi-resize');
    const toolbar = panel.querySelector('.float-navi-tools');
    const body = panel.querySelector('.float-navi-body');
    const tree = panel.querySelector('.float-navi-tree');
    const transparency = panel.querySelector('#float-navi-transparency');
    const transparencyLabel = panel.querySelector('.float-navi-appearance label');
    const transparencyValue = panel.querySelector('.float-navi-appearance output');
    const appearance = panel.querySelector('.float-navi-appearance');
    const appearanceClose = panel.querySelector('.float-navi-appearance-close');
    const settings = panel.querySelector('.float-navi-settings');
    const menu = panel.querySelector('.float-navi-menu');
    let menuView = 'root';
    let sourceSection = null;
    let sourceInput = null;
    let toggle = null;
    let open = false;
    let frame = 0;
    let gesture = null;
    let treeDragging = false;
    let toolSource = '';
    let focusKey = null;
    let pendingFocus = null;
    let geometry = null;

    // The sidebar remains Core's single source of truth. Only the search input
    // needs forwarding; clicks and tree drags bubble through the real #app so
    // Core keeps its existing authorization, menus, save and navigation behavior.
    function queueSync() {
        if (!frame) frame = requestAnimationFrame(() => { frame = 0; sync(); });
    }

    function rememberFocus(element) {
        if (element?.id === 'float-navi-search') return { search: true };
        if (element === transparency) return { transparency: true };
        if (element === settings) return { settings: true };
        const target = element?.closest?.('[data-action]');
        return target ? { action: target.dataset.action, id: target.dataset.id || '' } : null;
    }

    function restoreFocus(key) {
        if (!key) return;
        const target = key.settings ? settings : key.transparency ? transparency : key.search ? toolbar.querySelector('input') : Array.from(panel.querySelectorAll('[data-action]'))
            .find(node => node.dataset.action === key.action && (node.dataset.id || '') === key.id);
        target?.focus({ preventScroll: true });
    }

    function fit() {
        if (!geometry) {
            const sidebarRight = app.querySelector('.sidebar')?.getBoundingClientRect().right || 260;
            geometry = { x: sidebarRight + 16, y: 72, width: 460, height: 600 };
        }
        const maxWidth = Math.max(1, window.innerWidth - 16);
        const maxHeight = Math.max(1, window.innerHeight - 16);
        geometry.width = Math.min(maxWidth, Math.max(Math.min(280, maxWidth), geometry.width));
        geometry.height = Math.min(maxHeight, Math.max(Math.min(220, maxHeight), geometry.height));
        geometry.x = Math.max(8, Math.min(geometry.x, window.innerWidth - geometry.width - 8));
        geometry.y = Math.max(8, Math.min(geometry.y, window.innerHeight - geometry.height - 8));
        Object.assign(panel.style, { left: `${geometry.x}px`, top: `${geometry.y}px`, width: `${geometry.width}px`, height: `${geometry.height}px` });
    }

    function updateLabels() {
        panel.querySelector('#float-navi-title').textContent = t('window.title');
        transparencyLabel.textContent = t('window.transparency');
        for (const [button, key] of [[move, 'window.move'], [close, 'window.close'], [resize, 'window.resize'], [settings, 'window.menu'], [appearanceClose, 'window.closeTransparency']]) {
            button.title = t(key);
            button.setAttribute('aria-label', t(key));
        }
        if (toggle) {
            const label = t(open ? 'window.close' : 'window.open');
            toggle.title = label;
            toggle.setAttribute('aria-label', label);
            toggle.setAttribute('aria-expanded', String(open));
        }
    }

    function setOpen(value) {
        open = value;
        panel.hidden = !open;
        updateLabels();
        if (open) {
            sync();
            fit();
            toolbar.querySelector('input')?.focus({ preventScroll: true });
        } else {
            hideMenu(false);
            appearance.hidden = true;
            gesture = null;
            panel.classList.remove('is-moving');
            focusKey = pendingFocus = null;
            toggle?.focus({ preventScroll: true });
        }
    }

    const sidebarObserver = new MutationObserver(queueSync);

    function hideMenu(restore = true) {
        menu.hidden = true;
        settings.setAttribute('aria-expanded', 'false');
        if (restore) settings.focus({ preventScroll: true });
    }

    function showMenu(view = 'root') {
        appearance.hidden = true;
        menuView = view;
        menu.replaceChildren();
        menu.setAttribute('aria-label', t(view === 'root' ? 'window.menu' : 'menu.background'));
        const addItem = (action, key, role = 'menuitem', checked = null, arrow = '') => {
            const button = document.createElement('button');
            button.type = 'button';
            button.dataset.floatMenuAction = action;
            button.setAttribute('role', role);
            button.tabIndex = -1;
            if (checked !== null) button.setAttribute('aria-checked', String(checked));
            if (arrow) button.setAttribute('aria-haspopup', 'menu');
            const label = document.createElement('span');
            label.textContent = t(key);
            const mark = document.createElement('span');
            mark.setAttribute('aria-hidden', 'true');
            mark.textContent = arrow || (checked ? '✓' : action === 'back' ? '‹' : '');
            button.append(label, mark);
            menu.append(button);
        };
        if (view === 'root') {
            addItem('transparency', 'menu.transparency');
            addItem('background', 'menu.background', 'menuitem', null, '›');
        } else {
            addItem('back', 'menu.back');
            addItem('light', 'menu.light', 'menuitemradio', panel.dataset.backgroundMode === 'light');
            addItem('dark', 'menu.dark', 'menuitemradio', panel.dataset.backgroundMode === 'dark');
        }
        menu.hidden = false;
        settings.setAttribute('aria-expanded', 'true');
        const first = menu.querySelector('button');
        first.tabIndex = 0;
        first.focus({ preventScroll: true });
    }

    settings.addEventListener('click', event => {
        event.stopPropagation();
        menu.hidden ? showMenu() : hideMenu();
    });
    settings.addEventListener('keydown', event => {
        if (event.key === 'ArrowDown') {
            event.preventDefault();
            event.stopPropagation();
            showMenu();
        }
    });
    menu.addEventListener('click', event => {
        event.stopPropagation();
        const action = event.target.closest('[data-float-menu-action]')?.dataset.floatMenuAction;
        if (action === 'back') showMenu();
        else if (action === 'background') showMenu(action);
        else if (action === 'transparency') {
            appearance.hidden = false;
            hideMenu(false);
            transparency.focus({ preventScroll: true });
        } else if (action === 'light' || action === 'dark') {
            panel.dataset.backgroundMode = action;
            hideMenu();
        }
    });
    menu.addEventListener('keydown', event => {
        event.stopPropagation();
        const buttons = Array.from(menu.querySelectorAll('button'));
        const current = buttons.indexOf(document.activeElement);
        let next = current;
        if (event.key === 'ArrowDown') next = (current + 1) % buttons.length;
        else if (event.key === 'ArrowUp') next = (current - 1 + buttons.length) % buttons.length;
        else if (event.key === 'Home') next = 0;
        else if (event.key === 'End') next = buttons.length - 1;
        else if (event.key === 'Escape' || event.key === 'ArrowLeft') {
            event.preventDefault();
            menuView === 'root' ? hideMenu() : showMenu();
        } else if (event.key === 'ArrowRight' && document.activeElement?.hasAttribute('aria-haspopup')) {
            event.preventDefault();
            document.activeElement.click();
        } else if (event.key === 'Tab') hideMenu(false);
        if (next !== current) {
            event.preventDefault();
            buttons.forEach(button => { button.tabIndex = -1; });
            buttons[next].tabIndex = 0;
            buttons[next].focus();
        }
    });
    appearanceClose.addEventListener('click', () => {
        appearance.hidden = true;
        settings.focus({ preventScroll: true });
    });

    function updateTransparency() {
        const value = Math.max(0, Math.min(100, Number(transparency.value) || 0));
        panel.style.setProperty('--float-navi-background-opacity', String(1 - value / 100));
        transparencyValue.value = `${value}%`;
        transparency.setAttribute('aria-valuetext', `${value}%`);
    }
    transparency.addEventListener('input', event => {
        event.stopPropagation();
        updateTransparency();
    });
    // Let the native slider handle arrow/Home/End keys without Core shortcuts.
    transparency.addEventListener('keydown', event => {
        if (event.key !== 'Escape') event.stopPropagation();
    });
    updateTransparency();

    function sync() {
        const sourceTree = app.querySelector('.sidebar .page-tree-main');
        const section = sourceTree?.closest('.sidebar-section');
        if (!section) {
            hideMenu(false);
            appearance.hidden = true;
            sidebarObserver.disconnect();
            sourceSection = sourceInput = toggle = null;
            open = false;
            focusKey = pendingFocus = null;
            tree.replaceChildren();
            toolbar.replaceChildren();
            toolSource = '';
            panel.hidden = true;
            panel.remove();
            return;
        }
        if (sourceSection !== section) {
            sidebarObserver.disconnect();
            sourceSection = section;
            sourceInput = section.querySelector('#localSearchInput');
            toggle = document.createElement('button');
            toggle.type = 'button';
            toggle.className = 'float-navi-toggle';
            toggle.setAttribute('aria-controls', panel.id);
            toggle.innerHTML = icon;
            toggle.addEventListener('click', event => { event.stopPropagation(); setOpen(!open); });
            const expand = section.querySelector('[data-action="expand-all-tree"]');
            expand?.before(toggle);
            sidebarObserver.observe(section, { subtree: true, childList: true, attributes: true, characterData: true });
        }
        const reattached = panel.parentElement !== app;
        if (reattached) app.append(panel);
        // Avoid observing our own accessibility-label updates indefinitely.
        sidebarObserver.disconnect();
        updateLabels();
        sidebarObserver.observe(section, { subtree: true, childList: true, attributes: true, characterData: true });
        if (!open) return;

        const active = document.activeElement;
        const key = pendingFocus || (panel.contains(active) ? rememberFocus(active) : reattached ? focusKey : null);
        const tools = Array.from(section.children).filter(node => node !== sourceTree).map(node => {
            const clone = node.cloneNode(true);
            clone.querySelector('.float-navi-toggle')?.remove();
            clone.querySelector('#localSearchInput')?.setAttribute('id', 'float-navi-search');
            return clone.outerHTML;
        }).join('');
        // Keep the input alive during normal typing, including IME composition.
        if (tools !== toolSource) {
            const input = toolbar.querySelector('input');
            toolbar.innerHTML = tools;
            const replacement = toolbar.querySelector('input');
            if (input && replacement) {
                for (const attr of Array.from(replacement.attributes)) {
                    if (attr.name !== 'value') input.setAttribute(attr.name, attr.value);
                }
                replacement.replaceWith(input);
            }
            toolSource = tools;
        }
        const input = toolbar.querySelector('input');
        if (input && sourceInput && input.value !== sourceInput.value) input.value = sourceInput.value;
        if (!treeDragging && tree.innerHTML !== sourceTree.innerHTML) {
            const scrollTop = body.scrollTop;
            tree.innerHTML = sourceTree.innerHTML;
            body.scrollTop = scrollTop;
        }
        if (key) restoreFocus(key);
        pendingFocus = null;
    }

    toolbar.addEventListener('input', event => {
        if (event.target.id !== 'float-navi-search' || !sourceInput?.isConnected) return;
        event.stopPropagation();
        sourceInput.value = event.target.value;
        sourceInput.dispatchEvent(new Event('input', { bubbles: true }));
        queueSync();
    });
    panel.addEventListener('click', event => {
        const target = event.target.closest('[data-action]');
        if (target?.dataset.action === 'toggle-tree') pendingFocus = rememberFocus(target);
        if (target?.dataset.action === 'clear-local-search') pendingFocus = { search: true };
        // Core handles the event after this listener; mirror its resulting state.
        if (target) queueSync();
    });
    document.addEventListener('focusin', event => {
        focusKey = panel.contains(event.target) ? rememberFocus(event.target) : null;
    });
    document.addEventListener('pointerdown', event => {
        if (!panel.contains(event.target)) pendingFocus = focusKey = null;
    });
    // Dismiss on click, after pointerup, so hiding the row cannot move a tree
    // control out from under the pointer and cancel the user's action.
    document.addEventListener('click', event => {
        if (!menu.hidden && !menu.contains(event.target) && !settings.contains(event.target)) hideMenu(false);
        if (!appearance.hidden && !appearance.contains(event.target)) {
            appearance.hidden = true;
            if (focusKey?.transparency) focusKey = null;
            if (pendingFocus?.transparency) pendingFocus = null;
        }
    }, true);
    close.addEventListener('click', () => setOpen(false));
    panel.addEventListener('keydown', event => {
        if (event.key === 'Escape' && !event.isComposing) {
            if (!menu.hidden || !appearance.hidden) {
                event.preventDefault();
                event.stopPropagation();
                if (!menu.hidden) hideMenu();
                else { appearance.hidden = true; settings.focus({ preventScroll: true }); }
                return;
            }
            if (app.querySelector('.context-menu,.dialog-backdrop,.extension-modal-backdrop')) return;
            event.preventDefault();
            event.stopPropagation();
            setOpen(false);
        }
    });

    for (const [handle, mode] of [[move, 'move'], [resize, 'resize']]) {
        handle.addEventListener('pointerdown', event => {
            if (event.button !== 0) return;
            event.preventDefault();
            handle.focus({ preventScroll: true });
            gesture = { mode, pointerId: event.pointerId, x: event.clientX, y: event.clientY, start: { ...geometry } };
            handle.setPointerCapture(event.pointerId);
            panel.classList.add('is-moving');
        });
        handle.addEventListener('pointermove', event => {
            if (!gesture || gesture.pointerId !== event.pointerId) return;
            const dx = event.clientX - gesture.x;
            const dy = event.clientY - gesture.y;
            geometry = { ...gesture.start };
            if (gesture.mode === 'move') { geometry.x += dx; geometry.y += dy; }
            else { geometry.width += dx; geometry.height += dy; }
            fit();
        });
        const stop = () => { gesture = null; panel.classList.remove('is-moving'); };
        handle.addEventListener('pointerup', stop);
        handle.addEventListener('pointercancel', stop);
        handle.addEventListener('lostpointercapture', stop);
        handle.addEventListener('keydown', event => {
            if (!['ArrowLeft', 'ArrowRight', 'ArrowUp', 'ArrowDown'].includes(event.key)) return;
            event.preventDefault();
            event.stopPropagation();
            const step = event.shiftKey ? 40 : 10;
            const delta = event.key === 'ArrowLeft' || event.key === 'ArrowUp' ? -step : step;
            const horizontal = event.key === 'ArrowLeft' || event.key === 'ArrowRight';
            geometry[mode === 'move' ? horizontal ? 'x' : 'y' : horizontal ? 'width' : 'height'] += delta;
            fit();
        });
    }

    app.addEventListener('dragstart', event => {
        if (event.target.closest('.tree-row[data-tree-page-id]')) treeDragging = true;
    });
    for (const name of ['drop', 'dragend']) {
        app.addEventListener(name, () => { treeDragging = false; queueSync(); });
    }
    window.addEventListener('resize', () => { if (open) fit(); });
    window.addEventListener('openconcept:locale-change', () => { hideMenu(false); queueSync(); });
    // Core replaces #app children on navigation. Reattach the same floating
    // window, retaining its geometry and scroll; do not observe the editor.
    new MutationObserver(queueSync).observe(app, { childList: true });
    sync();
})();
