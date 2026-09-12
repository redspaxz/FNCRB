// FNCRB client behaviors (external file — CSP-safe, no inline scripts)
(function () {
  'use strict';

  function meta(name) {
    var el = document.querySelector('meta[name="' + name + '"]');
    return el ? el.content : '';
  }

  // toolbar session clock
  var clock = document.getElementById('applet-clock') || document.getElementById('vaadin-clock');
  if (clock) {
    setInterval(function () { clock.textContent = new Date().toTimeString().slice(0, 8); }, 1000);
  }

  // compliance: run COBAC classification & provisioning
  var btn = document.getElementById('btn-reclassify');
  if (btn) {
    btn.addEventListener('click', async function () {
      var b = this; b.disabled = true;
      var base = meta('base-url');
      var csrf = meta('csrf-token');
      try {
        var res = await fetch(base + '/compliance/reclassify', {
          method: 'POST',
          headers: { 'X-CSRF-Token': csrf }
        });
        var data = await res.json();
        if (res.ok) {
          alert('Reclassified ' + data.loans_reclassified + ' loan(s). COBAC classes and provisions updated.');
          location.reload();
        } else {
          alert('Error: ' + (data.error || res.status));
        }
      } catch (e) { alert('Request failed: ' + e); }
      b.disabled = false;
    });
  }
  // login: enable the submit button only after accepting the terms
  var terms = document.getElementById('termsAccepted');
  var loginBtn = document.getElementById('loginBtn');
  if (terms && loginBtn) {
    var sync = function () { loginBtn.disabled = !terms.checked; };
    terms.addEventListener('change', sync);
    sync();
  }
})();
