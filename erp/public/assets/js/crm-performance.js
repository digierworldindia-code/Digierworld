/* CRM performance: daily calls chart. */
(function () {
    'use strict';
    const el = document.getElementById('chCalls');
    const data = document.getElementById('perf-data');
    if (!el || !data || typeof Chart === 'undefined') { return; }
    const d = JSON.parse(data.textContent);
    const theme = DGR.chartTheme();
    new Chart(el, {
        type: 'bar',
        data: { labels: d.labels, datasets: [
            { label: 'Calls', data: d.calls, backgroundColor: theme.series[1] },
            { label: 'Connected', data: d.connected, backgroundColor: theme.gold },
        ] },
        options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom' } }, scales: { y: { beginAtZero: true, ticks: { precision: 0 } } } },
    });
})();
