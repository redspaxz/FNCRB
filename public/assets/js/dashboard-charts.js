// Dashboard KPI charts — fetch analytics feed and render SVG charts
(function () {
  'use strict';
  var base = (document.querySelector('meta[name="base-url"]') || {}).content || '';
  var C = window.FNCRBCharts;
  if (!C) return;

  fetch(base + '/dashboard/analytics', { headers: { 'Accept': 'application/json' } })
    .then(function (r) { return r.ok ? r.json() : Promise.reject(r.status); })
    .then(function (d) { render(d); })
    .catch(function () {
      document.querySelectorAll('.chart-box').forEach(function (b) {
        b.textContent = 'Analytics unavailable';
      });
    });

  var CLASS_COLORS = {
    HEALTHY: '#2eaa6b', WATCH: '#37a3f5', UNCERTAIN: '#e0a63a',
    DOUBTFUL: '#e04f44', COMPROMISED: '#8b0000'
  };

  function render(d) {
    var box = function (id) { return document.getElementById(id); };

    // donut: loan count per COBAC class
    var byClass = d.portfolio_by_class.count || {};
    C.donut(box('chart-class'), Object.keys(byClass).map(function (k) {
      return { label: k, value: byClass[k], color: CLASS_COLORS[k] };
    }), { centerLabel: 'active loans' });

    // vertical bars: arrears buckets
    var arr = d.arrears_distribution || {};
    var arrLabels = { current: 'current', '1-30': '1-30d', '31-90': '31-90d', '91-180': '91-180d', '180+': '180+d' };
    C.vbars(box('chart-arrears'), Object.keys(arrLabels).map(function (k) {
      return { label: arrLabels[k], value: arr[k] || 0 };
    }), { color: '#e0a63a' });

    // donut: incidents by type
    var inc = d.incidents_by_type || {};
    C.donut(box('chart-incidents'), Object.keys(inc).map(function (k) {
      var short = k.replace('BOUNCED_CHEQUE', 'Bounced chq').replace('DEFAULTED_NOTE', 'Defaulted note')
                   .replace('UNAUTHORIZED_OVERDRAFT', 'Unauth. OD').replace('FRAUD_INSTRUMENT', 'Fraud');
      return { label: short, value: inc[k] };
    }), { centerLabel: 'incidents' });

    // horizontal bars: exposure by institution
    var inst = d.exposure_by_institution_xaf || {};
    C.hbars(box('chart-institutions'), Object.keys(inst).map(function (k) {
      return { label: k, value: inst[k] };
    }));

    // line: reporting trend
    var tr = (d.reporting_trend || {}).loans || {};
    C.line(box('chart-trend'), Object.keys(tr).map(function (k) {
      return { label: k.slice(2), value: tr[k] };
    }));
  }
})();
