// User management actions (lock/unlock, admin password reset)
(function () {
  'use strict';
  var csrf = document.querySelector('meta[name="csrf-token"]');
  var base = document.querySelector('meta[name="base-url"]');

  document.querySelectorAll('[data-toggle-user]').forEach(function (btn) {
    btn.addEventListener('click', async function () {
      if (!confirm('Lock/unlock this user account?')) return;
      var res = await fetch(base.content + '/users/toggle', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf.content },
        body: JSON.stringify({ id: parseInt(this.dataset.toggleUser, 10) })
      });
      var data = await res.json();
      if (res.ok) {
        var row = document.querySelector('[data-user-row][data-id="' + data.id + '"]') ||
                  this.closest('[data-user-row]');
        row.querySelector('[data-status]').textContent = data.status;
        this.textContent = data.status === 'ACTIVE' ? 'Lock' : 'Unlock';
      } else { alert('Error: ' + (data.error || res.status)); }
    });
  });

  document.querySelectorAll('[data-reset-user]').forEach(function (btn) {
    btn.addEventListener('click', async function () {
      if (!confirm('Generate a new one-time password for this user?')) return;
      var res = await fetch(base.content + '/users/reset-password', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf.content },
        body: JSON.stringify({ id: parseInt(this.dataset.resetUser, 10) })
      });
      var data = await res.json();
      if (res.ok) {
        alert('New one-time password for this user:\n\n' + data.password +
              '\n\nShare it securely; it will not be shown again.');
      } else { alert('Error: ' + (data.error || res.status)); }
    });
  });
})();
