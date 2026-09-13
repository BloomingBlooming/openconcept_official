(() => {
    'use strict';
    const boot = JSON.parse(document.getElementById('knowledge-boot').textContent);
    const t = (key, values = {}) => (boot.labels[key] || key).replace(/\{(\w+)\}/g, (all, key) => values[key] ?? all);
    const locale = boot.locale || document.documentElement.lang || 'en-US';
    const format = options => new Intl.DateTimeFormat(locale, { calendar: 'gregory', timeZone: 'UTC', ...options });
    const monthFormat = format({ year: 'numeric', month: 'long' });
    const weekdayFormat = format({ weekday: 'short' });
    const fullFormat = format({ year: 'numeric', month: 'long', day: 'numeric', weekday: 'long' });
    // Use UTC calendar arithmetic, including years 0001–0099; no timestamp or
    // timezone conversion is applied to the existing YYYY-MM-DD API values.
    const date = (year, month, day) => { const value = new Date(0); value.setUTCFullYear(year, month, day); return value; };
    const iso = value => `${String(value.getUTCFullYear()).padStart(4, '0')}-${String(value.getUTCMonth() + 1).padStart(2, '0')}-${String(value.getUTCDate()).padStart(2, '0')}`;
    const parse = value => {
        if (!/^\d{4}-\d{2}-\d{2}$/.test(value)) return null;
        const [year, month, day] = value.split('-').map(Number);
        const result = date(year, month - 1, day);
        return year >= 1 && year <= 9999 && iso(result) === value ? result : null;
    };
    const today = () => { const now = new Date(); return date(now.getFullYear(), now.getMonth(), now.getDate()); };
    const make = (tag, text, className) => {
        const node = document.createElement(tag);
        if (text !== undefined) node.textContent = text;
        if (className) node.className = className;
        return node;
    };
    const button = (text, label, action) => {
        const node = make('button', text); node.type = 'button';
        node.setAttribute('aria-label', label); node.addEventListener('click', action); return node;
    };
    const popup = make('section', undefined, 'knowledge-calendar');
    popup.id = 'knowledge-calendar'; popup.hidden = true;
    popup.setAttribute('role', 'dialog'); popup.setAttribute('aria-modal', 'false');
    const header = make('div', undefined, 'knowledge-calendar-header');
    const heading = make('strong'); heading.id = 'knowledge-calendar-month'; heading.setAttribute('aria-live', 'polite');
    const previous = button('‹', t('previousMonth'), () => shiftMonth(-1));
    const next = button('›', t('nextMonth'), () => shiftMonth(1));
    header.append(previous, heading, next);
    const grid = make('div', undefined, 'knowledge-calendar-grid');
    grid.setAttribute('role', 'grid'); grid.setAttribute('aria-labelledby', heading.id);
    const footer = make('div', undefined, 'knowledge-calendar-footer');
    footer.append(button(t('today'), t('today'), () => choose(today())), button(t('clearDate'), t('clearDate'), () => choose(null)),
        button('×', t('closeCalendar'), () => close()));
    popup.append(header, grid, footer); document.body.append(popup);
    let input = null, trigger = null, focused = today();
    function close(restore = true) {
        popup.hidden = true; trigger?.setAttribute('aria-expanded', 'false');
        if (restore) trigger?.focus();
    }
    function choose(value) {
        input.value = value ? iso(value) : '';
        input.setCustomValidity('');
        input.dispatchEvent(new Event('input', { bubbles: true }));
        input.dispatchEvent(new Event('change', { bubbles: true }));
        close();
    }
    function shiftMonth(delta, focusDay = false) {
        const first = date(focused.getUTCFullYear(), focused.getUTCMonth() + delta, 1);
        if (first.getUTCFullYear() < 1 || first.getUTCFullYear() > 9999) return;
        const lastDay = date(first.getUTCFullYear(), first.getUTCMonth() + 1, 0).getUTCDate();
        focused = date(first.getUTCFullYear(), first.getUTCMonth(), Math.min(focused.getUTCDate(), lastDay));
        render(focusDay);
    }
    function render(focusDay = true) {
        heading.textContent = monthFormat.format(focused);
        previous.disabled = focused.getUTCFullYear() === 1 && focused.getUTCMonth() === 0;
        next.disabled = focused.getUTCFullYear() === 9999 && focused.getUTCMonth() === 11;
        grid.replaceChildren();
        const weekdays = make('div'); weekdays.setAttribute('role', 'row');
        for (let day = 0; day < 7; day++) {
            const label = make('span', weekdayFormat.format(date(2026, 0, 4 + day)));
            label.setAttribute('role', 'columnheader'); weekdays.append(label);
        }
        grid.append(weekdays);
        const first = date(focused.getUTCFullYear(), focused.getUTCMonth(), 1);
        const length = date(first.getUTCFullYear(), first.getUTCMonth() + 1, 0).getUTCDate();
        let row, active;
        for (let cell = 0; cell < Math.ceil((first.getUTCDay() + length) / 7) * 7; cell++) {
            if (cell % 7 === 0) { row = make('div'); row.setAttribute('role', 'row'); grid.append(row); }
            const day = cell - first.getUTCDay() + 1;
            const cellNode = make('span'); cellNode.setAttribute('role', 'gridcell'); row.append(cellNode);
            if (day < 1 || day > length) continue;
            const value = date(first.getUTCFullYear(), first.getUTCMonth(), day);
            const dayButton = button(String(day), fullFormat.format(value), () => choose(value));
            dayButton.dataset.date = iso(value);
            dayButton.tabIndex = day === focused.getUTCDate() ? 0 : -1;
            cellNode.setAttribute('aria-selected', String(iso(value) === input.value));
            if (iso(value) === iso(today())) dayButton.setAttribute('aria-current', 'date');
            if (dayButton.tabIndex === 0) active = dayButton;
            cellNode.append(dayButton);
        }
        if (focusDay) active?.focus();
        position();
    }
    function position() {
        const rect = input.getBoundingClientRect();
        const bounds = popup.getBoundingClientRect();
        popup.style.left = `${Math.max(8, Math.min(rect.left, window.innerWidth - bounds.width - 8))}px`;
        popup.style.top = `${Math.max(8, Math.min(rect.bottom + 6, window.innerHeight - bounds.height - 8))}px`;
    }
    for (const opener of document.querySelectorAll('[data-knowledge-calendar]')) {
        const field = document.getElementById('knowledge-' + opener.dataset.knowledgeCalendar);
        opener.setAttribute('aria-controls', popup.id);
        field.addEventListener('input', () => field.setCustomValidity(!field.value || parse(field.value) ? '' : t('invalidDate')));
        const show = () => {
            if (trigger === opener && !popup.hidden) { close(); return; }
            close(false); input = field; trigger = opener;
            focused = parse(field.value) || today();
            popup.setAttribute('aria-label', t('calendarFor', { field: t(field.name) }));
            popup.hidden = false; trigger.setAttribute('aria-expanded', 'true'); render();
        };
        opener.addEventListener('click', show);
        field.addEventListener('keydown', event => { if (event.key === 'ArrowDown') { event.preventDefault(); show(); } });
    }
    popup.addEventListener('keydown', event => {
        if (event.key === 'Escape') { event.preventDefault(); close(); return; }
        const value = event.target.dataset.date;
        if (!value) return;
        focused = parse(value);
        if (event.key === 'PageUp' || event.key === 'PageDown') {
            event.preventDefault(); shiftMonth((event.key === 'PageUp' ? -1 : 1) * (event.shiftKey ? 12 : 1), true); return;
        }
        const offsets = { ArrowLeft: -1, ArrowRight: 1, ArrowUp: -7, ArrowDown: 7, Home: -focused.getUTCDay(), End: 6 - focused.getUTCDay() };
        if (!(event.key in offsets)) return;
        event.preventDefault();
        const moved = date(focused.getUTCFullYear(), focused.getUTCMonth(), focused.getUTCDate() + offsets[event.key]);
        if (moved.getUTCFullYear() < 1 || moved.getUTCFullYear() > 9999) return;
        focused = moved; render();
    });
    document.addEventListener('pointerdown', event => {
        if (!popup.hidden && !popup.contains(event.target) && !trigger?.contains(event.target)) close(false);
    });
    document.addEventListener('focusin', event => {
        if (!popup.hidden && !popup.contains(event.target) && event.target !== trigger) close(false);
    });
    window.addEventListener('resize', () => { if (!popup.hidden) position(); });
})();
