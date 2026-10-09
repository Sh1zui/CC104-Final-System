/*
 * Reports page (Phase 12): the period picker and two charts. Data comes
 * from the JSON block the page renders (#report-data); colors come from
 * the chart tokens in style.css (validated for color-vision deficiency in
 * light and dark), so the charts follow the theme.
 */
(function () {
    'use strict';

    // Period picker: show the dates only for "Custom range"; presets apply at once.
    var range = document.getElementById('r-range');
    if (range) {
        range.addEventListener('change', function () {
            var custom = range.value === 'custom';
            document.getElementById('r-custom').classList.toggle('d-none', !custom);
            if (!custom) document.getElementById('report-filters').submit();
        });
    }

    var dataEl = document.getElementById('report-data');
    if (!dataEl || typeof Chart === 'undefined') return;
    var data = JSON.parse(dataEl.textContent);
    var charts = [];

    function cssVar(name) { return getComputedStyle(document.documentElement).getPropertyValue(name).trim(); }
    function peso(v) { return '₱' + Number(v).toLocaleString('en-PH', { maximumFractionDigits: 0 }); }

    function build() {
        charts.forEach(function (c) { c.destroy(); });
        charts = [];
        Chart.defaults.font.family = cssVar('--font-ui');
        Chart.defaults.color = cssVar('--text-muted');
        var grid = cssVar('--border');
        var surface = cssVar('--surface');
        var tooltip = {
            backgroundColor: cssVar('--text'), titleColor: surface, bodyColor: surface,
            padding: 10, cornerRadius: 6, displayColors: true, boxPadding: 4
        };
        var xTicks = { maxRotation: 0, autoSkip: true, maxTicksLimit: data.granularity === 'day' ? 10 : 12 };

        var rev = document.getElementById('revenue-chart');
        if (rev) {
            charts.push(new Chart(rev, {
                type: 'bar',
                data: { labels: data.labels, datasets: [{ label: 'Revenue', data: data.revenue, backgroundColor: cssVar('--chart-1'),
                         borderRadius: { topLeft: 4, topRight: 4 }, borderSkipped: 'start', maxBarThickness: 36 }] },
                options: {
                    maintainAspectRatio: false,
                    interaction: { mode: 'index', intersect: false },
                    plugins: {
                        legend: { display: false },
                        tooltip: Object.assign({}, tooltip, { displayColors: false, callbacks: { label: function (c) { return ' ' + peso(c.parsed.y); } } })
                    },
                    scales: {
                        x: { grid: { display: false }, ticks: xTicks },
                        y: { beginAtZero: true, grid: { color: grid }, border: { display: false }, ticks: { callback: peso, maxTicksLimit: 5 } }
                    }
                }
            }));
        }

        var bk = document.getElementById('bookings-chart');
        if (bk) {
            charts.push(new Chart(bk, {
                type: 'bar',
                data: {
                    labels: data.labels,
                    datasets: [
                        { label: 'Desk', data: data.bookings.desk, backgroundColor: cssVar('--chart-1'), borderColor: surface, borderWidth: { top: 2 },
                          borderRadius: 0, borderSkipped: 'start', maxBarThickness: 28, stack: 's' },
                        { label: 'Online', data: data.bookings.online, backgroundColor: cssVar('--chart-2'), borderColor: surface, borderWidth: { top: 2 },
                          borderRadius: { topLeft: 4, topRight: 4 }, borderSkipped: 'start', maxBarThickness: 28, stack: 's' }
                    ]
                },
                options: {
                    maintainAspectRatio: false,
                    interaction: { mode: 'index', intersect: false },
                    plugins: {
                        legend: { position: 'top', align: 'end', labels: { boxWidth: 10, boxHeight: 10, useBorderRadius: true, borderRadius: 2 } },
                        tooltip: tooltip
                    },
                    scales: {
                        x: { stacked: true, grid: { display: false }, ticks: xTicks },
                        y: { stacked: true, beginAtZero: true, grid: { color: grid }, border: { display: false }, ticks: { precision: 0, maxTicksLimit: 5 } }
                    }
                }
            }));
        }
    }

    build();
    // Re-read the tokens when the theme changes (app.js flips data-theme on <html>).
    new MutationObserver(build).observe(document.documentElement, { attributes: true, attributeFilter: ['data-theme'] });
    if (window.matchMedia) {
        var mq = window.matchMedia('(prefers-color-scheme: dark)');
        if (mq.addEventListener) mq.addEventListener('change', build);
    }
})();
