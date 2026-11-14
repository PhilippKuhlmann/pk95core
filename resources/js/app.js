import { registerProxyMap } from './proxy-map';

/*
 * PK95 Core UI behaviour. Alpine comes with the standard Livewire bundle, so everything
 * here is registered on 'alpine:init' (this module runs before Livewire starts Alpine).
 */

// ---- theme toggle (same behaviour as DokuVault) ---------------------------------
// The theme itself is set by window.pk95ApplyTheme (layouts/partials/theme.blade.php).
function initThemeToggle() {
    const d = document.getElementById('theme-toggle-dark-icon');
    const l = document.getElementById('theme-toggle-light-icon');
    const btn = document.getElementById('theme-toggle');
    if (!btn || !d || !l || btn.dataset.ready) return;
    btn.dataset.ready = '1';
    const showIcon = () => {
        const isDark = document.documentElement.classList.contains('dark');
        d.classList.toggle('hidden', isDark);
        l.classList.toggle('hidden', !isDark);
    };
    showIcon();
    btn.addEventListener('click', () => {
        const isDark = document.documentElement.classList.toggle('dark');
        try { localStorage.setItem('color-theme', isDark ? 'dark' : 'light'); } catch (e) { /* ignore */ }
        showIcon();
    });
}
document.addEventListener('DOMContentLoaded', initThemeToggle);

// wire:navigate copies the <html> attributes of the new page, which drops "dark": apply the
// theme again right when the page is swapped (no light flash), and wire up the new toggle.
document.addEventListener('livewire:navigating', (e) => {
    e.detail?.onSwap?.(() => window.pk95ApplyTheme?.());
});
document.addEventListener('livewire:navigated', () => {
    window.pk95ApplyTheme?.();
    initThemeToggle();
});

async function copyToClipboard(value) {
    try {
        await navigator.clipboard.writeText(value);
    } catch (e) {
        const ta = document.createElement('textarea');
        ta.value = value;
        ta.setAttribute('readonly', '');
        ta.style.position = 'fixed';
        ta.style.opacity = '0';
        document.body.appendChild(ta);
        ta.select();
        document.execCommand('copy');
        ta.remove();
    }
}

// While Core restarts (self update) the poll requests fail with 502 from a reverse proxy:
// no error dialog for those, the page reloads itself once Core is back.
document.addEventListener('livewire:init', () => {
    window.Livewire.interceptRequest(({ onError }) => {
        onError(({ preventDefault }) => {
            if (window.pk95Restarting) preventDefault();
        });
    });
});

