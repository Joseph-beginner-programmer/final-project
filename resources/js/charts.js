const SVG_NS = 'http://www.w3.org/2000/svg';

const svgEl = (tag, attrs = {}, style = {}) => {
    const el = document.createElementNS(SVG_NS, tag);
    Object.entries(attrs).forEach(([key, value]) => el.setAttribute(key, value));
    Object.assign(el.style, style);
    return el;
};

const niceMax = (value) => {
    if (value <= 0) return 1;
    const exponent = Math.pow(10, Math.floor(Math.log10(value)));
    const fraction = value / exponent;
    const nice = fraction <= 1 ? 1 : fraction <= 2 ? 2 : fraction <= 2.5 ? 2.5 : fraction <= 5 ? 5 : 10;
    return nice * exponent;
};

const formatters = {
    number: (v) => new Intl.NumberFormat('id-ID', { maximumFractionDigits: 0 }).format(v),
    percent: (v) => new Intl.NumberFormat('id-ID', { minimumFractionDigits: 1, maximumFractionDigits: 1 }).format(v) + '%',
    percentAxis: (v) => new Intl.NumberFormat('id-ID', { maximumFractionDigits: 2 }).format(v) + '%',
    rupiah: (v) => (v < 0 ? '−Rp ' : 'Rp ') + new Intl.NumberFormat('id-ID', { maximumFractionDigits: 0 }).format(Math.abs(v)),
    compactRupiah: (v) => (v < 0 ? '−' : '') + 'Rp ' + new Intl.NumberFormat('id-ID', { notation: 'compact', maximumFractionDigits: 1 }).format(Math.abs(v)),
};

const roundedBar = (x, y, w, h, r, roundTop) => {
    const radius = Math.min(r, h, w / 2);
    if (radius <= 0) return `M${x},${y}h${w}v${h}h${-w}Z`;
    return roundTop
        ? `M${x},${y + h}V${y + radius}Q${x},${y} ${x + radius},${y}H${x + w - radius}Q${x + w},${y} ${x + w},${y + radius}V${y + h}Z`
        : `M${x},${y}H${x + w}V${y + h - radius}Q${x + w},${y + h} ${x + w - radius},${y + h}H${x + radius}Q${x},${y + h} ${x},${y + h - radius}Z`;
};

const chartBase = (config) => ({
    config,
    isolated: null,
    tooltip: { show: false, x: 0, y: 0, title: '', rows: [] },
    observer: null,

    init() {
        this.observer = new ResizeObserver(() => this.render());
        this.observer.observe(this.$refs.frame);
        this.$watch('isolated', () => this.render());
        this.render();
    },

    destroy() {
        this.observer?.disconnect();
    },

    toggle(id) {
        if (!this.config.isolatable) return;
        this.isolated = this.isolated === id ? null : id;
    },

    isDimmed(id) {
        return this.isolated !== null && this.isolated !== id;
    },

    format(value) {
        return formatters[this.config.format ?? 'number'](value);
    },

    showTooltip(event, title, rows) {
        const frame = this.$refs.frame.getBoundingClientRect();
        const point = event.clientX !== undefined && event.clientX !== 0
            ? { x: event.clientX - frame.left, y: event.clientY - frame.top }
            : { x: event.target.getBBox().x + event.target.getBBox().width / 2, y: 20 };
        this.tooltip = { show: true, x: Math.min(point.x + 12, frame.width - 180), y: Math.max(point.y - 10, 0), title, rows };
    },

    hideTooltip() {
        this.tooltip.show = false;
    },

    drawYAxis(svg, plot, max, ticks = 4) {
        for (let i = 0; i <= ticks; i++) {
            const value = (max / ticks) * i;
            const y = plot.top + plot.height - (value / max) * plot.height;
            svg.appendChild(svgEl('line', { x1: plot.left, x2: plot.left + plot.width, y1: y, y2: y, 'stroke-width': 1 }, { stroke: i === 0 ? 'var(--viz-baseline)' : 'var(--viz-grid)' }));
            const label = svgEl('text', { x: plot.left - 8, y: y + 4, 'text-anchor': 'end', 'font-size': 11 }, { fill: 'var(--viz-muted)', fontVariantNumeric: 'tabular-nums' });
            label.textContent = this.config.axisFormat ? formatters[this.config.axisFormat](value) : this.format(value);
            svg.appendChild(label);
        }
    },

    drawXLabels(svg, plot, labels, centerOf) {
        labels.forEach((text, i) => {
            const label = svgEl('text', { x: centerOf(i), y: plot.top + plot.height + 18, 'text-anchor': 'middle', 'font-size': 11 }, { fill: 'var(--viz-muted)' });
            label.textContent = text;
            svg.appendChild(label);
        });
    },
});

