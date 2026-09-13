// FNCRB lightweight SVG chart engine v2 — dependency-free (CSP-safe, no CDN)
// Refinements: axis grids + tick values, % legends, hover emphasis,
// gradient fills, value badges, summary captions.
(function () {
  'use strict';

  var NS = 'http://www.w3.org/2000/svg';
  var COLORS = ['#1a66d6', '#2eaa6b', '#e0a63a', '#e04f44', '#5e6ad2', '#37a3f5', '#8b5cf6', '#64748b'];

  function el(tag, attrs) {
    var e = document.createElementNS(NS, tag);
    for (var k in attrs) e.setAttribute(k, attrs[k]);
    return e;
  }
  function fmt(n) {
    n = +n || 0;
    if (n >= 1e9) return (n / 1e9).toFixed(1) + 'B';
    if (n >= 1e6) return (n / 1e6).toFixed(1) + 'M';
    if (n >= 1e3) return (n / 1e3).toFixed(0) + 'K';
    return String(n);
  }
  function fmtFull(n) { return (+n || 0).toLocaleString('en-US'); }
  function pct(part, total) { return total ? Math.round(part / total * 100) + '%' : '0%'; }
  function clean(c) { while (c.firstChild) c.removeChild(c.firstChild); }
  function caption(c, html) {
    var d = document.createElement('div'); d.className = 'chart-caption'; d.innerHTML = html; c.appendChild(d);
  }
  function defs(svg, id) {
    var defs = el('defs', {});
    var grad = el('linearGradient', { id: id, x1: '0', y1: '0', x2: '0', y2: '1' });
    grad.appendChild(el('stop', { offset: '0%', 'stop-color': '#3f8ef7', 'stop-opacity': '0.35' }));
    grad.appendChild(el('stop', { offset: '100%', 'stop-color': '#3f8ef7', 'stop-opacity': '0.03' }));
    defs.appendChild(grad);
    svg.appendChild(defs);
  }

  // ---------- Donut: segments with gaps, hover emphasis, % legend, center KPI ----------
  function donut(container, data, opts) {
    opts = opts || {};
    clean(container);
    var W = opts.width || 240, H = W, cx = W / 2, cy = H / 2, R = W / 2 - 8, r = R * 0.64;
    var svg = el('svg', { viewBox: '0 0 ' + W + ' ' + H, class: 'chart-donut-svg' });
    var total = data.reduce(function (s, d) { return s + d.value; }, 0);

    if (!total) {
      var t0 = el('text', { x: cx, y: cy, 'text-anchor': 'middle', class: 'chart-empty' });
      t0.textContent = 'no data'; svg.appendChild(t0); container.appendChild(svg); return;
    }

    var gap = data.filter(function (d) { return d.value > 0; }).length > 1 ? 0.035 : 0; // rad gaps between segments
    var a = -Math.PI / 2;
    data.forEach(function (d, i) {
      var sweep = 2 * Math.PI * d.value / total;
      if (d.value <= 0) return;
      var a0 = a + gap / 2, a1 = a + sweep - gap / 2;
      if (a1 <= a0) a1 = a0 + 0.001;
      var large = (a1 - a0) > Math.PI ? 1 : 0;
      var p = ['M', cx + R * Math.cos(a0), cy + R * Math.sin(a0),
        'A', R, R, 0, large, 1, cx + R * Math.cos(a1), cy + R * Math.sin(a1),
        'L', cx + r * Math.cos(a1), cy + r * Math.sin(a1),
        'A', r, r, 0, large, 0, cx + r * Math.cos(a0), cy + r * Math.sin(a0), 'Z'].join(' ');
      var path = el('path', { d: p, fill: d.color || COLORS[i % COLORS.length], class: 'chart-seg' });
      var title = el('title');
      title.textContent = d.label + ': ' + fmtFull(d.value) + ' (' + pct(d.value, total) + ')';
      path.appendChild(title);
      svg.appendChild(path);
      // % label on segment when large enough
      if (d.value / total > 0.07) {
        var mid = (a0 + a1) / 2, lr = (R + r) / 2;
        var lbl = el('text', {
          x: cx + lr * Math.cos(mid), y: cy + lr * Math.sin(mid) + 3.5,
          'text-anchor': 'middle', class: 'chart-seg-label'
        });
        lbl.textContent = pct(d.value, total);
        svg.appendChild(lbl);
      }
      a += sweep;
    });

    var v = el('text', { x: cx, y: cy - 2, 'text-anchor': 'middle', class: 'chart-donut-value' });
    v.textContent = opts.centerValue !== undefined ? opts.centerValue : fmt(total);
    svg.appendChild(v);
    var s = el('text', { x: cx, y: cy + 16, 'text-anchor': 'middle', class: 'chart-donut-sub' });
    s.textContent = opts.centerLabel || '';
    svg.appendChild(s);
    container.appendChild(svg);

    var legend = document.createElement('div'); legend.className = 'chart-legend';
    data.forEach(function (d, i) {
      if (d.value <= 0) return;
      var item = document.createElement('span');
      item.className = 'chart-legend-item';
      item.innerHTML = '<i style="background:' + (d.color || COLORS[i % COLORS.length]) + '"></i>'
        + d.label + ' <b>' + (opts.legendValue ? opts.legendValue(d) : fmt(d.value)) + '</b>'
        + ' <em>' + pct(d.value, total) + '</em>';
      legend.appendChild(item);
    });
    container.appendChild(legend);
    if (opts.captionHtml) caption(container, opts.captionHtml);
  }

  // ---------- Vertical bars: y-axis grid + ticks, value badges, rounded caps ----------
  function vbars(container, data, opts) {
    opts = opts || {};
    clean(container);
    var W = 460, H = 210, padL = 42, padR = 14, padT = 16, padB = 34;
    var svg = el('svg', { viewBox: '0 0 ' + W + ' ' + H, class: 'chart-vbars-svg' });
    var max = Math.max.apply(null, data.map(function (d) { return d.value; }).concat([1]));
    var nice = niceMax(max);
    var n = data.length || 1;
    var slot = (W - padL - padR) / n;
    var bw = Math.min(52, slot - 10);

    // gridlines + y ticks (0..4)
    for (var g = 0; g <= 4; g++) {
      var y = H - padB - (H - padT - padB) * g / 4;
      svg.appendChild(el('line', { x1: padL, x2: W - padR, y1: y, y2: y, stroke: g ? '#e8ebf0' : '#c9ced6', 'stroke-width': g ? 1 : 1.5 }));
      var tick = el('text', { x: padL - 7, y: y + 4, 'text-anchor': 'end', class: 'chart-tick' });
      tick.textContent = fmt(nice * g / 4); svg.appendChild(tick);
    }

    data.forEach(function (d, i) {
      var x = padL + i * slot + (slot - bw) / 2;
      var h = (H - padT - padB) * d.value / nice;
      var rect = el('rect', {
        x: x, y: H - padB - Math.max(h, 2), width: bw, height: Math.max(h, 2), rx: 4,
        fill: d.color || opts.color || COLORS[i % COLORS.length], class: 'chart-bar'
      });
      var title = el('title'); title.textContent = d.label + ': ' + fmtFull(d.value);
      rect.appendChild(title); svg.appendChild(rect);
      var lbl = el('text', { x: x + bw / 2, y: H - padB + 16, 'text-anchor': 'middle', class: 'chart-tick-strong' });
      lbl.textContent = d.label; svg.appendChild(lbl);
      var val = el('text', { x: x + bw / 2, y: H - padB - Math.max(h, 2) - 6, 'text-anchor': 'middle', class: 'chart-tick-val' });
      val.textContent = fmt(d.value); svg.appendChild(val);
    });
    container.appendChild(svg);
    if (opts.captionHtml) caption(container, opts.captionHtml);
  }

  // ---------- Horizontal bars: % of total + value, hover ----------
  function hbars(container, data, opts) {
    opts = opts || {};
    clean(container);
    var total = data.reduce(function (s, d) { return s + d.value; }, 0);
    var max = Math.max.apply(null, data.map(function (d) { return d.value; }).concat([1]));
    var wrap = document.createElement('div'); wrap.className = 'chart-hbars';
    data.forEach(function (d, i) {
      var row = document.createElement('div'); row.className = 'chart-hbar-row';
      row.title = d.label + ': ' + fmtFull(d.value) + ' (' + pct(d.value, total) + ')';
      var fill = document.createElement('span');
      fill.className = 'chart-hbar-fill';
      fill.style.width = (d.value / max * 100) + '%';
      fill.style.background = d.color || opts.color || COLORS[i % COLORS.length];
      row.innerHTML = '<span class="chart-hbar-label" title="' + d.label + '">' + d.label + '</span>'
        + '<span class="chart-hbar-track"></span>'
        + '<span class="chart-hbar-value">' + (opts.raw ? fmtFull(d.value) : fmt(d.value))
        + '</span><span class="chart-hbar-pct">' + pct(d.value, total) + '</span>';
      row.querySelector('.chart-hbar-track').appendChild(fill);
      wrap.appendChild(row);
    });
    container.appendChild(wrap);
    if (opts.captionHtml) caption(container, opts.captionHtml);
  }

  // ---------- Line: smooth curve, gradient area, y ticks, end badge ----------
  function line(container, data, opts) {
    opts = opts || {};
    clean(container);
    var W = 460, H = 210, padL = 42, padR = 18, padT = 18, padB = 34;
    var svg = el('svg', { viewBox: '0 0 ' + W + ' ' + H, class: 'chart-line-svg' });
    defs(svg, 'fncrb-area-grad');
    var vals = data.map(function (d) { return d.value; });
    var max = Math.max.apply(null, vals.concat([1]));
    var min = Math.min.apply(null, vals.concat([0]));
    var nice = niceMax(max);
    var step = data.length > 1 ? (W - padL - padR) / (data.length - 1) : 0;
    var yOf = function (v) { return H - padB - (H - padT - padB) * ((v - 0) / (nice || 1)); };
    var pts = data.map(function (d, i) { return [padL + i * step, yOf(d.value)]; });

    for (var g = 0; g <= 4; g++) {
      var y = H - padB - (H - padT - padB) * g / 4;
      svg.appendChild(el('line', { x1: padL, x2: W - padR, y1: y, y2: y, stroke: g ? '#e8ebf0' : '#c9ced6', 'stroke-width': g ? 1 : 1.5 }));
      var tick = el('text', { x: padL - 7, y: y + 4, 'text-anchor': 'end', class: 'chart-tick' });
      tick.textContent = fmt(nice * g / 4); svg.appendChild(tick);
    }

    if (pts.length) {
      // smooth path (Catmull-Rom-ish)
      var dPath = 'M' + pts[0][0].toFixed(1) + ',' + pts[0][1].toFixed(1);
      for (var i = 1; i < pts.length; i++) {
        var p0 = pts[i - 1], p1 = pts[i];
        var mx = (p0[0] + p1[0]) / 2;
        dPath += ' C' + mx.toFixed(1) + ',' + p0[1].toFixed(1) + ' ' + mx.toFixed(1) + ',' + p1[1].toFixed(1) + ' ' + p1[0].toFixed(1) + ',' + p1[1].toFixed(1);
      }
      var area = dPath + ' L' + pts[pts.length - 1][0].toFixed(1) + ',' + (H - padB) + ' L' + pts[0][0].toFixed(1) + ',' + (H - padB) + ' Z';
      svg.appendChild(el('path', { d: area, fill: 'url(#fncrb-area-grad)' }));
      svg.appendChild(el('path', { d: dPath, fill: 'none', stroke: '#1a66d6', 'stroke-width': 2.5, 'stroke-linecap': 'round' }));

      pts.forEach(function (p, i) {
        var c = el('circle', { cx: p[0], cy: p[1], r: 3.5, fill: '#fff', stroke: '#1a66d6', 'stroke-width': 2, class: 'chart-pt' });
        var t = el('title'); t.textContent = data[i].label + ': ' + fmtFull(data[i].value);
        c.appendChild(t); svg.appendChild(c);
      });
      // end badge: last value
      var last = pts[pts.length - 1];
      var bx = Math.min(last[0] + 6, W - 34);
      var badge = el('g', { class: 'chart-badge' });
      badge.appendChild(el('rect', { x: bx - 4, y: Math.max(last[1] - 22, 2), width: 42, height: 17, rx: 8 }));
      var bt = el('text', { x: bx + 17, y: Math.max(last[1] - 10, 13), 'text-anchor': 'middle' });
      bt.textContent = fmt(data[data.length - 1].value);
      badge.appendChild(bt);
      svg.appendChild(badge);
    }
    data.forEach(function (d, i) {
      if (data.length > 7 && i % 2 && i !== data.length - 1) return;
      var lbl = el('text', { x: padL + i * step, y: H - padB + 16, 'text-anchor': 'middle', class: 'chart-tick-strong' });
      lbl.textContent = d.label; svg.appendChild(lbl);
    });
    container.appendChild(svg);
    if (opts.captionHtml) caption(container, opts.captionHtml);
  }

  function niceMax(v) {
    var mag = Math.pow(10, Math.floor(Math.log10(Math.max(v, 1))));
    return Math.ceil(v / (mag / 2)) * (mag / 2);
  }

  window.FNCRBCharts = { donut: donut, hbars: hbars, vbars: vbars, line: line, fmt: fmt, fmtFull: fmtFull, pct: pct };
})();
