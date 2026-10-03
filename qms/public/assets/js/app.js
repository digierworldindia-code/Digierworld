/*
 * QMS shared front-end helpers (ES module, no inline scripts – strict CSP).
 * - CSRF + Idempotency-Key aware fetch helper (window.QMS.post)
 * - online / offline indicator
 * - show-password toggles, confirm dialogs, single-submit buttons
 * - signature dialog (remarks + password) for workflow actions
 */

const meta = (name) => document.querySelector(`meta[name="${name}"]`)?.content ?? '';

function uuid() {
  if (window.crypto?.randomUUID) {
    return window.crypto.randomUUID();
  }
  const bytes = window.crypto.getRandomValues(new Uint8Array(16));
  bytes[6] = (bytes[6] & 0x0f) | 0x40;
  bytes[8] = (bytes[8] & 0x3f) | 0x80;
  const hex = [...bytes].map((b) => b.toString(16).padStart(2, '0')).join('');
  return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
}

class HttpError extends Error {
  constructor(status, body) {
    super(body?.error || `Request failed (${status})`);
    this.status = status;
    this.body = body;
  }
}

/**
 * POST JSON with CSRF header. Pass {idempotencyKey} to make the request safe to retry.
 * Throws HttpError for HTTP errors and TypeError for network failures (offline).
 */
async function post(url, data = {}, { idempotencyKey = null } = {}) {
  const headers = {
    'Content-Type': 'application/json',
    Accept: 'application/json',
    'X-Requested-With': 'XMLHttpRequest',
    [meta('csrf-header') || 'X-CSRF-TOKEN']: meta('csrf-hash'),
  };
  if (idempotencyKey) {
    headers['Idempotency-Key'] = idempotencyKey;
  }
  const response = await fetch(url, {
    method: 'POST',
    credentials: 'same-origin',
    headers,
    body: JSON.stringify(data),
  });
  let body = null;
  try {
    body = await response.json();
  } catch {
    body = null;
  }
  if (!response.ok) {
    throw new HttpError(response.status, body);
  }
  return body;
}

window.QMS = Object.assign(window.QMS || {}, { post, uuid, HttpError, meta });

/* ---------- online / offline indicator -------------------------------------- */
function renderNetStatus() {
  const online = navigator.onLine;
  document.querySelectorAll('[data-net-status]').forEach((el) => {
    el.classList.toggle('is-offline', !online);
    const txt = el.querySelector('.txt');
    if (txt) txt.textContent = online ? 'Online' : 'Offline';
  });
  document.dispatchEvent(new CustomEvent('qms:network', { detail: { online } }));
}
window.addEventListener('online', renderNetStatus);
window.addEventListener('offline', renderNetStatus);
renderNetStatus();

/* ---------- show / hide password -------------------------------------------- */
document.addEventListener('click', (event) => {
  const button = event.target.closest('[data-toggle-password]');
  if (!button) return;
  const input = document.querySelector(button.dataset.togglePassword);
  if (!input) return;
  const show = input.type === 'password';
  input.type = show ? 'text' : 'password';
  button.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
  button.querySelector('.bi')?.classList.toggle('bi-eye', !show);
  button.querySelector('.bi')?.classList.toggle('bi-eye-slash', show);
});

/* ---------- confirm + single submit ----------------------------------------- */
document.addEventListener('submit', (event) => {
  const form = event.target;
  if (!(form instanceof HTMLFormElement)) return;
  const message = form.dataset.confirm;
  if (message && !window.confirm(message)) {
    event.preventDefault();
    return;
  }
  form.querySelectorAll('[data-once]').forEach((button) => {
    // Disable after the browser has captured the submitter value.
    window.setTimeout(() => { button.disabled = true; }, 0);
  });
});

document.querySelectorAll('[data-autosubmit]').forEach((el) => {
  el.addEventListener('change', () => el.form?.submit());
});

/* ---------- signature dialog ------------------------------------------------- */
/*
 * <button data-sign-action="approve" data-sign-title="..." data-sign-remarks="required|optional"
 *         data-sign-form="#workflowForm">
 * The dialog collects remarks + password, then submits the hidden form.
 */
const signModalEl = document.getElementById('signatureModal');
if (signModalEl && window.bootstrap) {
  const modal = new window.bootstrap.Modal(signModalEl);
  const form = signModalEl.querySelector('form');
  const remarks = signModalEl.querySelector('[name="remarks"]');
  const password = signModalEl.querySelector('[name="password"]');
  const title = signModalEl.querySelector('[data-sign-title]');
  const meaning = signModalEl.querySelector('[data-sign-meaning]');
  const actionInput = signModalEl.querySelector('[name="action"]');
  const remarksLabel = signModalEl.querySelector('[data-remarks-label]');

  document.querySelectorAll('[data-sign-action]').forEach((button) => {
    button.addEventListener('click', () => {
      form.action = button.dataset.signUrl;
      actionInput.value = button.dataset.signAction;
      title.textContent = button.dataset.signTitle || 'Confirm';
      meaning.textContent = button.dataset.signMeaning || '';
      const required = button.dataset.signRemarks === 'required';
      remarks.required = required;
      remarksLabel.classList.toggle('required', required);
      remarks.closest('.mb-3').hidden = button.dataset.signRemarks === 'none';
      remarks.required = required && button.dataset.signRemarks !== 'none';
      password.closest('.mb-3').hidden = button.dataset.signPassword === 'no';
      password.required = button.dataset.signPassword !== 'no';
      remarks.value = '';
      password.value = '';
      const key = form.querySelector('[name="idempotency_key"]');
      if (key) key.value = uuid();
      const submit = form.querySelector('[type="submit"]');
      if (submit) {
        submit.disabled = false;
        submit.textContent = button.dataset.signButton || 'Sign';
        submit.className = `btn btn-lg ${button.dataset.signTone || 'btn-primary'}`;
      }
      modal.show();
      window.setTimeout(() => {
        const first = [remarks, password].find((el) => !el.closest('.mb-3').hidden);
        first?.focus();
      }, 300);
    });
  });
}
