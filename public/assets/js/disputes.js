// Dispute workflow actions (review / correct / reject, details toggle)
(function () {
  'use strict';
  var csrf = document.querySelector('meta[name="csrf-token"]');
  var base = document.querySelector('meta[name="base-url"]');

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
        var note = prompt('Resolution note (required):');
        if (!note || note.trim().length < 5) return;
        payload.note = note.trim();
      }
      if (action === 'CORRECTED') {
        var entity = prompt('Correction — entity (loans or borrowers):', 'loans');
        if (!entity) return;
        var entityId = prompt('Correction — record id:', '');
        if (!entityId) return;
        var field = prompt('Correction — field (e.g. outstanding_xaf, days_past_due, status, full_name):', '');
        if (!field) return;
        var newValue = prompt('Correction — new value:', '');
        if (newValue === null || newValue === '') return;
        payload.correction = { entity: entity, entity_id: parseInt(entityId, 10) || entityId, field: field, new_value: newValue };
        payload.note = 'Data corrected per dispute.';
      }
      if (action === 'UNDER_REVIEW') payload.note = 'Under review by bureau staff.';

      try {
        var res = await fetch(base.content + '/disputes/transition', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf.content },
          body: JSON.stringify(payload)
        });
        var data = await res.json();
        if (res.ok) location.reload();
        else alert('Error: ' + (data.error ? data.error.message : res.status));
      } catch (err) { alert('Request failed: ' + err); }
    });
  });
})();
