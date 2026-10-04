/*
 * Inspection entry screen (tablet first).
 *
 * - Live PASS / FAIL against the LSL / USL snapshot (exact decimal comparison,
 *   same rules as the server's SpecEvaluator). Only the server's verdict is stored.
 * - Autosave of changed values only; edits made offline are kept in
 *   localStorage and sent when the network returns.
 * - Stepper (Section 2 of 5) with Previous / Next for single-round reports.
 * - Submit flushes pending saves first and carries an Idempotency-Key, so a
 *   double tap or a retry after a network drop never submits twice.
 */
const { post, uuid, HttpError } = window.QMS;

const root = document.getElementById('inspectionForm');
if (root) {
  init(root);
}

function init(root) {
  const reportId = root.dataset.report;
  const storageKey = `qms-draft-${reportId}`;
  const inspectionDate = root.dataset.inspectionDate;
  const editable = root.dataset.status === 'DRAFT' || root.dataset.status === 'RETURNED';
  const autosaveMs = Math.max(5, Number(root.dataset.autosave || 20)) * 1000;

  const state = {
    lock: Number(root.dataset.lock),
    cells: new Map(), // "obs:n" -> value
    obs: new Map(), // obsId -> { gauge_id?, remarks? }
    rounds: new Map(), // roundId -> { field: value }
    header: {},
    inflight: null,
    inflightPayload: null,
    again: false,
    timer: null,
    stopped: false,
  };

  const saveStateEl = root.querySelector('[data-save-state]');
  const offlineBanner = root.querySelector('[data-offline-banner]');
  const conflictBanner = root.querySelector('[data-conflict-banner]');
  const submitBtn = root.querySelector('[data-submit]');

  /* ---------------------------------------------------------- evaluation */
  const DEC = /^[+-]?\d{1,12}(\.\d{1,6})?$/;
  const scaled = (v) => {
    const negative = v.startsWith('-');
    const [int, frac = ''] = v.replace(/^[+-]/, '').split('.');
    const n = BigInt(int) * 1000000n + BigInt((frac + '000000').slice(0, 6));
    return negative ? -n : n;
  };
  const decimals = (v) => (v.includes('.') ? v.length - v.indexOf('.') - 1 : 0);

  function evaluate(el, raw) {
    const value = (raw ?? '').trim();
    if (value === '') return { result: null, error: null };
    switch (el.dataset.type) {
      case 'NUMERIC':
      case 'PERCENTAGE': {
        const v = value.includes('.') ? value : value.replace(',', '.');
        if (!DEC.test(v)) return { result: null, error: 'Enter a number, for example 6.45.' };
        const places = Number(el.dataset.decimals || 3);
        if (decimals(v) > places) {
          return { result: null, error: places === 0 ? 'Enter a whole number.' : `Use at most ${places} decimals.` };
        }
        if (el.dataset.type === 'PERCENTAGE' && (scaled(v) < 0n || scaled(v) > 100000000n)) {
          return { result: null, error: 'A percentage must be between 0 and 100.' };
        }
        const { lsl, usl } = el.dataset;
        if (!lsl && !usl) return { result: 'NOT_APPLICABLE', error: null };
        if (lsl && scaled(v) < scaled(lsl)) return { result: 'FAIL', error: null };
        if (usl && scaled(v) > scaled(usl)) return { result: 'FAIL', error: null };
        return { result: 'PASS', error: null };
      }
      case 'OK_NOT_OK':
      case 'VISUAL':
        return { result: value === 'OK' ? 'PASS' : 'FAIL', error: null };
      case 'GO_NO_GO':
        return { result: value === 'GO' ? 'PASS' : 'FAIL', error: null };
      case 'DATE':
        if (!/^\d{4}-\d{2}-\d{2}$/.test(value)) return { result: null, error: 'Enter a valid date.' };
        if (el.dataset.dateRule === 'ON_OR_AFTER_INSPECTION_DATE') {
          return { result: value >= inspectionDate ? 'PASS' : 'FAIL', error: null };
        }
        return { result: 'NOT_APPLICABLE', error: null };
      case 'TIME':
        if (!/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/.test(value)) return { result: null, error: 'Enter a time as HH:MM.' };
        return { result: 'NOT_APPLICABLE', error: null };
      default:
        return { result: 'NOT_APPLICABLE', error: null };
    }
  }

  function readingValue(container, n) {
    for (const input of container.querySelectorAll(`[data-reading="${n}"]`)) {
      if (input.type === 'radio') {
        if (input.checked) return input.value;
      } else {
        return input.value;
      }
    }
    return '';
  }

  function aggregate(container) {
    const count = Number(container.dataset.count || 1);
    const results = [];
    let missing = 0;
    for (let n = 1; n <= count; n += 1) {
      const { result } = evaluate(container, readingValue(container, n));
      if (result) results.push(result);
      else missing += 1;
    }
    if (results.includes('FAIL')) return 'FAIL';
    if (container.dataset.mandatory === '1' && missing > 0) return 'INCOMPLETE';
    if (results.length === 0 || results.every((r) => r === 'NOT_APPLICABLE')) return 'NOT_APPLICABLE';
    return 'PASS';
  }

  /* ---------------------------------------------------------- display */
  const BADGES = {
    PASS: ['qms-badge--pass', 'bi-check-circle-fill', 'PASS'],
    FAIL: ['qms-badge--fail', 'bi-x-octagon-fill', 'OUT OF SPEC'],
    NOT_APPLICABLE: ['qms-badge--muted', 'bi-dash-circle', 'N/A'],
    INCOMPLETE: ['qms-badge--muted', 'bi-circle', 'INCOMPLETE'],
  };
  function badge(result) {
    const [cls, icon, text] = BADGES[result] || BADGES.INCOMPLETE;
    const span = document.createElement('span');
    span.className = `qms-badge ${cls}`;
    const i = document.createElement('i');
    i.className = `bi ${icon}`;
    i.setAttribute('aria-hidden', 'true');
    span.append(i, ` ${text}`);
    return span;
  }

  function showReading(container, n, result, error) {
    const numeric = ['NUMERIC', 'PERCENTAGE'].includes(container.dataset.type);
    container.querySelectorAll(`[data-reading="${n}"]`).forEach((input) => {
      if (input.type === 'radio') return;
      input.classList.toggle('is-pass', result === 'PASS');
      input.classList.toggle('is-fail', result === 'FAIL');
      input.classList.toggle('is-invalid', Boolean(error));
      input.setAttribute('aria-invalid', error || result === 'FAIL' ? 'true' : 'false');
    });
    const verdict = document.getElementById(`v-${container.dataset.obs}-${n}`);
    if (verdict) {
      const mini = verdict.classList.contains('qms-mini-verdict');
      let text = '';
      if (!error && result === 'FAIL') text = mini ? '✗ OOS' : numeric ? '✗ OUT OF SPEC' : '✗ FAIL';
      else if (!error && result === 'PASS' && !mini) text = numeric ? '✓ Within limits' : '✓ PASS';
      verdict.textContent = text;
      if (!mini) {
        verdict.classList.toggle('is-pass', !error && result === 'PASS');
        verdict.classList.toggle('is-fail', !error && result === 'FAIL');
      }
    }
    setError(`cell-${container.dataset.obs}-${n}`, error || '');
  }

  function setObservationResult(container, result) {
    container.dataset.result = result;
    container.classList.toggle('is-fail', result === 'FAIL');
    container.querySelector('[data-result]')?.replaceChildren(badge(result));
  }

  function setError(key, message) {
    root.querySelectorAll(`[data-error-for="${key}"]`).forEach((el) => {
      el.textContent = message;
    });
  }

  function showGaugeState(select) {
    const box = select.closest('[data-obs]')?.querySelector('[data-gauge-state]');
    if (!box) return;
    const option = select.selectedOptions[0];
    if (!option || option.value === '') {
      box.textContent = '';
      return;
    }
    const usable = option.dataset.usable !== '0';
    const block = box.dataset.block === '1';
    box.textContent = usable ? option.dataset.state || '' : `⚠ ${option.dataset.state || 'Not usable'}${block ? ' – submission will be blocked' : ''}`;
    box.classList.toggle('text-danger', !usable);
    box.classList.toggle('fw-bold', !usable);
    box.classList.toggle('text-muted', usable);
  }

  function refreshSummary() {
    let done = 0;
    let pass = 0;
    let fail = 0;
    const all = [...root.querySelectorAll('[data-obs]')];
    all.forEach((c) => {
      const r = c.dataset.result || aggregate(c);
      if (r !== 'INCOMPLETE') done += 1;
      if (r === 'PASS') pass += 1;
      if (r === 'FAIL') fail += 1;
    });
    const set = (sel, v) => root.querySelectorAll(sel).forEach((el) => { el.textContent = v; });
    set('[data-sum-done]', `${done} / ${all.length}`);
    set('[data-sum-pass]', String(pass));
    set('[data-sum-fail]', String(fail));

    root.querySelectorAll('.qms-step-panel').forEach((panel) => {
      const button = root.querySelector(`[data-goto="${panel.dataset.step}"] [data-step-icon]`);
      if (!button) return;
      const items = [...panel.querySelectorAll('[data-obs]')];
      if (items.length === 0) return;
      const anyFail = items.some((c) => c.dataset.result === 'FAIL');
      const allDone = items.every((c) => c.dataset.result && c.dataset.result !== 'INCOMPLETE');
      button.replaceChildren();
      if (anyFail || allDone) {
        const i = document.createElement('i');
        i.className = `bi ${anyFail ? 'bi-x-octagon-fill oos' : 'bi-check-circle-fill done'}`;
        button.append(i);
      }
    });
  }

  function renderProblems(problems) {
    root.querySelectorAll('[data-problems]').forEach((box) => {
      const alert = document.createElement('div');
      if (problems.length === 0) {
        alert.className = 'alert alert-success mb-2';
        alert.textContent = '✓ Everything required is filled in. The report is ready to submit.';
      } else {
        alert.className = 'alert alert-warning mb-2';
        const strong = document.createElement('strong');
        strong.textContent = 'Before you can submit:';
        const ul = document.createElement('ul');
        ul.className = 'mb-0';
        problems.forEach((p) => {
          const li = document.createElement('li');
          li.textContent = p;
          ul.append(li);
        });
        alert.append(strong, ul);
      }
      box.replaceChildren(alert);
    });
  }

  function setSaveState(kind, detail = '') {
    if (!saveStateEl) return;
    const pending = pendingCount();
    const texts = {
      saving: 'Saving…',
      saved: detail ? `All changes saved at ${detail}` : 'All changes saved',
      dirty: 'Unsaved changes',
      offline: `Offline – ${pending} change(s) kept on this tablet`,
      error: `Not saved: ${detail}`,
      partial: 'Saved – some values need correction',
      submitting: 'Submitting…',
    };
    saveStateEl.textContent = texts[kind] || '';
    saveStateEl.classList.toggle('is-offline', kind === 'offline');
    saveStateEl.classList.toggle('is-error', kind === 'error' || kind === 'partial');
  }

  function notice(message, tone = 'danger') {
    if (!conflictBanner) {
      window.alert(message);
      return;
    }
    conflictBanner.className = `alert alert-${tone}`;
    conflictBanner.textContent = message;
    conflictBanner.hidden = false;
    conflictBanner.scrollIntoView({ behavior: 'smooth', block: 'center' });
  }

  /* ---------------------------------------------------------- dirty state */
  const hasDirty = () => state.cells.size > 0 || state.obs.size > 0 || state.rounds.size > 0 || Object.keys(state.header).length > 0;
  const pendingCount = () => state.cells.size + state.obs.size + state.rounds.size + Object.keys(state.header).length
    + (state.inflightPayload ? (state.inflightPayload.cells?.length || 0) : 0);

  function patch(map, key, values) {
    map.set(key, { ...(map.get(key) || {}), ...values });
  }

  function buildPayload() {
    const payload = { lock_version: state.lock };
    if (Object.keys(state.header).length) {
      payload.header = { ...state.header };
      state.header = {};
    }
    if (state.cells.size) {
      payload.cells = [...state.cells].map(([key, value]) => {
        const [obs, n] = key.split(':');
        return { observation_id: Number(obs), reading_no: Number(n), value };
      });
      state.cells.clear();
    }
    if (state.obs.size) {
      payload.observations = [...state.obs].map(([id, v]) => ({ observation_id: Number(id), ...v }));
      state.obs.clear();
    }
    if (state.rounds.size) {
      payload.rounds = [...state.rounds].map(([id, v]) => ({ round_id: Number(id), ...v }));
      state.rounds.clear();
    }
    return payload;
  }

  /** Puts unsent values back unless the user changed them again meanwhile. */
  function requeue(payload, { header = true } = {}) {
    (payload.cells || []).forEach((c) => {
      const key = `${c.observation_id}:${c.reading_no}`;
      if (!state.cells.has(key)) state.cells.set(key, c.value);
    });
    (payload.observations || []).forEach(({ observation_id: id, ...v }) => {
      state.obs.set(String(id), { ...v, ...(state.obs.get(String(id)) || {}) });
    });
    (payload.rounds || []).forEach(({ round_id: id, ...v }) => {
      state.rounds.set(String(id), { ...v, ...(state.rounds.get(String(id)) || {}) });
    });
    if (header && payload.header) state.header = { ...payload.header, ...state.header };
  }

  function persist() {
    try {
      const snapshot = { cells: new Map(), obs: new Map(), rounds: new Map(), header: {} };
      const merge = (source) => {
        (source.cells || []).forEach((c) => snapshot.cells.set(`${c.observation_id}:${c.reading_no}`, c.value));
        (source.observations || []).forEach(({ observation_id: id, ...v }) => snapshot.obs.set(String(id), { ...(snapshot.obs.get(String(id)) || {}), ...v }));
        (source.rounds || []).forEach(({ round_id: id, ...v }) => snapshot.rounds.set(String(id), { ...(snapshot.rounds.get(String(id)) || {}), ...v }));
        Object.assign(snapshot.header, source.header || {});
      };
      if (state.inflightPayload) merge(state.inflightPayload);
      state.cells.forEach((value, key) => snapshot.cells.set(key, value));
      state.obs.forEach((v, id) => snapshot.obs.set(id, { ...(snapshot.obs.get(id) || {}), ...v }));
      state.rounds.forEach((v, id) => snapshot.rounds.set(id, { ...(snapshot.rounds.get(id) || {}), ...v }));
      Object.assign(snapshot.header, state.header);
      const empty = !snapshot.cells.size && !snapshot.obs.size && !snapshot.rounds.size && !Object.keys(snapshot.header).length;
      if (empty) {
        localStorage.removeItem(storageKey);
        return;
      }
      localStorage.setItem(storageKey, JSON.stringify({
        at: Date.now(),
        user: root.dataset.user,
        cells: [...snapshot.cells],
        obs: [...snapshot.obs],
        rounds: [...snapshot.rounds],
        header: snapshot.header,
      }));
    } catch {
      /* storage disabled or full: autosave still works online */
    }
  }

  function restoreLocal() {
    let saved = null;
    try {
      saved = JSON.parse(localStorage.getItem(storageKey) || 'null');
    } catch {
      saved = null;
    }
    if (!saved || saved.user !== root.dataset.user || Date.now() - saved.at > 7 * 86400000) {
      try { localStorage.removeItem(storageKey); } catch { /* ignore */ }
      return;
    }
    if (!editable) {
      try { localStorage.removeItem(storageKey); } catch { /* ignore */ }
      notice('Values entered on this tablet while offline could not be saved because the report was submitted meanwhile.', 'warning');
      return;
    }
    let applied = 0;
    (saved.cells || []).forEach(([key, value]) => {
      const [obs, n] = key.split(':');
      const container = root.querySelector(`[data-obs="${obs}"]`);
      if (!container) return;
      container.querySelectorAll(`[data-reading="${n}"]`).forEach((input) => {
        if (input.disabled) return;
        if (input.type === 'radio') input.checked = input.value === value;
        else input.value = value;
      });
      const { result, error } = evaluate(container, value);
      showReading(container, n, result, error);
      setObservationResult(container, aggregate(container));
      state.cells.set(key, value);
      applied += 1;
    });
    (saved.obs || []).forEach(([id, v]) => {
      const container = root.querySelector(`[data-obs="${id}"]`);
      if (!container) return;
      if ('gauge_id' in v) {
        const select = container.querySelector('[data-gauge]');
        if (select) {
          select.value = v.gauge_id ?? '';
          showGaugeState(select);
        }
      }
      if ('remarks' in v) {
        const input = container.querySelector('[data-remarks]');
        if (input) input.value = v.remarks;
      }
      state.obs.set(String(id), v);
      applied += 1;
    });
    (saved.rounds || []).forEach(([id, v]) => {
      Object.entries(v).forEach(([field, value]) => {
        const input = root.querySelector(`[data-round="${id}"][data-round-field="${field}"]`);
        if (input && !input.disabled) input.value = value;
      });
      state.rounds.set(String(id), v);
      applied += 1;
    });
    Object.entries(saved.header || {}).forEach(([field, value]) => {
      const input = root.querySelector(`[data-header-field="${field}"]`);
      if (input && !input.disabled) input.value = value;
      state.header[field] = value;
      applied += 1;
    });
    if (applied > 0) {
      notice(`${applied} value(s) entered on this tablet earlier were restored and are being saved now.`, 'info');
      refreshSummary();
      queueSave(300);
    }
  }

  /* ---------------------------------------------------------- saving */
  function queueSave(delay = 1500) {
    if (state.stopped) return;
    window.clearTimeout(state.timer);
    setSaveState('dirty');
    state.timer = window.setTimeout(() => { save(); }, delay);
  }

  function applyResponse(res, payload) {
    // Clear old messages for everything that was sent, then show the new ones.
    (payload.cells || []).forEach((c) => setError(`cell-${c.observation_id}-${c.reading_no}`, ''));
    (payload.observations || []).forEach((o) => setError(`obs-${o.observation_id}`, ''));
    (payload.rounds || []).forEach((r) => setError(`round-${r.round_id}`, ''));
    Object.entries(res.errors || {}).forEach(([key, message]) => setError(key, message));

    Object.entries(res.observations || {}).forEach(([id, info]) => {
      const container = root.querySelector(`[data-obs="${id}"]`);
      if (!container) return;
      Object.entries(info.readings || {}).forEach(([n, result]) => {
        const key = `${id}:${n}`;
        const hasError = Object.prototype.hasOwnProperty.call(res.errors || {}, `cell-${id}-${n}`);
        if (!state.cells.has(key) && !hasError) showReading(container, n, result, null);
      });
      if (![...state.cells.keys()].some((k) => k.startsWith(`${id}:`))) setObservationResult(container, info.result);
    });

    root.querySelectorAll('[data-overall]').forEach((el) => el.replaceChildren(badge(res.overall_result)));
    root.querySelectorAll('[data-oos]').forEach((el) => { el.hidden = Number(res.oos_count) === 0; });
    root.querySelectorAll('[data-oos-count]').forEach((el) => { el.textContent = String(res.oos_count); });
    if (Array.isArray(res.problems)) renderProblems(res.problems);
    refreshSummary();
  }

  async function save() {
    if (state.inflight) {
      state.again = true;
      return state.inflight;
    }
    window.clearTimeout(state.timer);
    if (!hasDirty()) {
      return true;
    }
    if (!navigator.onLine) {
      persist();
      setSaveState('offline');
      if (offlineBanner) offlineBanner.hidden = false;
      return false;
    }

    const payload = buildPayload();
    state.inflightPayload = payload;
    setSaveState('saving');

    state.inflight = (async () => {
      try {
        const res = await post(root.dataset.saveUrl, payload);
        if (!res || typeof res !== 'object') throw new HttpError(500, { error: 'Unexpected answer from the server.' });
        state.lock = Number(res.lock_version);
        state.inflightPayload = null;
        applyResponse(res, payload);
        if (offlineBanner) offlineBanner.hidden = true;
        setSaveState(res.ok ? 'saved' : 'partial', res.saved_at || '');
        return true;
      } catch (err) {
        state.inflightPayload = null;
        if (!(err instanceof HttpError)) {
          requeue(payload);
          setSaveState('offline');
          if (offlineBanner) offlineBanner.hidden = false;
          return false;
        }
        const message = err.body?.error || err.message;
        switch (err.status) {
          case 422: // header validation: nothing was saved; keep the rest, drop the header until edited again
            requeue(payload, { header: false });
            Object.entries(err.body?.errors || {}).forEach(([key, msg]) => setError(key, msg));
            setSaveState('error', message);
            if (hasDirty()) state.again = true;
            break;
          case 409:
            requeue(payload, { header: false });
            notice(`${message} Your other values are kept and will be saved.`, 'warning');
            setSaveState('error', 'report details changed by someone else – reload');
            if (hasDirty()) state.again = true;
            break;
          case 401:
          case 403:
            requeue(payload);
            state.stopped = err.status === 401;
            notice(`${message} Your values are kept on this tablet: log in again and reopen this report.`, 'danger');
            setSaveState('error', message);
            break;
          case 404:
          case 423:
            state.stopped = true;
            notice(`${message} Values that were not saved yet cannot be stored any more.`, 'danger');
            setSaveState('error', message);
            try { localStorage.removeItem(storageKey); } catch { /* ignore */ }
            return false;
          default:
            requeue(payload);
            setSaveState('error', message);
        }
        return false;
      } finally {
        persist();
        state.inflight = null;
        if (state.again) {
          state.again = false;
          if (hasDirty() && !state.stopped) queueSave(400);
        }
      }
    })();
    return state.inflight;
  }

  async function flush() {
    window.clearTimeout(state.timer);
    for (let i = 0; i < 4; i += 1) {
      if (state.inflight) await state.inflight;
      if (!hasDirty()) return true;
      const ok = await save();
      if (!ok) return false;
    }
    return !hasDirty();
  }

  /* ---------------------------------------------------------- input events */
  function onEdit(event) {
    const t = event.target;
    if (!editable || t.disabled || state.stopped) return;

    if (t.matches('[data-reading]')) {
      if (t.type === 'radio' && !t.checked) return;
      const container = t.closest('[data-obs]');
      const n = t.dataset.reading;
      const { result, error } = evaluate(container, t.value);
      showReading(container, n, result, error);
      setObservationResult(container, aggregate(container));
      refreshSummary();
      if (error) {
        state.cells.delete(`${container.dataset.obs}:${n}`);
        return;
      }
      state.cells.set(`${container.dataset.obs}:${n}`, t.value.trim());
    } else if (t.matches('[data-gauge]')) {
      if (event.type !== 'change') return;
      patch(state.obs, t.closest('[data-obs]').dataset.obs, { gauge_id: t.value === '' ? null : Number(t.value) });
      showGaugeState(t);
    } else if (t.matches('[data-remarks]')) {
      patch(state.obs, t.closest('[data-obs]').dataset.obs, { remarks: t.value });
    } else if (t.matches('[data-round-field]')) {
      patch(state.rounds, t.dataset.round, { [t.dataset.roundField]: t.value });
    } else if (t.matches('[data-header-field]')) {
      state.header[t.dataset.headerField] = t.value;
      setError(t.dataset.headerField, '');
    } else {
      return;
    }
    persist();
    queueSave(event.type === 'change' ? 800 : 1500);
  }
  root.addEventListener('input', onEdit);
  root.addEventListener('change', onEdit);

  // Enter moves to the next reading (numeric keypads on tablets).
  root.addEventListener('keydown', (event) => {
    if (event.key !== 'Enter' || !event.target.matches('input[data-reading]:not([type="radio"])')) return;
    event.preventDefault();
    const inputs = [...root.querySelectorAll('input[data-reading]:not([type="radio"]):not([disabled]), select[data-reading]:not([disabled])')]
      .filter((el) => el.offsetParent !== null);
    const next = inputs[inputs.indexOf(event.target) + 1];
    if (next) next.focus();
  });

  root.querySelectorAll('[data-now-for]').forEach((button) => {
    button.addEventListener('click', () => {
      const input = document.getElementById(button.dataset.nowFor);
      if (!input || input.disabled) return;
      // Plant time, whatever time zone the tablet is set to.
      const parts = new Intl.DateTimeFormat('en-GB', {
        timeZone: root.dataset.plantTz || undefined, hour: '2-digit', minute: '2-digit', hourCycle: 'h23',
      }).formatToParts(new Date());
      const part = (type) => parts.find((p) => p.type === type)?.value || '00';
      input.value = `${part('hour')}:${part('minute')}`;
      input.dispatchEvent(new Event('change', { bubbles: true }));
    });
  });

  root.querySelectorAll('[data-gauge]').forEach(showGaugeState);
  root.querySelector('[data-save-now]')?.addEventListener('click', () => {
    if (!hasDirty()) {
      setSaveState('saved');
      return;
    }
    save();
  });

  // Forms that act on the sheet (add / sign inspection) save pending values first.
  document.addEventListener('submit', async (event) => {
    const form = event.target;
    if (!(form instanceof HTMLFormElement) || !form.matches('[data-flush-save]') || event.defaultPrevented) return;
    event.preventDefault();
    const ok = await flush();
    if (!ok && hasDirty() && !window.confirm('Some values could not be saved yet. Continue anyway? They stay on this tablet.')) {
      form.querySelectorAll('[data-once]').forEach((b) => { b.disabled = false; });
      return;
    }
    form.submit();
  });

  document.addEventListener('qms:network', (event) => {
    if (event.detail.online) {
      if (offlineBanner) offlineBanner.hidden = true;
      if (hasDirty()) queueSave(500);
    } else if (offlineBanner) {
      offlineBanner.hidden = false;
      setSaveState(hasDirty() ? 'offline' : 'saved');
    }
  });

  window.setInterval(() => {
    if (hasDirty() && !state.inflight && !state.stopped && navigator.onLine) save();
  }, autosaveMs);

  window.addEventListener('beforeunload', (event) => {
    if (hasDirty() || state.inflight) {
      persist();
      event.preventDefault();
      event.returnValue = '';
    }
  });

  /* ---------------------------------------------------------- stepper */
  const panels = [...root.querySelectorAll('.qms-step-panel')];
  const stepButtons = [...root.querySelectorAll('[data-goto]')];
  const prev = root.querySelector('[data-prev]');
  const next = root.querySelector('[data-next]');
  const progress = root.querySelector('[data-progress]');
  let current = 0;

  function showStep(index, focus = true) {
    if (panels.length === 0) return;
    current = Math.max(0, Math.min(panels.length - 1, index));
    panels.forEach((panel, i) => { panel.hidden = i !== current; });
    stepButtons.forEach((button) => {
      if (Number(button.dataset.goto) === current) button.setAttribute('aria-current', 'step');
      else button.removeAttribute('aria-current');
    });
    if (progress) progress.textContent = `Step ${current + 1} of ${panels.length} · ${panels[current].dataset.stepTitle || ''}`;
    if (prev) prev.disabled = current === 0;
    if (next) next.hidden = current === panels.length - 1;
    stepButtons.find((b) => Number(b.dataset.goto) === current)?.scrollIntoView({ block: 'nearest', inline: 'center' });
    if (focus) {
      window.scrollTo({ top: 0, behavior: 'smooth' });
      const heading = panels[current].querySelector('h2, .card-header');
      if (heading) {
        heading.setAttribute('tabindex', '-1');
        heading.focus({ preventScroll: true });
      }
    }
    try { window.history.replaceState(null, '', `#step-${current}`); } catch { /* ignore */ }
  }

  if (panels.length > 0 && root.querySelector('[data-steps]')) {
    stepButtons.forEach((button) => button.addEventListener('click', () => showStep(Number(button.dataset.goto))));
    prev?.addEventListener('click', () => showStep(current - 1));
    next?.addEventListener('click', () => {
      save();
      showStep(current + 1);
    });
    const fromHash = () => {
      const match = /^#step-(\d+)$/.exec(window.location.hash);
      return match ? Number(match[1]) : null;
    };
    showStep(fromHash() ?? 0, false);
    window.addEventListener('hashchange', () => {
      const step = fromHash();
      if (step !== null && step !== current) showStep(step);
    });
  }

  /* ---------------------------------------------------------- submit */
  submitBtn?.addEventListener('click', async () => {
    if (submitBtn.dataset.confirm && !window.confirm(submitBtn.dataset.confirm)) return;
    submitBtn.disabled = true;
    setSaveState('submitting');
    const saved = await flush();
    if (!saved || hasDirty()) {
      submitBtn.disabled = false;
      notice('The latest values could not be saved. Check the network connection and try again.', 'warning');
      setSaveState(navigator.onLine ? 'error' : 'offline', 'not submitted');
      return;
    }
    const key = submitBtn.dataset.idem || uuid();
    submitBtn.dataset.idem = key; // retries of this attempt reuse the key
    try {
      const res = await post(root.dataset.submitUrl, { seen_status: root.dataset.status }, { idempotencyKey: key });
      try { localStorage.removeItem(storageKey); } catch { /* ignore */ }
      window.location.assign(res.redirect);
    } catch (err) {
      submitBtn.disabled = false;
      if (!(err instanceof HttpError)) {
        notice('No network: the report was not submitted yet. Your values are saved; press Submit again when the network is back.', 'warning');
        setSaveState('offline');
        return;
      }
      const message = err.body?.error || err.message;
      if (err.status === 422 && err.body?.errors) {
        const problems = Object.values(err.body.errors);
        renderProblems(problems);
        if (panels.length) showStep(panels.length - 1);
        notice(message, 'warning');
      } else {
        notice(message, 'danger');
      }
      setSaveState('error', 'not submitted');
    }
  });

  /* ---------------------------------------------------------- start */
  root.querySelectorAll('[data-obs]').forEach((c) => {
    if (!c.dataset.result) c.dataset.result = aggregate(c);
  });
  refreshSummary();
  restoreLocal();
  if (!navigator.onLine && offlineBanner) offlineBanner.hidden = false;
}