export const stackedBar = (config) => ({
    ...chartBase(config),

    visibleSeries() {
        return this.config.series.filter((s) => this.isolated === null || this.isolated === s.id);
    },

    render() {
        const svg = this.$refs.svg;
        const width = this.$refs.frame.clientWidth;
        const height = 240;
        svg.setAttribute('viewBox', `0 0 ${width} ${height}`);
        svg.setAttribute('width', width);
        svg.replaceChildren();

        const plot = { left: this.config.axisFormat === 'compactRupiah' ? 64 : 44, top: 12, width: width - (this.config.axisFormat === 'compactRupiah' ? 72 : 52), height: height - 40 };
        const labels = this.config.labels;
        const series = this.visibleSeries();
        const showTargets = this.config.targets && this.isolated !== null;

        const totals = labels.map((_, i) => series.reduce((sum, s) => sum + Math.max(0, s.values[i]), 0));
        const negatives = labels.map((_, i) => series.reduce((sum, s) => sum + Math.min(0, s.values[i]), 0));
        const targetValues = showTargets ? labels.map((_, i) => series[0]?.targets[i] ?? 0) : [];
        const maxValue = niceMax(Math.max(...totals, ...targetValues, 0));
        const minValue = Math.min(...negatives, 0) < 0 ? -niceMax(-Math.min(...negatives)) : 0;
        const span = maxValue - minValue;
        const yOf = (v) => plot.top + plot.height - ((v - minValue) / span) * plot.height;

        for (let i = 0; i <= 4; i++) {
            const value = minValue + (span / 4) * i;
            const y = yOf(value);
            svg.appendChild(svgEl('line', { x1: plot.left, x2: plot.left + plot.width, y1: y, y2: y, 'stroke-width': 1 }, { stroke: Math.abs(value) < 1e-9 ? 'var(--viz-baseline)' : 'var(--viz-grid)' }));
            const label = svgEl('text', { x: plot.left - 8, y: y + 4, 'text-anchor': 'end', 'font-size': 11 }, { fill: 'var(--viz-muted)', fontVariantNumeric: 'tabular-nums' });
            label.textContent = formatters[this.config.axisFormat ?? this.config.format ?? 'number'](value);
            svg.appendChild(label);
        }
        if (minValue < 0) {
            svg.appendChild(svgEl('line', { x1: plot.left, x2: plot.left + plot.width, y1: yOf(0), y2: yOf(0), 'stroke-width': 1 }, { stroke: 'var(--viz-baseline)' }));
        }

        const band = plot.width / labels.length;
        const barWidth = Math.min(24, band * 0.5);
        const centerOf = (i) => plot.left + band * i + band / 2;

        labels.forEach((label, i) => {
            const x = centerOf(i) - barWidth / 2;
            let up = 0;
            let down = 0;
            const ups = series.filter((s) => s.values[i] > 0);
            const downs = series.filter((s) => s.values[i] < 0);

            ups.forEach((s, j) => {
                const y0 = yOf(up);
                up += s.values[i];
                const y1 = yOf(up);
                const isTop = j === ups.length - 1;
                const h = Math.max(0, y0 - y1 - (isTop ? 0 : 2));
                svg.appendChild(svgEl('path', { d: roundedBar(x, y1, barWidth, h, 4, isTop) }, { fill: `var(--series-${s.slot})` }));
            });
            downs.forEach((s, j) => {
                const y0 = yOf(down);
                down += s.values[i];
                const y1 = yOf(down);
                const isBottom = j === downs.length - 1;
                const h = Math.max(0, y1 - y0 - (isBottom ? 0 : 2));
                svg.appendChild(svgEl('path', { d: roundedBar(x, y0, barWidth, h, 4, false) }, { fill: `var(--series-${s.slot})` }));
            });

            if (showTargets && targetValues[i] > 0) {
                const y = yOf(targetValues[i]);
                const tw = Math.min(band * 0.8, barWidth + 20);
                svg.appendChild(svgEl('line', { x1: centerOf(i) - tw / 2, x2: centerOf(i) + tw / 2, y1: y, y2: y, 'stroke-width': 2, 'stroke-linecap': 'round' }, { stroke: 'var(--viz-ink)' }));
            }

            const hit = svgEl('rect', { x: plot.left + band * i, y: plot.top, width: band, height: plot.height, tabindex: 0, role: 'img', 'aria-label': label }, { fill: 'transparent', cursor: 'default', outline: 'none' });
            const rows = series.map((s) => ({ name: s.name, value: this.format(s.values[i]), slot: s.slot, kind: 'bar' }));
            if (series.length > 1) rows.push({ name: this.config.totalLabel, value: this.format(totals[i] + negatives[i]), slot: null, kind: 'total' });
            if (showTargets) rows.push({ name: this.config.targetLabel, value: this.format(targetValues[i]), slot: null, kind: 'target' });
            const show = (e) => { this.showTooltip(e, label, rows); hit.style.fill = 'var(--viz-hover)'; };
            const hide = () => { this.hideTooltip(); hit.style.fill = 'transparent'; };
            hit.addEventListener('pointermove', show);
            hit.addEventListener('focus', show);
            hit.addEventListener('pointerleave', hide);
            hit.addEventListener('blur', hide);
            svg.insertBefore(hit, svg.firstChild);
        });

        this.drawXLabels(svg, plot, labels, centerOf);
    },
});

