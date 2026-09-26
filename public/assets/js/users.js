// User management actions (lock/unlock, admin password reset, 2FA reset)
(function () {
  'use strict';
  var csrf = document.querySelector('meta[name="csrf-token"]');
  var base = document.querySelector('meta[name="base-url"]');

  async function post(path, id) {
    var res = await fetch(base.content + path, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-Token': csrf.content },
      body: JSON.stringify({ id: id })
    });
    var data = {};
    try { data = await res.json(); } catch (e) { /* non-JSON */ }
    var err = data.error ? (data.error.message || data.error) : null;
    return { ok: res.ok, data: data, error: err || ('HTTP ' + res.status) };
  }

  document.querySelectorAll('[data-toggle-user]').forEach(function (btn) {
    btn.addEventListener('click', async function () {
      if (!confirm('Lock/unlock this user account? Locking signs the user out immediately.')) return;
      var r = await post('/users/toggle', parseInt(this.dataset.toggleUser, 10));
      if (r.ok) {
        var row = this.closest('[data-user-row]');
        row.querySelector('[data-status]').textContent = r.data.status;
        this.textContent = r.data.status === 'ACTIVE' ? 'Lock' : 'Unlock';
      } else { alert('Error: ' + r.error); }
    });
  });

  document.querySelectorAll('[data-reset-user]').forEach(function (btn) {
    btn.addEventListener('click', async function () {
      if (!confirm('Generate a new one-time password for this user? Their current sessions end.')) return;
      var r = await post('/users/reset-password', parseInt(this.dataset.resetUser, 10));
      if (r.ok) {
        alert('New one-time password for this user:\n\n' + r.data.password +
              '\n\nShare it securely; it will not be shown again. The user must change it at sign-in.');
      } else { alert('Error: ' + r.error); }
    });
  });

  document.querySelectorAll('[data-reset-mfa]').forEach(function (btn) {
    btn.addEventListener('click', async function () {
      if (!confirm('Remove this user\'s 2FA enrolment (e.g. lost device)? They must re-enrol.')) return;
      var r = await post('/users/reset-2fa', parseInt(this.dataset.resetMfa, 10));
      if (r.ok) {
        this.closest('[data-user-row]').querySelector('[data-mfa]').textContent = '—';
        this.remove();
      } else { alert('Error: ' + r.error); }
    });
  });
})();