// ---- tooltips: data-tip="…" on any element, one layer for the whole page ---------------
// Replaces the native title tooltip (which only shows after about a second). Shows right
// away on hover, on keyboard focus and on tap; Esc, leaving or a tap elsewhere hides it.
// A newline in data-tip starts a new line, the first line of several is the heading.
// Bars in SVG charts (and elements with data-tip-follow) follow the mouse, everything else
// sits above the element; it flips below at the top edge and stays inside the viewport.
// The tip is only visual: the element keeps its accessible name (aria-label, visible or
// sr-only text) and is described by the layer while it is shown.
const tooltip = (() => {
    const GAP = 8;
    let layer = null, target = null, point = null, follow = false;
    let showTimer = null, hideTimer = null, described = null;

    const ensureLayer = () => {
        if (layer?.isConnected) return layer;
        layer = document.createElement('div');
        layer.id = 'pk95-tip';
        layer.setAttribute('role', 'tooltip');
        layer.hidden = true;
        layer.className = 'pointer-events-none fixed left-0 top-0 z-[70] max-w-[min(20rem,calc(100vw-16px))] rounded-lg border border-gray-200 bg-white px-2.5 py-1.5 text-xs leading-snug text-gray-700 shadow-lg break-words dark:border-gray-600 dark:bg-gray-800 dark:text-gray-200';
        document.body.appendChild(layer);
        return layer;
    };

    const fill = (text) => {
        const lines = text.split('\n').map((l) => l.trim()).filter((l) => l !== '');
        layer.replaceChildren(...lines.map((l, i) => {
            const div = document.createElement('div');
            div.textContent = l;
            if (i === 0 && lines.length > 1) div.className = 'mb-0.5 font-semibold text-gray-900 dark:text-white';
            return div;
        }));
    };

    const place = () => {
        if (!target || layer.hidden) return;
        const w = layer.offsetWidth, h = layer.offsetHeight;
        const vw = document.documentElement.clientWidth, vh = window.innerHeight;
        let x, above, below;
        if (follow && point) {
            x = point.x; above = point.y - 14; below = point.y + 18;
        } else {
            const r = target.getBoundingClientRect();
            x = r.left + r.width / 2; above = r.top - 6; below = r.bottom + 6;
        }
        let top = above - h;
        if (top < GAP) top = below;
        if (top + h > vh - GAP) top = Math.max(GAP, vh - GAP - h);
        const left = Math.min(Math.max(GAP, x - w / 2), Math.max(GAP, vw - GAP - w));
        layer.style.transform = `translate(${Math.round(left)}px, ${Math.round(top)}px)`;
    };

    const undescribe = () => {
        if (!described) return;
        const [el, before] = described;
        if (before === null) el.removeAttribute('aria-describedby'); else el.setAttribute('aria-describedby', before);
        described = null;
    };

    const show = (el, e = null) => {
        clearTimeout(showTimer); clearTimeout(hideTimer);
        const text = el.dataset.tip?.trim();
        if (!text) return hide();
        ensureLayer();
        if (target !== el) undescribe();
        target = el;
        follow = el instanceof SVGElement || el.hasAttribute('data-tip-follow');
        point = e && 'clientX' in e && (e.clientX || e.clientY) ? { x: e.clientX, y: e.clientY } : null;
        fill(text);
        layer.hidden = false;
        place();
        if (!described && el.getAttribute('aria-label') !== text) {
            described = [el, el.getAttribute('aria-describedby')];
            el.setAttribute('aria-describedby', [described[1], 'pk95-tip'].filter(Boolean).join(' '));
        }
    };

    function hide() {
        clearTimeout(showTimer); clearTimeout(hideTimer);
        undescribe();
        target = null;
        if (layer) layer.hidden = true;
    }

    // first tip after a moment (no flicker when the mouse just passes), then without delay
    const showSoon = (el, e) => {
        clearTimeout(hideTimer);
        if (el === target) return;
        clearTimeout(showTimer);
        if (layer && !layer.hidden) return show(el, e);
        showTimer = setTimeout(() => show(el, e), 60);
    };
    const hideSoon = () => {
        clearTimeout(showTimer);
        clearTimeout(hideTimer);
        hideTimer = setTimeout(hide, 80);
    };

    const tipOf = (node) => (node instanceof Element ? node.closest('[data-tip]') : null);

    document.addEventListener('pointerover', (e) => {
        if (e.pointerType === 'touch') return;
        const el = tipOf(e.target);
        if (el) showSoon(el, e);
        else { clearTimeout(showTimer); if (target) hideSoon(); }
    });
    document.addEventListener('pointermove', (e) => {
        if (e.pointerType === 'touch' || !target || !follow) return;
        point = { x: e.clientX, y: e.clientY };
        place();
    }, { passive: true });
    document.documentElement.addEventListener('pointerleave', () => hideSoon());
    // touch: tapping an element with a tip shows it, tapping anywhere else hides it
    document.addEventListener('pointerdown', (e) => {
        if (e.pointerType !== 'touch') return;
        const el = tipOf(e.target);
        if (el) show(el, e); else hide();
    }, { passive: true });
    document.addEventListener('focusin', (e) => {
        const el = tipOf(e.target);
        if (el && e.target.matches(':focus-visible')) show(el); else if (target && !el) hide();
    });
    document.addEventListener('focusout', (e) => {
        if (target && target.contains(e.target)) hide();
    });
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && target) hide();
    });
    window.addEventListener('scroll', () => { if (target && !follow) place(); else if (target) hide(); }, { capture: true, passive: true });
    window.addEventListener('resize', () => hide());
    document.addEventListener('livewire:navigating', () => hide());
    // a Livewire re-render (polling) may change or remove the element under the tip
    document.addEventListener('livewire:init', () => {
        window.Livewire.hook('morphed', () => {
            if (!target) return;
            if (!target.isConnected) return hide();
            const text = target.dataset.tip?.trim();
            if (!text) return hide();
            fill(text);
            place();
        });
    });

    return { show, hide };
})();
window.pk95Tooltip = tooltip;

