/*
 * Template editor behaviour: parameter dialog (add / edit), type-dependent
 * fields, library defaults, layout-dependent header fields, list filters.
 */

/* ---------- header: "planned inspections per shift" only for SHIFT_GRID ------ */
function syncLayoutFields() {
  const select = document.querySelector('[data-layout-select]');
  const form = document.querySelector('form[data-layout]');
  const layout = select ? select.selectedOptions[0]?.dataset.layout : form?.dataset.layout;
  document.querySelectorAll('[data-grid-only]').forEach((el) => {
    el.hidden = layout !== 'SHIFT_GRID';
  });
}
document.querySelector('[data-layout-select]')?.addEventListener('change', syncLayoutFields);
syncLayoutFields();

/* ---------- mapping list filters --------------------------------------------- */
document.querySelectorAll('[data-filter-list]').forEach((input) => {
  const list = document.querySelector(input.dataset.filterList);
  input.addEventListener('input', () => {
    const q = input.value.trim().toLowerCase();
    list?.querySelectorAll('[data-filter-text]').forEach((row) => {
      row.hidden = q !== '' && !row.dataset.filterText.includes(q);
    });
  });
});

/* ---------- parameter dialog -------------------------------------------------- */
const modalEl = document.getElementById('paramModal');
if (modalEl && window.bootstrap) {
  const modal = new window.bootstrap.Modal(modalEl);
  const form = document.getElementById('paramForm');
  const field = (name) => form.elements.namedItem(name);
  const typeSelect = field('observation_type');
  let editing = false;

  const syncType = () => {
    const type = typeSelect.value;
    form.querySelectorAll('[data-for-types]').forEach((el) => {
      el.hidden = !el.dataset.forTypes.split(' ').includes(type);
    });
  };
  typeSelect.addEventListener('change', syncType);

  const setValue = (name, value) => {
    const el = field(name);
    if (!el) return;
    if (el.type === 'checkbox') {
      el.checked = String(value) === '1';
    } else {
      el.value = value === null || value === undefined ? '' : String(value);
    }
  };

  const trimDecimal = (value, places) => {
    if (value === null || value === undefined || value === '') return '';
    const [int, frac = ''] = String(value).split('.');
    return places > 0 ? `${int}.${frac.padEnd(places, '0').slice(0, places)}` : int;
  };

  const open = (data) => {
    form.reset();
    editing = Boolean(data.id);
    document.getElementById('paramModalTitle').textContent = editing ? 'Edit parameter' : 'Add parameter';
    const places = Number(data.decimal_places ?? 2);
    setValue('id', data.id ?? 0);
    setValue('section_id', data.section_id);
    setValue('parameter_id', data.parameter_id ?? '');
    setValue('name', data.name ?? '');
    setValue('specification_text', data.specification_text ?? '');
    setValue('observation_type', data.observation_type ?? 'NUMERIC');
    setValue('unit_id', data.unit_id ?? '');
    setValue('decimal_places', places);
    setValue('lsl', trimDecimal(data.lsl, places));
    setValue('nominal', trimDecimal(data.nominal, places));
    setValue('usl', trimDecimal(data.usl, places));
    setValue('date_rule', data.date_rule ?? 'NONE');
    setValue('inspection_method_id', data.inspection_method_id ?? '');
    setValue('gauge_type_id', data.gauge_type_id ?? '');
    setValue('observation_count', data.observation_count ?? 2);
    setValue('prefill_source', data.prefill_source ?? 'NONE');
    setValue('gauge_required', data.gauge_required ?? 0);
    setValue('is_mandatory', data.is_mandatory ?? 1);
    setValue('help_text', data.help_text ?? '');
    syncType();
    modal.show();
  };

  document.querySelectorAll('[data-add-param]').forEach((button) => {
    button.addEventListener('click', () => open({ section_id: button.dataset.addParam }));
  });
  document.querySelectorAll('[data-edit-param]').forEach((button) => {
    button.addEventListener('click', () => open(JSON.parse(button.closest('[data-param]').dataset.param)));
  });

  // Library defaults when adding
  field('parameter_id').addEventListener('change', (event) => {
    if (editing) return;
    const option = event.target.selectedOptions[0];
    if (!option?.dataset.defaults) return;
    const lib = JSON.parse(option.dataset.defaults);
    setValue('name', lib.name);
    setValue('observation_type', lib.default_observation_type);
    setValue('unit_id', lib.default_unit_id ?? '');
    setValue('inspection_method_id', lib.default_method_id ?? '');
    setValue('gauge_type_id', lib.default_gauge_type_id ?? '');
    field('gauge_required').checked = Boolean(lib.default_gauge_type_id);
    syncType();
  });

  // Re-open the dialog after a validation error on save
  if (document.querySelector('.alert-danger') && sessionStorage.getItem('qms:paramDraft')) {
    try { open(JSON.parse(sessionStorage.getItem('qms:paramDraft'))); } catch { /* ignore */ }
  }
  form.addEventListener('submit', () => {
    const data = Object.fromEntries(new FormData(form).entries());
    data.gauge_required = field('gauge_required').checked ? 1 : 0;
    data.is_mandatory = field('is_mandatory').checked ? 1 : 0;
    delete data.csrf_qms;
    try { sessionStorage.setItem('qms:paramDraft', JSON.stringify(data)); } catch { /* ignore */ }
  });
  if (!document.querySelector('.alert-danger')) {
    try { sessionStorage.removeItem('qms:paramDraft'); } catch { /* ignore */ }
  }
}
