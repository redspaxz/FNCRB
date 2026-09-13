// Dashboard KPI charts — fetch analytics feed and render refined SVG charts
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
  var CLASS_ORDER = ['HEALTHY', 'WATCH', 'UNCERTAIN', 'DOUBTFUL', 'COMPROMISED'];
  var XAF = ' XAF';

  function render(d) {
    var box = function (id) { return document.getElementById(id); };

    // Donut: loans per COBAC class; legend shows outstanding amount per class
    var cnt = d.portfolio_by_class.count || {};
    var amt = d.portfolio_by_class.outstanding_xaf || {};
    var totalLoans = CLASS_ORDER.reduce(function (s, k) { return s + (cnt[k] || 0); }, 0);
    var totalOut = CLASS_ORDER.reduce(function (s, k) { return s + (+amt[k] || 0); }, 0);
    C.donut(box('chart-class'), CLASS_ORDER.map(function (k) {
      return { label: k.charAt(0) + k.slice(1).toLowerCase(), value: cnt[k] || 0, color: CLASS_COLORS[k] };
    }), {
      centerValue: String(totalLoans),
      centerLabel: 'active loans',
      legendValue: function (seg) {
        var k = seg.label.toUpperCase();
        return C.fmt(+amt[k] || 0) + XAF;
      },
      captionHtml: 'Outstanding total <b>' + C.fmt(totalOut) + XAF + '</b> across ' + totalLoans + ' loan(s).'
    });

    // Vertical bars: arrears buckets
    var arr = d.arrears_distribution || {};
    var inArr = Object.keys(arr).reduce(function (s, k) { return s + (k === 'current' ? 0 : arr[k]); }, 0);
    C.vbars(box('chart-arrears'), [
      { label: 'current', value: arr.current || 0, color: '#2eaa6b' },
      { label: '1-30d', value: arr['1-30'] || 0, color: '#37a3f5' },
      { label: '31-90d', value: arr['31-90'] || 0, color: '#e0a63a' },
      { label: '91-180d', value: arr['91-180'] || 0, color: '#e04f44' },
      { label: '180+d', value: arr['180+'] || 0, color: '#8b0000' }
    ], {
      captionHtml: inArr + ' loan(s) in arrears — ' + C.pct(inArr, Object.keys(arr).reduce(function (s, k) { return s + arr[k]; }, 0)) + ' of the active portfolio.'
    });

    // Donut: incidents by type; legend shows raw counts
    var inc = d.incidents_by_type || {};
    var totalInc = Object.keys(inc).reduce(function (s, k) { return s + inc[k]; }, 0);
    var NAMES = {
      BOUNCED_CHEQUE: 'Bounced cheque', DEFAULTED_NOTE: 'Defaulted note',
      UNAUTHORIZED_OVERDRAFT: 'Unauthorized OD', FRAUD_INSTRUMENT: 'Fraud instrument'
    };
    C.donut(box('chart-incidents'), Object.keys(inc).map(function (k) {
      return { label: NAMES[k] || k, value: inc[k] };
    }), {
      centerValue: String(totalInc),
      centerLabel: 'incidents',
      legendValue: function (s) { return String(s.value); },
      captionHtml: 'CIP log — ' + totalInc + ' incident(s) on record.'
    });

    // Horizontal bars: exposure by institution, share of total
    var inst = d.exposure_by_institution_xaf || {};
    C.hbars(box('chart-institutions'), Object.keys(inst).map(function (k) {
      return { label: k, value: +inst[k] || 0 };
    }), {
      captionHtml: 'Top institutions by outstanding balance (XAF).'
    });

    // Line: reporting trend
    var tr = (d.reporting_trend || {}).loans || {};
    var keys = Object.keys(tr);
    var totalRep = keys.reduce(function (s, k) { return s + tr[k]; }, 0);
    C.line(box('chart-trend'), keys.map(function (k) {
      return { label: k.slice(2), value: tr[k] };
    }), {
      captionHtml: totalRep + ' loan record(s) reported over the last ' + Math.min(keys.length, 12) + ' period(s).'
    });
  }
})();