document.addEventListener('alpine:init', () => {
    registerProxyMap(window.Alpine);
    const Alpine = window.Alpine;

    // ---- page layout: desktop collapse + mobile drawer -----------------------------
    Alpine.data('seitenlayout', () => ({
        menuEin: (() => { try { return localStorage.getItem('menu-eingeklappt') === '1'; } catch (e) { return false; } })(),
        mobilOffen: false,
        menuUmschalten() {
            this.menuEin = !this.menuEin;
            try { localStorage.setItem('menu-eingeklappt', this.menuEin ? '1' : '0'); } catch (e) { /* ignore */ }
        },
        mobilUmschalten(state) {
            this.mobilOffen = state === undefined ? !this.mobilOffen : state;
            document.body.classList.toggle('overflow-hidden', this.mobilOffen);
        },
    }));

    // ---- copy to clipboard: $kopieren(text) sets `copied` on the surrounding x-data ----
    Alpine.magic('kopieren', (el) => async (value) => {
        await copyToClipboard(String(value ?? ''));
        const data = Alpine.$data(el);
        if (data && 'copied' in data) {
            data.copied = true;
            setTimeout(() => { data.copied = false; }, 1500);
        }
    });

    // ---- focus helper used by dialogs ---------------------------------------------------
    Alpine.magic('fokus', () => (target) => setTimeout(() => target?.focus(), 30));

    // ---- confirm dialog (DokuVault "Löschdialog", one instance in the layout) -----------
    // $store.confirm.ask({ title, text, button, tone: 'red'|'blue', ok: () => $wire.delete(1) })
    // or on a classic form: <form data-confirm="Text" data-confirm-title="…" data-confirm-button="…">
    Alpine.store('confirm', {
        open: false, title: '', text: '', button: 'Löschen', tone: 'red', onOk: null, from: null,
        ask(opts) {
            this.title = opts.title || 'Wirklich fortfahren?';
            this.text = opts.text || '';
            this.button = opts.button || 'Bestätigen';
            this.tone = opts.tone || 'red';
            this.onOk = opts.ok || null;
            this.from = document.activeElement;
            this.open = true;
            setTimeout(() => document.getElementById('confirm-cancel')?.focus(), 30);
        },
        ok() {
            const fn = this.onOk;
            this.open = false;
            this.onOk = null;
            this.from = null;
            if (fn) fn();
        },
        // back to where the question came from (a button, or a field of an open form dialog)
        cancel() {
            const from = this.from;
            this.open = false;
            this.onOk = null;
            this.from = null;
            if (from?.isConnected) setTimeout(() => from.focus(), 0);
        },
    });

    // ---- modal: form dialog of a Livewire page (x-modal) ---------------------------------------
    // The page decides whether it is open (renders it or not). Here: focus into the first field,
    // keep Tab inside, back to the opening button afterwards; Esc, the backdrop and Abbrechen ask
    // before unsaved changes are thrown away; the page behind does not scroll.
    let modalsOpen = 0;
    Alpine.data('modal', (cfg) => ({
        dirty: false,
        shown: false,
        returnTo: null,
        returnSel: null,
        init() {
            const a = document.activeElement;
            if (a && a !== document.body && !this.$root.contains(a)) {
                this.returnTo = a;
                // the button may be replaced by a re-render: find it again by its action
                const click = a.getAttribute('wire:click');
                this.returnSel = click ? `[wire\\:click="${click.replace(/["\\]/g, '\\$&')}"]` : null;
            }
            if (modalsOpen++ === 0) document.documentElement.classList.add('overflow-hidden');
            this.$nextTick(() => {
                this.shown = true;
                this.focusFirst();
            });
        },
        destroy() {
            modalsOpen = Math.max(0, modalsOpen - 1);
            if (modalsOpen === 0) document.documentElement.classList.remove('overflow-hidden');
            const [back, sel] = [this.returnTo, this.returnSel];
            setTimeout(() => {
                const el = back?.isConnected ? back : (sel ? document.querySelector(sel) : null);
                el?.focus();
            }, 30);
        },
        focusables() {
            return [...this.$refs.panel.querySelectorAll('a[href], button:not([disabled]), input:not([disabled]):not([type=hidden]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])')]
                .filter((el) => el.getClientRects().length > 0);
        },
        focusFirst() {
            const fields = [...this.$refs.body.querySelectorAll('input:not([disabled]):not([type=hidden]), select:not([disabled]), textarea:not([disabled]), button[role=combobox]')]
                .filter((el) => el.getClientRects().length > 0);
            (fields[0] ?? this.focusables()[0])?.focus();
        },
        trap(e) {
            const list = this.focusables();
            if (!list.length) return;
            const [first, last] = [list[0], list[list.length - 1]];
            if (!this.$refs.panel.contains(document.activeElement)) {
                e.preventDefault();
                first.focus();
            } else if (e.shiftKey && document.activeElement === first) {
                e.preventDefault();
                last.focus();
            } else if (!e.shiftKey && document.activeElement === last) {
                e.preventDefault();
                first.focus();
            }
        },
        requestClose() {
            const confirm = Alpine.store('confirm');
            if (confirm.open) return;
            if (!this.dirty) {
                this.$wire.call(cfg.close);
                return;
            }
            confirm.ask({
                title: 'Änderungen verwerfen?', text: 'Was du in diesem Formular geändert hast, wird nicht gespeichert.',
                button: 'Verwerfen', ok: () => this.$wire.call(cfg.close),
            });
        },
    }));
    document.addEventListener('submit', (ev) => {
        const form = ev.target;
        if (form.dataset && form.dataset.confirm !== undefined && form.dataset.confirmed !== '1') {
            ev.preventDefault();
            Alpine.store('confirm').ask({
                title: form.dataset.confirmTitle,
                text: form.dataset.confirm,
                button: form.dataset.confirmButton,
                tone: form.dataset.confirmTone,
                ok: () => { form.dataset.confirmed = '1'; form.requestSubmit(); },
            });
        }
    }, true);

    // ---- toasts: session flash on load + Livewire `notice` events ------------------------
    // success hides after 4 s, errors stay until closed.
    Alpine.data('toast', (kind, initial) => ({
        kind,
        show: false,
        text: '',
        timer: null,
        init() {
            if (initial) this.zeigen(initial);
        },
        empfangen(detail) {
            const d = Array.isArray(detail) ? detail[0] : detail;
            if (!d || (d.kind || 'success') !== this.kind) return;
            this.zeigen(d.text);
        },
        zeigen(text) {
            this.text = text;
            this.show = true;
            clearTimeout(this.timer);
            if (this.kind === 'success') this.timer = setTimeout(() => { this.show = false; }, 4000);
        },
    }));

    // ---- Core restarting after a self update ---------------------------------------------
    // Waits until /up has been unreachable once and answers again, then reloads the page. The
    // card carries data-running while the server knows of a running update; when it vanishes
    // before Core ever went down (it was current), waiting ends without a reload.
    Alpine.data('coreRestart', (running) => ({
        waiting: false,
        init() {
            if (running) this.start();
        },
        start() {
            if (this.waiting) return;
            this.waiting = true;
            this.wait();
        },
        async wait() {
            window.pk95Restarting = true;
            let down = false;
            const until = Date.now() + 10 * 60 * 1000;
            while (Date.now() < until) {
                await new Promise((resolve) => setTimeout(resolve, 3000));
                if (!down && this.$root.dataset.running === undefined) {
                    window.pk95Restarting = false;
                    this.waiting = false;
                    return;
                }
                try {
                    const response = await fetch('/up', { cache: 'no-store' });
                    if (!response.ok) down = true;
                    else if (down) break;
                } catch (e) {
                    down = true;
                }
            }
            window.location.reload();
        },
    }));

    // ---- combobox: searchable dropdown (x-input.combobox) ------------------------------------
    // Options come from data-options (JSON) and follow Livewire re-renders; the value is bound
    // with x-modelable, so wire:model / wire:model.live work as on a native select. The menu
    // is position: fixed next to the trigger, so overflow containers do not cut it off.
    Alpine.data('combobox', (cfg) => ({
        value: cfg.multiple ? [] : '',
        open: false,
        query: '',
        active: 0,
        style: '',
        raw: '[]',
        parsedRaw: null,
        parsed: [],
        init() {
            this.raw = this.$root.dataset.options || '[]';
            new MutationObserver(() => { this.raw = this.$root.dataset.options || '[]'; })
                .observe(this.$root, { attributes: true, attributeFilter: ['data-options'] });
        },
        get options() {
            if (this.raw !== this.parsedRaw) {
                this.parsedRaw = this.raw;
                try { this.parsed = JSON.parse(this.raw); } catch (e) { this.parsed = []; }
            }
            return this.parsed;
        },
        get items() {
            const q = this.query.trim().toLowerCase();
            const list = q ? this.options.filter((o) => (o.label + ' ' + (o.hint || '')).toLowerCase().includes(q)) : this.options;
            let group = null;
            return list.map((o) => {
                const head = o.group && o.group !== group ? o.group : null;
                if (o.group) group = o.group;
                return { ...o, head };
            });
        },
        selected() {
            return cfg.multiple ? (Array.isArray(this.value) ? this.value.map(String) : []) : [String(this.value ?? '')];
        },
        isSelected(v) {
            return this.selected().includes(String(v));
        },
        labelOf(v) {
            const o = this.options.find((x) => x.value === String(v));
            return o ? o.label : String(v);
        },
        get display() {
            if (cfg.multiple) {
                const n = this.selected().length;
                return n === 0 ? cfg.placeholder : (n === 1 ? this.labelOf(this.selected()[0]) : n + ' ' + cfg.many);
            }
            return this.value === '' || this.value === null || this.value === undefined ? cfg.placeholder : this.labelOf(this.value);
        },
        get empty() {
            return cfg.multiple ? this.selected().length === 0 : (this.value === '' || this.value === null || this.value === undefined);
        },
        parts(label) {
            const q = this.query.trim();
            const i = q ? label.toLowerCase().indexOf(q.toLowerCase()) : -1;
            return i < 0 ? [{ t: label, m: false }]
                : [{ t: label.slice(0, i), m: false }, { t: label.slice(i, i + q.length), m: true }, { t: label.slice(i + q.length), m: false }];
        },
        optId(i) {
            return cfg.id + '-o-' + i;
        },
        toggle() {
            this.open ? this.close() : this.show();
        },
        show() {
            this.query = '';
            this.open = true;
            const at = this.items.findIndex((o) => this.isSelected(o.value));
            this.active = at < 0 ? 0 : at;
            this.$nextTick(() => {
                this.place();
                (this.$refs.search || this.$refs.trigger).focus();
                this.reveal();
            });
        },
        close(focus = true) {
            if (!this.open) return;
            this.open = false;
            if (focus) this.$refs.trigger.focus();
        },
        choose(o) {
            if (!o) return;
            if (cfg.multiple) {
                const v = this.selected();
                const k = v.indexOf(String(o.value));
                if (k >= 0) v.splice(k, 1); else v.push(String(o.value));
                this.value = v;
            } else {
                this.value = String(o.value);
                this.close();
            }
        },
        clear() {
            this.value = cfg.multiple ? [] : '';
            this.close();
        },
        key(e) {
            const last = this.items.length - 1;
            switch (e.key) {
                case 'ArrowDown':
                    e.preventDefault();
                    if (!this.open) return this.show();
                    this.active = Math.min(last, this.active + 1);
                    break;
                case 'ArrowUp':
                    e.preventDefault();
                    if (!this.open) return this.show();
                    this.active = Math.max(0, this.active - 1);
                    break;
                case 'Home':
                    if (!this.open) return;
                    e.preventDefault();
                    this.active = 0;
                    break;
                case 'End':
                    if (!this.open) return;
                    e.preventDefault();
                    this.active = Math.max(0, last);
                    break;
                case 'Enter':
                    e.preventDefault();
                    if (!this.open) return this.show();
                    this.choose(this.items[this.active]);
                    break;
                case 'Escape':
                    if (this.open) { e.preventDefault(); e.stopPropagation(); this.close(); }
                    break;
                case 'Tab':
                    this.close(false);
                    return;
                default:
                    // typing on the trigger opens the search and keeps the characters
                    if (cfg.search && e.target === this.$refs.trigger && e.key.length === 1 && !e.ctrlKey && !e.metaKey && !e.altKey) {
                        e.preventDefault();
                        if (!this.open) this.show();
                        this.query += e.key;
                        this.active = 0;
                        this.reveal();
                    }
                    return;
            }
            this.reveal();
        },
        reveal() {
            this.$nextTick(() => document.getElementById(this.optId(this.active))?.scrollIntoView({ block: 'nearest' }));
        },
        // below the trigger, or above when there is more room there; full width on phones
        place() {
            if (!this.open || !this.$refs.menu) return;
            const r = this.$refs.trigger.getBoundingClientRect();
            const vw = window.innerWidth;
            const vh = window.innerHeight;
            const h = Math.min(this.$refs.menu.scrollHeight, 336);
            const below = vh - r.bottom - 8;
            const above = r.top - 8;
            const up = below < h && above > below;
            let left = r.left;
            let width = Math.max(r.width, 240);
            if (vw < 640) { left = 8; width = vw - 16; } else if (left + width > vw - 8) { left = Math.max(8, vw - 8 - width); }
            const top = up ? Math.max(8, r.top - 4 - h) : r.bottom + 4;
            this.style = `top:${top}px;left:${left}px;width:${width}px;max-height:${Math.max(160, Math.min(336, up ? above : below))}px`;
        },
    }));

    // ---- live deployment log console --------------------------------------------------------
    Alpine.data('deploymentLog', (initial) => ({
        lines: initial || [],
        autoscroll: true,
        init() {
            this.$nextTick(() => this.scrollDown());
        },
        append(lines) {
            if (!Array.isArray(lines) || !lines.length) return;
            const lastId = this.lines.length ? this.lines[this.lines.length - 1].id : 0;
            for (const l of lines) if (l.id > lastId) this.lines.push(l);
            if (this.lines.length > 5000) this.lines.splice(0, this.lines.length - 5000);
            if (this.autoscroll) this.$nextTick(() => this.scrollDown());
        },
        scrollDown() {
            const c = this.$refs.console;
            if (c) c.scrollTop = c.scrollHeight;
        },
        onScroll() {
            const c = this.$refs.console;
            this.autoscroll = c.scrollHeight - c.scrollTop - c.clientHeight < 40;
        },
        lineClass(l) {
            return {
                step: 'text-cerulean-300 font-semibold pt-2',
                error: 'text-red-400 font-semibold',
                success: 'text-emerald-400 font-semibold',
                task: 'text-gray-400',
                out: 'text-gray-200',
            }[l.level] || 'text-gray-300';
        },
    }));
});
