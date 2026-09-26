// Dispute workflow actions (review / correct / reject, details toggle)
(function () {
  'use strict';
  var csrf = document.querySelector('meta[name="csrf-token"]');
  var base = document.querySelector('meta[name="base-url"]');
  var FIELDS = {
    loans: ['outstanding_xaf', 'days_past_due', 'status', 'monthly_payment_xaf'],
    borrowers: ['full_name', 'phone', 'region', 'date_of_birth'],
    payment_incidents: ['resolved']
  };

  document.querySelectorAll('[data-dsp-details]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var row = document.querySelector('[data-dsp-detail-row="' + this.dataset.dspDetails + '"]');
      if (row) row.classList.toggle('d-none');
    });
  });

  document.querySelectorAll('[data-dsp-action]').forEach(function (btn) {
    btn.addEventListener('click', async function () {
      var action = this.dataset.dspAction;
      var id = parseInt(this.dataset.dspId, 10);
      var payload = { id: id, status: action };

      if (action === 'REJECTED' || action === 'WITHDRAWN') {
        var note = prompt('Resolution note (required, min 5 characters):');
        if (!note || note.trim().length < 5) return;
        payload.note = note.trim();
      }
      if (action === 'CORRECTED') {
        var entity = prompt('Correction — record type (loans, borrowers or payment_incidents):', 'loans');
        if (!entity || !FIELDS[entity]) { if (entity) alert('Unknown record type.'); return; }
        var loanId = parseInt(this.dataset.dspLoan || '0', 10);
        var entityId = prompt('Correction — record id' + (entity === 'loans' && loanId ? ' (disputed loan #' + loanId + ')' : '') + ':',
          entity === 'loans' && loanId ? String(loanId) : '');
        if (!entityId) return;
        var field = prompt('Correction — field (' + FIELDS[entity].join(', ') + '):', FIELDS[entity][0]);
        if (!field || FIELDS[entity].indexOf(field) < 0) { if (field) alert('Field not correctable.'); return; }
        var newValue = prompt('Correction — new value:', '');
        if (newValue === null || newValue === '') return;
        var why = prompt('Resolution note (evidence relied on):', 'Data corrected per dispute.');
        if (!why || why.trim().length < 5) return;
        payload.correction = { entity: entity, entity_id: parseInt(entityId, 10), field: field, new_value: newValue };
        payload.note = why.trim();
      }
      if (action === 'UNDER_REVIEW') payload.note = 'Under review by bureau staff.';

      try {
        var res = await fetch(base.content + '/disputes/transition', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-Token': csrf.content },
          body: JSON.stringify(payload)
        });
        var data = await res.json();
        if (res.ok) location.reload();
        else alert('Error: ' + (data.error ? (data.error.message || data.error) : res.status));
      } catch (err) { alert('Request failed: ' + err); }
    });
  });
})();
