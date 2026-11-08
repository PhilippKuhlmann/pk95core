/*
 * World map of the PK95 Proxy overview: country paths from the panel's map data
 * (public/modules/proxy/world.json, Natural Earth, public domain), coloured in five
 * logarithmic steps like the original. The data is large and never changes: fetched once.
 */
let worldData = null;

export function registerProxyMap(Alpine) {
    Alpine.data('proxyWorldMap', (counts, names, total, href) => ({
        view: 'world',
        tip: null,
        error: '',
        offMap: '',
        world: null,

        async init() {
            try { if (localStorage.getItem('proxypanel-map') === 'europe') this.view = 'europe'; } catch (e) { /* optional */ }
            try {
                worldData ??= fetch('/modules/proxy/world.json').then((r) => {
                    if (!r.ok) throw new Error(`Fehler ${r.status}`);
                    return r.json();
                }).catch((e) => { worldData = null; throw e; });
                this.world = await worldData;
                this.draw();
            } catch (e) {
                this.error = 'Karte konnte nicht geladen werden: ' + e.message;
            }
        },

        setView(v) {
            this.view = v;
            try { localStorage.setItem('proxypanel-map', v); } catch (e) { /* optional */ }
            if (this.world) this.draw();
        },

        draw() {
            const w = this.world;
            const svg = this.$refs.svg;
            const ns = 'http://www.w3.org/2000/svg';
            const max = Object.entries(counts).filter(([c]) => w.countries[c]).reduce((m, [, n]) => Math.max(m, n), 0);
            const step = (n) => Math.max(1, Math.ceil((Math.log(n + 1) / Math.log(max + 1)) * 5));
            svg.setAttribute('viewBox', w[this.view].join(' '));
            svg.replaceChildren();
            const add = (d, level, code) => {
                const p = document.createElementNS(ns, 'path');
                p.setAttribute('d', d);
                p.setAttribute('fill', `var(--proxy-map-${level})`);
                p.setAttribute('stroke', 'var(--proxy-map-line)');
                p.setAttribute('stroke-width', '0.3');
                if (code) {
                    p.dataset.c = code;
                    if (counts[code]) p.style.cursor = 'pointer';
                }
                svg.append(p);
            };
            w.unnamed.forEach((d) => add(d, 0));
            for (const [code, d] of Object.entries(w.countries)) add(d, counts[code] ? step(counts[code]) : 0, code);

            const tipFor = (e) => {
                const code = e.target.dataset && e.target.dataset.c;
                if (!code) { this.tip = null; return; }
                const n = counts[code] || 0;
                const r = this.$refs.box.getBoundingClientRect();
                const pct = total ? (n / total) * 100 : 0;
                const pctText = pct > 0 && pct < 0.1 ? '<0,1' : pct.toLocaleString('de-DE', { maximumFractionDigits: 1 });
                this.tip = {
                    x: Math.max(0, e.clientX - r.left + 12),
                    y: Math.max(0, e.clientY - r.top - 36),
                    text: `${names[code] || code}: ${n.toLocaleString('de-DE')} Anfragen (${pctText} %)`,
                };
            };
            svg.onpointermove = tipFor;
            svg.onpointerdown = tipFor;
            svg.onclick = (e) => {
                const code = e.target.dataset && e.target.dataset.c;
                if (code && counts[code]) window.Livewire.navigate(href.replace('__C__', encodeURIComponent(code)));
            };

            const off = Object.entries(counts).filter(([c]) => !w.countries[c]);
            this.offMap = off.length
                ? 'Nicht auf der Karte: ' + off.map(([c, n]) => `${c === 'LAN' ? 'Lokales Netz' : (names[c] || c || 'Unbekannt')} ${n.toLocaleString('de-DE')}`).join(', ')
                : '';
        },
    }));
}
