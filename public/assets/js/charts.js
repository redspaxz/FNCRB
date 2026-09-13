// FNCRB lightweight SVG chart engine — dependency-free (CSP-safe, no CDN)
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

  function clean(container) {
    while (container.firstChild) container.removeChild(container.firstChild);
  }

  // Donut chart: data = [{label, value, color?}]
  function donut(container, data, opts) {
    opts = opts || {};
    clean(container);
    var W = opts.width || 220, H = W, R = W / 2 - 6, r = R * 0.62;
    var svg = el('svg', { viewBox: '0 0 ' + W + ' ' + H, class: 'chart-donut' });
    var total = data.reduce(function (s, d) { return s + d.value; }, 0);
    if (!total) {
      svg.appendChild(el('text', { x: W / 2, y: H / 2, 'text-anchor': 'middle', class: 'chart-empty' }))
        .textContent = 'no data';
      container.appendChild(svg); return;
    }
    var a0 = -Math.PI / 2;
    data.forEach(function (d, i) {
      var a1 = a0 + 2 * Math.PI * d.value / total;
      var large = (a1 - a0) > Math.PI ? 1 : 0;
      var p = [
        'M', W / 2 + R * Math.cos(a0), H / 2 + R * Math.sin(a0),
        'A', R, R, 0, large, 1, W / 2 + R * Math.cos(a1), H / 2 + R * Math.sin(a1),
        'L', W / 2 + r * Math.cos(a1), H / 2 + r * Math.sin(a1),
        'A', r, r, 0, large, 0, W / 2 + r * Math.cos(a0), H / 2 + r * Math.sin(a0), 'Z'
      ].join(' ');
      var path = el('path', { d: p, fill: d.color || COLORS[i % COLORS.length] });
      var title = el('title'); title.textContent = d.label + ': ' + fmtFull(d.value);
      path.appendChild(title);
      svg.appendChild(path);
      a0 = a1;
    });
    var center = el('text', { x: W / 2, y: H / 2 - 4, 'text-anchor': 'middle', class: 'chart-donut-value' });
    center.textContent = opts.centerValue !== undefined ? opts.centerValue : fmt(total);
    svg.appendChild(center);
    var sub = el('text', { x: W / 2, y: H / 2 + 14, 'text-anchor': 'middle', class: 'chart-donut-sub' });
    sub.textContent = opts.centerLabel || '';
    svg.appendChild(sub);
    container.appendChild(svg);

    var legend = document.createElement('div'); legend.className = 'chart-legend';
    data.forEach(function (d, i) {
      var item = document.createElement('span');
      item.innerHTML = '<i style="background:' + (d.color || COLORS[i % COLORS.length]) + '"></i>'
        + d.label + ' <b>' + fmt(d.value) + '</b>';
      legend.appendChild(item);
    });
    container.appendChild(legend);
  }

  // Horizontal bar chart: data = [{label, value}]
  function hbars(container, data, opts) {
    opts = opts || {};
    clean(container);
    var max = Math.max.apply(null, data.map(function (d) { return d.value; }).concat([1]));
    var wrap = document.createElement('div'); wrap.className = 'chart-hbars';
    data.forEach(function (d, i) {
      var row = document.createElement('div'); row.className = 'chart-hbar-row';
      row.innerHTML = '<span class="chart-hbar-label" title="' + d.label + '">' + d.label + '</span>'
        + '<span class="chart-hbar-track"><span class="chart-hbar-fill" style="width:'
        + (d.value / max * 100) + '%;background:' + (opts.color || COLORS[i % COLORS.length])
        + '"></span></span>'
        + '<span class="chart-hbar-value">' + (opts.raw ? fmtFull(d.value) : fmt(d.value)) + '</span>';
      wrap.appendChild(row);
    });
    container.appendChild(wrap);
  }

  // Vertical bar chart: data = [{label, value}]
  function vbars(container, data, opts) {
    opts = opts || {};
    clean(container);
    var W = opts.width || 460, H = 180, pad = 26, bw = Math.min(46, (W - pad * 2) / Math.max(data.length, 1) - 8);
    var svg = el('svg', { viewBox: '0 0 ' + W + ' ' + H, class: 'chart-vbars', preserveAspectRatio: 'xMidYMid meet' });
    var max = Math.max.apply(null, data.map(function (d) { return d.value; }).concat([1]));
    data.forEach(function (d, i) {
      var x = pad + i * ((W - pad * 2) / Math.max(data.length, 1));
      var h = (H - pad * 2) * d.value / max;
      var rect = el('rect', {
        x: x, y: H - pad - h, width: bw, height: Math.max(h, 1), rx: 3,
        fill: opts.color || COLORS[i % COLORS.length]
      });
      var title = el('title'); title.textContent = d.label + ': ' + fmtFull(d.value);
      rect.appendChild(title); svg.appendChild(rect);
      var lbl = el('text', { x: x + bw / 2, y: H - pad + 13, 'text-anchor': 'middle', class: 'chart-tick' });
      lbl.textContent = d.label; svg.appendChild(lbl);
      var val = el('text', { x: x + bw / 2, y: H - pad - h - 5, 'text-anchor': 'middle', class: 'chart-tick-val' });
      val.textContent = fmt(d.value); svg.appendChild(val);
    });
    container.appendChild(svg);
  }

  // Line chart: data = [{label, value}]
  function line(container, data, opts) {
    opts = opts || {};
    clean(container);
    var W = opts.width || 460, H = 180, pad = 30;
    var svg = el('svg', { viewBox: '0 0 ' + W + ' ' + H, class: 'chart-line', preserveAspectRatio: 'xMidYMid meet' });
    var max = Math.max.apply(null, data.map(function (d) { return d.value; }).concat([1]));
    var min = Math.min.apply(null, data.map(function (d) { return d.value; }).concat([0]));
    var step = data.length > 1 ? (W - pad * 2) / (data.length - 1) : 0;
    var pts = data.map(function (d, i) {
      return [pad + i * step, H - pad - (H - pad * 2) * ((d.value - min) / (max - min || 1))];
    });
    // grid
    [0, 0.5, 1].forEach(function (f) {
      svg.appendChild(el('line', {
        x1: pad, x2: W - pad, y1: H - pad - (H - pad * 2) * f, y2: H - pad - (H - pad * 2) * f,
        stroke: '#e0e3e8', 'stroke-dasharray': '3 3'
      }));
    });
    if (pts.length) {
      var dAttr = pts.map(function (p, i) { return (i ? 'L' : 'M') + p[0].toFixed(1) + ' ' + p[1].toFixed(1); }).join(' ');
      var area = dAttr + ' L ' + pts[pts.length - 1][0].toFixed(1) + ' ' + (H - pad) + ' L ' + pts[0][0].toFixed(1) + ' ' + (H - pad) + ' Z';
      svg.appendChild(el('path', { d: area, fill: 'rgba(26,102,214,.12)' }));
      svg.appendChild(el('path', { d: dAttr, fill: 'none', stroke: '#1a66d6', 'stroke-width': 2.5 }));
      pts.forEach(function (p, i) {
        var c = el('circle', { cx: p[0], cy: p[1], r: 3.5, fill: '#fff', stroke: '#1a66d6', 'stroke-width': 2 });
        var t = el('title'); t.textContent = data[i].label + ': ' + fmtFull(data[i].value);
        c.appendChild(t); svg.appendChild(c);
      });
    }
    data.forEach(function (d, i) {
      if (data.length > 8 && i % 2) return;
      var lbl = el('text', { x: pad + i * step, y: H - pad + 13, 'text-anchor': 'middle', class: 'chart-tick' });
      lbl.textContent = d.label; svg.appendChild(lbl);
    });
    container.appendChild(svg);
  }

  window.FNCRBCharts = { donut: donut, hbars: hbars, vbars: vbars, line: line, fmt: fmt, fmtFull: fmtFull };
})();
