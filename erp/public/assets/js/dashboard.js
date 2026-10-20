/* Dashboard charts — data comes from the permission-gated /dashboard/data endpoint. */
(function () {
    'use strict';
    const wrap = document.querySelector('[data-dashboard-charts]');
    if (!wrap || typeof Chart === 'undefined') { return; }

    const theme = DGR.chartTheme();
    const gold = theme.gold, ink = theme.series[1], grey = theme.series[2];
    const palette = theme.series;
    const inrTick = (v) => '₹' + Number(v).toLocaleString('en-IN', { maximumFractionDigits: 0 });
    const tooltipInr = { callbacks: { label: (c) => `${c.dataset.label || c.label}: ₹${Number(c.parsed.y ?? c.parsed).toLocaleString('en-IN', { minimumFractionDigits: 2 })}` } };

    const empty = (id) => { const el = document.getElementById(id); if (el) { el.replaceWith(Object.assign(document.createElement('div'), { className: 'empty-state py-4', innerHTML: '<i class="bi bi-bar-chart"></i>No data for this period yet.' })); } };
    const hasData = (arr) => Array.isArray(arr) && arr.some((v) => Number(v) !== 0);

    DGR.api(wrap.dataset.endpoint).then(({ charts }) => {
        const b = charts.billed_vs_collected;
        if (b && (hasData(b.billed) || hasData(b.collected))) {
            new Chart(document.getElementById('chBilled'), {
                type: 'bar',
                data: { labels: b.labels, datasets: [
                    { label: 'Billed (net of credit notes)', data: b.billed, backgroundColor: ink },
                    { label: 'Collected (net of refunds)', data: b.collected, backgroundColor: gold },
                ] },
                options: { responsive: true, scales: { y: { ticks: { callback: inrTick } } }, plugins: { tooltip: tooltipInr } },
            });
        } else { empty('chBilled'); }

        const doughnut = (id, set) => {
            if (!set || !hasData(set.values)) { empty(id); return; }
            new Chart(document.getElementById(id), { type: 'doughnut', data: { labels: set.labels, datasets: [{ data: set.values, backgroundColor: palette }] }, options: { plugins: { legend: { position: 'bottom' } } } });
        };
        const hbar = (id, set, color) => {
            if (!set || !hasData(set.values)) { empty(id); return; }
            new Chart(document.getElementById(id), { type: 'bar', data: { labels: set.labels, datasets: [{ label: '₹', data: set.values, backgroundColor: color }] }, options: { indexAxis: 'y', plugins: { legend: { display: false } }, scales: { x: { ticks: { callback: inrTick } } } } });
        };
        doughnut('chGst', charts.gst_split);
        doughnut('chModes', charts.payment_modes);
        hbar('chExp', charts.expenses, grey);
        hbar('chAging', charts.aging, gold);
        hbar('chSvc', charts.top_services, ink);

        const y = charts.yoy;
        if (y && (hasData(y.current) || hasData(y.previous))) {
            new Chart(document.getElementById('chYoy'), {
                type: 'line',
                data: { labels: y.labels, datasets: [
                    { label: y.current_label, data: y.current, borderColor: gold, backgroundColor: gold, tension: .25 },
                    { label: y.previous_label, data: y.previous, borderColor: grey, backgroundColor: grey, borderDash: [5, 4], tension: .25 },
                ] },
                options: { scales: { y: { ticks: { callback: inrTick } } }, plugins: { tooltip: tooltipInr } },
            });
        } else { empty('chYoy'); }
    }).catch(() => { wrap.querySelectorAll('canvas').forEach((c) => empty(c.id)); });
})();
