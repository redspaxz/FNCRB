// Four-pillar analytics charts (renders from window.FNCRB_ANALYTICS)
(function () {
  'use strict';
  var C = window.FNCRBCharts;
  var A = window.FNCRB_ANALYTICS;
  if (!C || !A) return;

  function box(sel) { return document.querySelector('[data-chart="' + sel + '"]'); }
  var XAF = ' XAF';

  // 2. bureau ops: demand by institution + 30-day trend
  var inst = (A.ops.by_institution || {});
  if (box('ops-institutions')) C.hbars(box('ops-institutions'), Object.keys(inst).map(function (k) {
    return { label: k, value: inst[k] };
  }), { captionHtml: 'Credit inquiries filed per institution (demand ranking).' });

  var perDay = A.ops.last30_per_day || {};
  if (box('ops-trend')) C.line(box('ops-trend'), Object.keys(perDay).map(function (k) {
    return { label: k.slice(5), value: perDay[k] };
  }), { captionHtml: 'Daily inquiry volume — commercial activity of the bureau.' });

  // 3. system: API throughput 24h + audit heartbeat
  var api = A.health.api_per_hour || {};
  if (box('sys-api')) C.vbars(box('sys-api'), Object.keys(api).map(function (k) {
    return { label: k.slice(-2) + 'h', value: api[k], color: '#1a66d6' };
  }), { captionHtml: 'Signed API requests per hour over the last 24 hours.' });

  var aud = A.health.audit_events_per_day || {};
  if (box('sys-audit')) C.line(box('sys-audit'), Object.keys(aud).map(function (k) {
    return { label: k.slice(5), value: aud[k] };
  }), { captionHtml: 'Audit events per day — platform operational heartbeat.' });

  // 4. disputes by status
  var st = A.disputes.by_status || {};
  var S_COLORS = { OPEN: '#e0a63a', UNDER_REVIEW: '#37a3f5', CORRECTED: '#2eaa6b', REJECTED: '#e04f44', WITHDRAWN: '#64748b' };
  var S_NAMES = { OPEN: 'Open', UNDER_REVIEW: 'Under review', CORRECTED: 'Corrected', REJECTED: 'Rejected', WITHDRAWN: 'Withdrawn' };
  if (box('dsp-status')) C.donut(box('dsp-status'), Object.keys(st).map(function (k) {
    return { label: S_NAMES[k] || k, value: st[k], color: S_COLORS[k] || '#64748b' };
  }), {
    centerValue: String(A.disputes.total || 0),
    centerLabel: 'disputes',
    legendValue: function (s) { return String(s.value); },
    captionHtml: 'Statutory 30-day response window — ' + (A.disputes.over_sla || 0) + ' case(s) overdue.'
  });

  // data inventory rows
  var inv = A.health.data_inventory_rows || {};
  if (box('sys-inventory')) C.hbars(box('sys-inventory'), Object.keys(inv).map(function (k) {
    return { label: k.replace('_', ' '), value: inv[k], color: '#5e6ad2' };
  }), { raw: true, captionHtml: 'Registry data inventory (row counts).' });
})();