export const lineTrend = (config) => ({
    ...chartBase(config),

    render() {
        const svg = this.$refs.svg;
        const width = this.$refs.frame.clientWidth;
        const height = 220;
        svg.setAttribute('viewBox', `0 0 ${width} ${height}`);
        svg.setAttribute('width', width);
        svg.replaceChildren();

        const plot = { left: 44, top: 14, width: width - 92, height: height - 40 };
        const labels = this.config.labels;
        const lines = [{ id: 'overall', name: this.config.overallLabel, values: this.config.overall, color: 'var(--viz-ink-secondary)' }];
        if (this.isolated !== null) {
            const s = this.config.series.find((row) => row.id === this.isolated);
            if (s) lines.push({ id: s.id, name: s.name, values: s.values, color: `var(--series-${s.slot})` });
        }

        const all = lines.flatMap((l) => l.values).filter((v) => v !== null);
        const max = niceMax(Math.max(...all, 1));
        const inset = 24;
        const step = labels.length > 1 ? (plot.width - inset * 2) / (labels.length - 1) : 0;
        const xOf = (i) => plot.left + inset + step * i;
        const yOf = (v) => plot.top + plot.height - (v / max) * plot.height;

        this.drawYAxis(svg, plot, max);
        this.drawXLabels(svg, plot, labels, xOf);

        const crosshair = svgEl('line', { y1: plot.top, y2: plot.top + plot.height, 'stroke-width': 1, visibility: 'hidden' }, { stroke: 'var(--viz-baseline)' });
        svg.appendChild(crosshair);

        lines.forEach((line) => {
            let d = '';
            let penDown = false;
            line.values.forEach((v, i) => {
                if (v === null) { penDown = false; return; }
                d += `${penDown ? 'L' : 'M'}${xOf(i)},${yOf(v)}`;
                penDown = true;
            });
            if (d) svg.appendChild(svgEl('path', { d, fill: 'none', 'stroke-width': 2, 'stroke-linejoin': 'round', 'stroke-linecap': 'round' }, { stroke: line.color }));
            line.values.forEach((v, i) => {
                if (v === null) return;
                svg.appendChild(svgEl('circle', { cx: xOf(i), cy: yOf(v), r: 4, 'stroke-width': 2 }, { fill: line.color, stroke: 'var(--viz-surface)' }));
            });
            const lastIndex = line.values.map((v) => v !== null).lastIndexOf(true);
            if (lastIndex >= 0) {
                const label = svgEl('text', { x: xOf(lastIndex) + 8, y: yOf(line.values[lastIndex]) + 4, 'font-size': 11, 'font-weight': 600 }, { fill: 'var(--viz-ink)' });
                label.textContent = this.format(line.values[lastIndex]);
                svg.appendChild(label);
            }
        });

        const hit = svgEl('rect', { x: plot.left, y: plot.top, width: plot.width, height: plot.height, tabindex: 0, role: 'img', 'aria-label': this.config.ariaLabel }, { fill: 'transparent', outline: 'none' });
        const showAt = (i, e) => {
            crosshair.setAttribute('x1', xOf(i));
            crosshair.setAttribute('x2', xOf(i));
            crosshair.setAttribute('visibility', 'visible');
            this.showTooltip(e, labels[i], lines.map((l) => ({ name: l.name, value: l.values[i] === null ? '—' : this.format(l.values[i]), color: l.color, kind: 'line' })));
        };
        let focusIndex = labels.length - 1;
        hit.addEventListener('pointermove', (e) => {
            const rect = svg.getBoundingClientRect();
            const i = Math.max(0, Math.min(labels.length - 1, Math.round((e.clientX - rect.left - plot.left - inset) / (step || 1))));
            showAt(i, e);
        });
        hit.addEventListener('focus', (e) => showAt(focusIndex, e));
        hit.addEventListener('keydown', (e) => {
            if (e.key === 'ArrowLeft') focusIndex = Math.max(0, focusIndex - 1);
            else if (e.key === 'ArrowRight') focusIndex = Math.min(labels.length - 1, focusIndex + 1);
            else return;
            e.preventDefault();
            showAt(focusIndex, e);
        });
        const hide = () => { crosshair.setAttribute('visibility', 'hidden'); this.hideTooltip(); };
        hit.addEventListener('pointerleave', hide);
        hit.addEventListener('blur', hide);
        svg.appendChild(hit);
    },
});

const register = () => {
    window.Alpine.data('stackedBar', stackedBar);
    window.Alpine.data('lineTrend', lineTrend);
};

if (window.Alpine) register();
document.addEventListener('alpine:init', register);
