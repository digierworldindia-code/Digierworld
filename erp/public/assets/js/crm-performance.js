/* CRM performance: daily calls chart. */
(function () {
    'use strict';
    const el = document.getElementById('chCalls');
    const data = document.getElementById('perf-data');
    if (!el || !data || typeof Chart === 'undefined') { return; }
    const d = JSON.parse(data.textContent);
    Chart.defaults.font.family = getComputedStyle(document.body).fontFamily;
    new Chart(el, {
        type: 'bar',
        data: { labels: d.labels, datasets: [
            { label: 'Calls', data: d.calls, backgroundColor: '#1b1b1b' },
            { label: 'Connected', data: d.connected, backgroundColor: '#b08d57' },
        ] },
        options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom' } }, scales: { y: { beginAtZero: true, ticks: { precision: 0 } } } },
    });
})();
