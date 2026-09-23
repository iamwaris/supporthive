/**
 * Dashboard charts, spec §14/§15.
 *
 * Data arrives only via fetch() to the JSON endpoints named on the root
 * element's data-* attributes — never inline in the page — because the CSP's
 * script-src has no 'unsafe-inline' and baking JSON into a template is an
 * XSS vector even when a CSP does allow it.
 */
document.addEventListener('DOMContentLoaded', () => {
  const root = document.getElementById('dashboard-charts');
  if (!root || !window.ApexCharts) {
    return;
  }

  const emptyState = (el, message) => {
    el.innerHTML = '<p class="flex h-full items-center justify-center px-6 text-center text-xs text-slate-400">'
      + message + '</p>';
  };

  fetch(root.dataset.trendUrl)
    .then((r) => r.json())
    .then(({ series }) => {
      new ApexCharts(document.getElementById('chart-trend'), {
        chart: { type: 'bar', height: 260, toolbar: { show: false }, fontFamily: 'inherit' },
        series: [
          { name: 'Revenue', data: series.map((s) => Number(s.revenue)) },
          { name: 'Expenses', data: series.map((s) => Number(s.expense)) },
        ],
        xaxis: { categories: series.map((s) => s.label), labels: { style: { fontSize: '11px' } } },
        yaxis: { labels: { style: { fontSize: '11px' } } },
        colors: ['#047857', '#B91C1C'],
        dataLabels: { enabled: false },
        legend: { position: 'top', fontSize: '11px' },
        plotOptions: { bar: { columnWidth: '55%', borderRadius: 3 } },
      }).render();
    })
    .catch(() => emptyState(document.getElementById('chart-trend'), 'Could not load this chart.'));

  fetch(root.dataset.categoryUrl)
    .then((r) => r.json())
    .then(({ categories }) => {
      const el = document.getElementById('chart-category');
      if (categories.length === 0) {
        emptyState(el, 'No expenses recorded this month.');
        return;
      }
      new ApexCharts(el, {
        chart: { type: 'donut', height: 260, fontFamily: 'inherit' },
        series: categories.map((c) => Number(c.total)),
        labels: categories.map((c) => c.category_name),
        legend: { position: 'bottom', fontSize: '11px' },
        dataLabels: { enabled: false },
      }).render();
    })
    .catch(() => emptyState(document.getElementById('chart-category'), 'Could not load this chart.'));

  fetch(root.dataset.budgetUrl)
    .then((r) => r.json())
    .then(({ budgets }) => {
      const el = document.getElementById('chart-budget');
      if (budgets.length === 0) {
        emptyState(el, 'No budgets set for this month.');
        return;
      }
      new ApexCharts(el, {
        chart: { type: 'bar', height: 260, toolbar: { show: false }, fontFamily: 'inherit' },
        series: [
          { name: 'Budget', data: budgets.map((b) => Number(b.amount)) },
          { name: 'Actual', data: budgets.map((b) => Number(b.spent)) },
        ],
        xaxis: { categories: budgets.map((b) => b.category_name), labels: { style: { fontSize: '11px' } } },
        yaxis: { labels: { style: { fontSize: '11px' } } },
        colors: ['#475569', '#F59E0B'],
        dataLabels: { enabled: false },
        legend: { position: 'top', fontSize: '11px' },
        plotOptions: { bar: { columnWidth: '55%', borderRadius: 3 } },
      }).render();
    })
    .catch(() => emptyState(document.getElementById('chart-budget'), 'Could not load this chart.'));
});
