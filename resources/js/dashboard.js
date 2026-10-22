import { Chart, registerables } from 'chart.js';

Chart.register(...registerables);

const BRAND = '#15814b';
const BRAND_SOFT = '#9fd2b6';
const GRID = '#eceef1';
const MUTED = '#6b7280';

Chart.defaults.font.family = "'Manrope Variable', system-ui, sans-serif";
Chart.defaults.color = MUTED;

const compact = (value) => {
    const abs = Math.abs(value);

    if (abs >= 1e9) return `${+(value / 1e9).toFixed(1)}B`;
    if (abs >= 1e6) return `${+(value / 1e6).toFixed(1)}M`;
    if (abs >= 1e3) return `${+(value / 1e3).toFixed(0)}k`;

    return `${value}`;
};

/**
 * Base component for every dashboard card: loads its own endpoint, exposes
 * loading / error / empty states and reloads when the date range changes.
 */
const cardLoader = ({ url, params = () => ({}) }) => ({
    url,
    data: null,
    loading: true,
    error: false,
    controller: null,

    init() {
        this.load();
    },

    async load() {
        this.controller?.abort();
        this.controller = new AbortController();
        this.loading = true;
        this.error = false;

        const query = new URLSearchParams({ ...this.range(), ...params.call(this) });

        try {
            const response = await fetch(`${this.url}?${query}`, {
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                signal: this.controller.signal,
                credentials: 'same-origin',
            });

            if (!response.ok) throw new Error(`HTTP ${response.status}`);

            this.data = await response.json();
            this.loading = false;
            this.$nextTick(() => this.render?.());
        } catch (error) {
            if (error.name === 'AbortError') return;

            this.error = true;
            this.loading = false;
        }
    },

    range() {
        return this.$store.range ? { from: this.$store.range.from, to: this.$store.range.to } : {};
    },
});

const destroyChart = (component) => {
    component.chart?.destroy();
    component.chart = null;
};

export function registerDashboard(Alpine) {
    Alpine.store('range', { from: null, to: null });

    Alpine.data('rangePicker', ({ from, to, locale }) => ({
        open: false,
        from,
        to,
        draft: { from, to },

        init() {
            this.$store.range.from = from;
            this.$store.range.to = to;
        },

        get label() {
            const fmt = (iso) => iso.split('-').reverse().join('.');

            return `${fmt(this.from)} – ${fmt(this.to)}`;
        },

        preset(months) {
            const end = new Date();
            const start = new Date(end.getFullYear(), end.getMonth() - months + 1, 1);

            this.draft.from = this.iso(start);
            this.draft.to = this.iso(end);
        },

        thisYear() {
            const end = new Date();

            this.draft.from = `${end.getFullYear()}-01-01`;
            this.draft.to = this.iso(end);
        },

        iso(date) {
            const pad = (n) => String(n).padStart(2, '0');

            return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
        },

        apply() {
            if (!this.draft.from || !this.draft.to || this.draft.from > this.draft.to) return;

            this.from = this.draft.from;
            this.to = this.draft.to;
            this.$store.range.from = this.from;
            this.$store.range.to = this.to;
            this.open = false;
            window.dispatchEvent(new CustomEvent('range-changed'));
        },
    }));

    Alpine.data('simpleCard', (url) => cardLoader({ url }));

    Alpine.data('trendCard', (url, labels) => ({
        ...cardLoader({ url, params() { return { mode: this.mode }; } }),
        mode: 'monthly',
        chart: null,
        labels,

        setMode(mode) {
            this.mode = mode;
            this.load();
        },

        render() {
            destroyChart(this);

            const canvas = this.$refs.canvas;
            if (!canvas || !this.data?.has_data) return;

            const income = this.data.income;
            const peak = income.indexOf(Math.max(...income));

            this.chart = new Chart(canvas, {
                type: 'bar',
                data: {
                    labels: this.data.labels,
                    datasets: [
                        {
                            label: this.labels.income,
                            data: income,
                            backgroundColor: income.map((_, i) => (i === peak ? BRAND : BRAND_SOFT)),
                            borderRadius: 18,
                            borderSkipped: false,
                            barPercentage: 0.8,
                            categoryPercentage: 0.7,
                        },
                        {
                            label: this.labels.expense,
                            data: this.data.expense,
                            backgroundColor: '#d9dee3',
                            borderRadius: 18,
                            borderSkipped: false,
                            barPercentage: 0.8,
                            categoryPercentage: 0.7,
                        },
                    ],
                },
                options: {
                    maintainAspectRatio: false,
                    interaction: { mode: 'index', intersect: false },
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            backgroundColor: '#101418',
                            padding: 10,
                            cornerRadius: 10,
                            callbacks: {
                                label: (ctx) => {
                                    const source = ctx.datasetIndex === 0 ? this.data.income_labels : this.data.expense_labels;

                                    return ` ${ctx.dataset.label}: ${source[ctx.dataIndex]}`;
                                },
                            },
                        },
                    },
                    scales: {
                        x: { grid: { display: false }, border: { display: false } },
                        y: {
                            beginAtZero: true,
                            border: { display: false, dash: [4, 4] },
                            grid: { color: GRID },
                            ticks: { callback: compact, maxTicksLimit: 6 },
                        },
                    },
                },
            });
        },
    }));

    Alpine.data('cashflowCard', (url) => ({
        ...cardLoader({ url, params() { return { series: this.series }; } }),
        series: 'income',
        chart: null,

        setSeries(series) {
            this.series = series;
            this.load();
        },

        render() {
            destroyChart(this);

            const canvas = this.$refs.canvas;
            if (!canvas || !this.data?.has_data) return;

            const ctx = canvas.getContext('2d');
            const gradient = ctx.createLinearGradient(0, 0, 0, canvas.height || 200);
            gradient.addColorStop(0, 'rgba(21,129,75,0.35)');
            gradient.addColorStop(1, 'rgba(21,129,75,0)');

            this.chart = new Chart(canvas, {
                type: 'line',
                data: {
                    labels: this.data.labels,
                    datasets: [
                        {
                            data: this.data.values,
                            borderColor: BRAND,
                            backgroundColor: gradient,
                            fill: true,
                            tension: 0.4,
                            borderWidth: 2,
                            pointRadius: 0,
                            pointHoverRadius: 5,
                            pointHoverBackgroundColor: BRAND,
                        },
                    ],
                },
                options: {
                    maintainAspectRatio: false,
                    interaction: { mode: 'index', intersect: false },
                    layout: { padding: 0 },
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            backgroundColor: '#101418',
                            padding: 10,
                            cornerRadius: 10,
                            callbacks: {
                                title: (items) => items[0].label,
                                label: (ctx) => ` ${this.data.value_labels[ctx.dataIndex]}`,
                            },
                        },
                    },
                    scales: { x: { display: false }, y: { display: false, beginAtZero: true } },
                },
            });
        },
    }));
}
