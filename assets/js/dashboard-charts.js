/*
 * Admin dashboard charts. Reads its data from the JSON block the page
 * renders (#dashboard-data) and its colors from the same CSS variables the
 * rest of the UI uses, so the charts follow light/dark mode automatically.
 */
(function () {
    'use strict';

    var dataEl = document.getElementById('dashboard-data');
    if (!dataEl || typeof Chart === 'undefined') return;

    var data = JSON.parse(dataEl.textContent);
    var charts = [];

    function cssVar(name) {
        return getComputedStyle(document.documentElement).getPropertyValue(name).trim();
    }

    function peso(value) {
        return '\u20B1' + Number(value).toLocaleString('en-PH', { maximumFractionDigits: 0 });
    }

    function build() {
        charts.forEach(function (c) { c.destroy(); });
        charts = [];

        Chart.defaults.font.family = cssVar('--font-ui');
        Chart.defaults.font.weight = 500;
        Chart.defaults.color = cssVar('--text-muted');

        var revenueCanvas = document.getElementById('revenue-chart');
        if (revenueCanvas) {
            charts.push(new Chart(revenueCanvas, {
                type: 'bar',
                data: {
                    labels: data.revenue.labels,
                    datasets: [{
                        data: data.revenue.values,
                        backgroundColor: cssVar('--chart-1'),
                        hoverBackgroundColor: cssVar('--chart-2'),
                        borderRadius: { topLeft: 6, topRight: 6 },
                        borderSkipped: 'start',
                        maxBarThickness: 42
                    }]
                },
                options: {
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false },
                        tooltip: { callbacks: { label: function (ctx) { return ' ' + peso(ctx.parsed.y); } } }
                    },
                    scales: {
                        x: { grid: { display: false } },
                        y: {
                            beginAtZero: true,
                            grid: { color: cssVar('--border') },
                            ticks: { callback: peso }
                        }
                    }
                }
            }));
        }

        var fleetCanvas = document.getElementById('fleet-chart');
        if (fleetCanvas) {
            // Same status colors as the stat tiles and badges.
            var colors = [
                cssVar('--success'),  // available
                cssVar('--info'),     // rented
                cssVar('--warning'),  // maintenance (orange, like its stat tile)
                cssVar('--danger')    // unavailable
            ];

            charts.push(new Chart(fleetCanvas, {
                type: 'doughnut',
                data: {
                    labels: data.fleet.labels,
                    datasets: [{
                        data: data.fleet.values,
                        backgroundColor: colors,
                        borderColor: cssVar('--surface'),
                        borderWidth: 2
                    }]
                },
                options: {
                    maintainAspectRatio: false,
                    cutout: '62%',
                    plugins: { legend: { position: 'bottom', labels: { boxWidth: 10, boxHeight: 10, usePointStyle: true } } }
                }
            }));
        }
    }

    build();
    document.addEventListener('themechange', build);
})();
