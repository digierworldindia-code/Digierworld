/*
 * New inspection: part search filter, machine list narrowed to machines with a
 * published template (GET inspections/options), shift block per report type.
 */
const form = document.getElementById('newInspection');
if (form) {
  const typeInputs = [...form.querySelectorAll('[name="report_type_id"]')];
  const part = form.querySelector('#part_id');
  const partFilter = form.querySelector('#partFilter');
  const machine = form.querySelector('#machine_id');
  const hint = form.querySelector('#machineHint');
  const shiftBlock = form.querySelector('#shiftBlock');
  const gridHint = form.querySelector('#gridHint');
  const allParts = [...part.options].slice(1).map((o) => ({ value: o.value, text: o.textContent, selected: o.selected }));
  let request = 0;

  const selectedType = () => typeInputs.find((i) => i.checked) || null;

  function applyType() {
    const type = selectedType();
    const hidden = !type || type.dataset.shift === 'HIDDEN';
    shiftBlock.querySelectorAll('input').forEach((i) => { i.disabled = hidden; });
    shiftBlock.querySelector('.qms-choice').hidden = hidden;
    shiftBlock.querySelector('#shiftLabel').hidden = hidden;
    gridHint.hidden = !(type && type.dataset.layout === 'SHIFT_GRID');
    shiftBlock.querySelectorAll('input').forEach((i) => { i.required = Boolean(type && type.dataset.shift === 'REQUIRED'); });
  }

  partFilter.addEventListener('input', () => {
    const q = partFilter.value.trim().toLowerCase();
    const current = part.value;
    part.replaceChildren(part.options[0] || new Option('Choose the part', ''));
    const matches = allParts.filter((p) => q === '' || p.text.toLowerCase().includes(q));
    matches.forEach((p) => part.add(new Option(p.text, p.value, false, p.value === current)));
    if (matches.length === 1) {
      part.value = matches[0].value;
      loadMachines();
    }
  });

  async function loadMachines() {
    const type = selectedType();
    if (!type || !part.value) return;
    const mine = ++request;
    hint.textContent = 'Loading machines…';
    try {
      const url = `${form.dataset.optionsUrl}?type=${encodeURIComponent(type.value)}&part=${encodeURIComponent(part.value)}`;
      const response = await fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } });
      const body = await response.json();
      if (mine !== request) return;
      if (!response.ok) throw new Error(body?.error || 'Could not load machines.');
      const previous = machine.value;
      machine.replaceChildren(new Option('Choose the machine', ''));
      body.machines.forEach((m) => {
        const label = `${m.code} · ${m.name}${m.ambiguous ? ' (several templates – ask QA)' : ''}`;
        const option = new Option(label, m.id, false, String(m.id) === previous);
        option.disabled = Boolean(m.ambiguous);
        machine.add(option);
      });
      if (body.machines.length === 1 && !body.machines[0].ambiguous) machine.value = String(body.machines[0].id);
      hint.textContent = body.machines.length === 0
        ? 'No published template applies to this part for the chosen report type. Ask the QA Admin to map one.'
        : `${body.machines.length} machine(s) with an inspection template for this part.`;
      hint.classList.toggle('text-danger', body.machines.length === 0);
    } catch (error) {
      if (mine === request) {
        hint.textContent = `${error.message} All machines are listed; the server checks the template when you start.`;
      }
    }
  }

  typeInputs.forEach((i) => i.addEventListener('change', () => { applyType(); loadMachines(); }));
  part.addEventListener('change', loadMachines);
  applyType();
  loadMachines();
}
