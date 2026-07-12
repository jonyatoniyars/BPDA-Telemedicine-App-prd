/**
 * PalliCare – app.js
 * Pure vanilla JS helpers for the PHP/cPanel frontend.
 */

/* ── Toast notifications ──────────────────────────────────── */

/**
 * Show a temporary toast notification.
 * @param {string} message
 * @param {'success'|'error'|'warning'|'info'} type
 * @param {number} duration  milliseconds before auto-dismiss
 */
function showToast(message, type = 'success', duration = 4000) {
  let container = document.getElementById('toast-container');
  if (!container) {
    container = document.createElement('div');
    container.id = 'toast-container';
    document.body.appendChild(container);
  }

  const icons = {
    success: '✓',
    error:   '✕',
    warning: '⚠',
    info:    'ℹ',
  };

  const toast = document.createElement('div');
  toast.className = `toast toast-${type}`;
  toast.innerHTML = `<span class="toast-icon" aria-hidden="true">${icons[type] ?? icons.info}</span>
    <span class="toast-msg">${escapeHtml(message)}</span>`;
  toast.setAttribute('role', 'status');
  toast.setAttribute('aria-live', 'polite');

  container.appendChild(toast);

  const dismiss = () => {
    toast.classList.add('hiding');
    toast.addEventListener('animationend', () => toast.remove(), { once: true });
  };

  const timer = setTimeout(dismiss, duration);
  toast.addEventListener('click', () => { clearTimeout(timer); dismiss(); });
}

/* ── apiFetch ─────────────────────────────────────────────── */

/**
 * Fetch wrapper that sets JSON headers and handles common errors.
 * @param {string} url
 * @param {RequestInit} options
 * @returns {Promise<any>}  Parsed JSON body
 */
async function apiFetch(url, options = {}) {
  const defaults = {
    headers: {
      'Content-Type': 'application/json',
      'Accept':       'application/json',
      'X-Requested-With': 'XMLHttpRequest',
    },
  };

  const merged = {
    ...defaults,
    ...options,
    headers: { ...defaults.headers, ...(options.headers ?? {}) },
  };

  let response;
  try {
    response = await fetch(url, merged);
  } catch (networkErr) {
    showToast('Network error – please check your connection.', 'error');
    throw networkErr;
  }

  if (response.status === 401) {
    showToast('Session expired. Redirecting to login…', 'warning');
    setTimeout(() => { window.location.href = 'login.php'; }, 1500);
    throw new Error('Unauthorized');
  }

  if (response.status === 403) {
    showToast('You do not have permission to perform that action.', 'error');
    throw new Error('Forbidden');
  }

  if (response.status === 404) {
    showToast('Resource not found.', 'error');
    throw new Error('Not found');
  }

  if (response.status === 500) {
    showToast('A server error occurred. Please try again.', 'error');
    throw new Error('Server error');
  }

  let data;
  const contentType = response.headers.get('Content-Type') ?? '';
  if (contentType.includes('application/json')) {
    data = await response.json();
  } else {
    data = await response.text();
  }

  if (!response.ok) {
    const msg = (data && data.message) ? data.message : `Request failed (${response.status})`;
    showToast(msg, 'error');
    throw new Error(msg);
  }

  return data;
}

/* ── confirmAction ────────────────────────────────────────── */

/**
 * Returns a Promise that resolves true/false based on a confirmation dialog.
 * Falls back to window.confirm when no custom modal is present.
 * @param {string} message
 * @param {string} [confirmLabel='Confirm']
 * @param {'danger'|'primary'} [confirmVariant='danger']
 * @returns {Promise<boolean>}
 */
function confirmAction(message, confirmLabel = 'Confirm', confirmVariant = 'danger') {
  const modal = document.getElementById('confirm-modal');
  if (!modal) {
    return Promise.resolve(window.confirm(message));
  }

  return new Promise((resolve) => {
    const bodyEl   = modal.querySelector('#confirm-modal-body');
    const okBtn    = modal.querySelector('#confirm-modal-ok');
    const cancelBtn= modal.querySelector('#confirm-modal-cancel');

    if (bodyEl)   bodyEl.textContent = message;
    if (okBtn)    okBtn.textContent  = confirmLabel;
    if (okBtn) {
      okBtn.className = `btn btn-${confirmVariant} btn-sm`;
    }

    openModal('confirm-modal');

    const cleanup = (result) => {
      closeModal('confirm-modal');
      okBtn?.removeEventListener('click', onOk);
      cancelBtn?.removeEventListener('click', onCancel);
      resolve(result);
    };
    const onOk     = () => cleanup(true);
    const onCancel = () => cleanup(false);

    okBtn?.addEventListener('click', onOk,     { once: true });
    cancelBtn?.addEventListener('click', onCancel, { once: true });
  });
}

/* ── Modal helpers ────────────────────────────────────────── */

function openModal(idOrEl) {
  const el = typeof idOrEl === 'string' ? document.getElementById(idOrEl) : idOrEl;
  if (!el) return;
  el.classList.add('open');
  document.body.style.overflow = 'hidden';
  const focusable = el.querySelector('button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])');
  focusable?.focus();
}

function closeModal(idOrEl) {
  const el = typeof idOrEl === 'string' ? document.getElementById(idOrEl) : idOrEl;
  if (!el) return;
  el.classList.remove('open');
  document.body.style.overflow = '';
}

/* ── Mobile sidebar toggle ────────────────────────────────── */

function initSidebar() {
  const sidebar    = document.querySelector('.sidebar');
  // Support both BEM (.sidebar__overlay) and non-BEM (.sidebar-backdrop) naming.
  const backdrop   = document.querySelector('.sidebar-backdrop, .sidebar__overlay');
  // Support both BEM (.sidebar__toggle) and non-BEM (.sidebar-toggle) toggle buttons.
  const toggleBtns = document.querySelectorAll('.sidebar-toggle, .sidebar__toggle');
  // Support sidebar close buttons (BEM: .sidebar__close, non-BEM: .sidebar-close-btn).
  const closeBtns  = document.querySelectorAll('.sidebar-close-btn, .sidebar__close');

  if (!sidebar) return;

  function openSidebar() {
    sidebar.classList.add('open');
    backdrop?.classList.add('open');
    document.body.style.overflow = 'hidden';
  }

  function closeSidebar() {
    sidebar.classList.remove('open');
    backdrop?.classList.remove('open');
    document.body.style.overflow = '';
  }

  toggleBtns.forEach((btn) => btn.addEventListener('click', () => {
    sidebar.classList.contains('open') ? closeSidebar() : openSidebar();
  }));

  closeBtns.forEach((btn) => btn.addEventListener('click', closeSidebar));

  backdrop?.addEventListener('click', closeSidebar);

  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && sidebar.classList.contains('open')) closeSidebar();
  });
}

/* ── Form validation helper ───────────────────────────────── */

/**
 * Validates a form element using HTML5 constraint API plus optional custom rules.
 * Shows inline error messages. Returns true if valid.
 *
 * @param {HTMLFormElement} form
 * @param {Record<string, (value:string)=>string|null>} [customRules]
 *   Keys are input[name], values are validator functions that return error string or null.
 * @returns {boolean}
 */
function validateForm(form, customRules = {}) {
  let valid = true;

  clearFormErrors(form);

  Array.from(form.elements).forEach((el) => {
    if (!el.name || el.disabled) return;

    let errorMsg = null;

    if (!el.checkValidity()) {
      errorMsg = el.validationMessage;
    }

    if (!errorMsg && customRules[el.name]) {
      errorMsg = customRules[el.name](el.value) ?? null;
    }

    if (errorMsg) {
      showFieldError(el, errorMsg);
      valid = false;
    }
  });

  if (!valid) {
    const firstError = form.querySelector('.field-error')?.previousElementSibling;
    firstError?.focus();
  }

  return valid;
}

function showFieldError(inputEl, message) {
  inputEl.classList.add('error');
  inputEl.setAttribute('aria-invalid', 'true');
  const existing = inputEl.parentElement.querySelector('.field-error');
  if (existing) { existing.textContent = message; return; }
  const p = document.createElement('p');
  p.className = 'field-error';
  p.textContent = message;
  inputEl.insertAdjacentElement('afterend', p);
}

function clearFormErrors(form) {
  form.querySelectorAll('.field-error').forEach((el) => el.remove());
  form.querySelectorAll('.error').forEach((el) => {
    el.classList.remove('error');
    el.removeAttribute('aria-invalid');
  });
}

/* ── Generic delete / confirm handlers via data attributes ── */

/**
 * Attach click handlers to elements with [data-confirm].
 * If element also has [data-action] and [data-method], sends an API request.
 * If element has [data-href], redirects after confirm.
 *
 * Usage examples:
 *   <button data-confirm="Delete this record?" data-action="/api/records/delete.php" data-method="POST" data-id="5">Delete</button>
 *   <a href="delete.php?id=5" data-confirm="Are you sure?">Delete</a>
 */
function initConfirmHandlers() {
  document.addEventListener('click', async (e) => {
    const btn = e.target.closest('[data-confirm]');
    if (!btn) return;

    e.preventDefault();
    e.stopPropagation();

    const message  = btn.dataset.confirm  || 'Are you sure?';
    const label    = btn.dataset.confirmLabel || 'Confirm';
    const variant  = btn.dataset.confirmVariant || 'danger';

    const confirmed = await confirmAction(message, label, variant);
    if (!confirmed) return;

    const action = btn.dataset.action;
    const method = (btn.dataset.method || 'POST').toUpperCase();
    const href   = btn.getAttribute('href') || btn.dataset.href;

    if (action) {
      const payload = {};
      if (btn.dataset.id) payload.id = btn.dataset.id;

      try {
        setButtonLoading(btn, true);
        const data = await apiFetch(action, {
          method,
          body: JSON.stringify(payload),
        });
        const successMsg = btn.dataset.successMsg || data?.message || 'Done!';
        showToast(successMsg, 'success');

        const reloadTarget = btn.dataset.reload;
        if (reloadTarget === 'page') {
          setTimeout(() => window.location.reload(), 800);
        } else if (reloadTarget) {
          const section = document.querySelector(reloadTarget);
          section?.dispatchEvent(new CustomEvent('reload'));
        } else {
          const row = btn.closest('tr');
          row?.remove();
        }
      } catch {
        // Error already shown by apiFetch
      } finally {
        setButtonLoading(btn, false);
      }
    } else if (href) {
      window.location.href = href;
    }
  });
}

/* ── Button loading state ─────────────────────────────────── */

function setButtonLoading(btn, loading) {
  if (loading) {
    btn.disabled = true;
    btn._originalHTML = btn.innerHTML;
    btn.innerHTML = `<svg class="animate-spin" style="width:1em;height:1em;display:inline-block;" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" aria-hidden="true">
      <circle opacity=".25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
      <path opacity=".75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
    </svg>`;
  } else {
    btn.disabled = false;
    if (btn._originalHTML) {
      btn.innerHTML = btn._originalHTML;
      delete btn._originalHTML;
    }
  }
}

/* ── Auto-hide alerts after 5 seconds ─────────────────────── */

function initAutoHideAlerts() {
  document.querySelectorAll('.alert[data-auto-hide]').forEach((el) => {
    const delay = parseInt(el.dataset.autoHide || '5000', 10);
    setTimeout(() => {
      el.style.transition = 'opacity 0.4s ease';
      el.style.opacity = '0';
      setTimeout(() => el.remove(), 400);
    }, delay);
  });

  document.querySelectorAll('.alert .alert-close').forEach((btn) => {
    btn.addEventListener('click', () => {
      const alert = btn.closest('.alert');
      if (!alert) return;
      alert.style.transition = 'opacity 0.2s ease';
      alert.style.opacity = '0';
      setTimeout(() => alert.remove(), 200);
    });
  });
}

/* ── Table search (client-side) ───────────────────────────── */

/**
 * Wire up a search input to filter table rows client-side.
 * @param {string} inputSelector   CSS selector for the search <input>
 * @param {string} tableSelector   CSS selector for the <table> or wrapper
 * @param {number[]} [cols]        Column indices to search (default: all)
 */
function initTableSearch(inputSelector, tableSelector, cols = null) {
  const input = document.querySelector(inputSelector);
  const table = document.querySelector(tableSelector);
  if (!input || !table) return;

  input.addEventListener('input', () => {
    const query = input.value.trim().toLowerCase();
    const rows  = table.querySelectorAll('tbody tr');

    rows.forEach((row) => {
      const cells = Array.from(row.querySelectorAll('td'));
      const text  = (cols ? cols.map((i) => cells[i]) : cells)
        .map((c) => (c ? c.textContent : ''))
        .join(' ')
        .toLowerCase();
      row.style.display = text.includes(query) ? '' : 'none';
    });

    const visible = table.querySelectorAll('tbody tr:not([style*="display: none"])');
    let noResults = table.querySelector('.no-results-row');
    if (visible.length === 0 && query) {
      if (!noResults) {
        noResults = document.createElement('tr');
        noResults.className = 'no-results-row';
        const td = document.createElement('td');
        td.colSpan = 99;
        td.className = 'text-center text-slate-500 py-8';
        td.textContent = 'No results match your search.';
        noResults.appendChild(td);
        table.querySelector('tbody')?.appendChild(noResults);
      }
    } else {
      noResults?.remove();
    }
  });
}

/* ── Modal close on backdrop click ───────────────────────── */

function initModalBackdrops() {
  document.querySelectorAll('.modal-backdrop').forEach((backdrop) => {
    backdrop.addEventListener('click', (e) => {
      if (e.target === backdrop) closeModal(backdrop);
    });
  });

  document.querySelectorAll('.modal-close, [data-modal-close]').forEach((btn) => {
    btn.addEventListener('click', () => {
      const modal = btn.closest('.modal-backdrop');
      if (modal) closeModal(modal);
    });
  });

  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') {
      document.querySelectorAll('.modal-backdrop.open').forEach(closeModal);
    }
  });
}

/* ── Password show/hide toggle ────────────────────────────── */

function initPasswordToggles() {
  document.querySelectorAll('[data-password-toggle]').forEach((btn) => {
    const targetId = btn.dataset.passwordToggle;
    const input    = document.getElementById(targetId);
    if (!input) return;

    btn.addEventListener('click', () => {
      const isText = input.type === 'text';
      input.type   = isText ? 'password' : 'text';
      btn.setAttribute('aria-label', isText ? 'Show password' : 'Hide password');
      /* swap eye icon text if used as label */
      const icon = btn.querySelector('[data-eye]');
      if (icon) icon.dataset.eye = isText ? 'show' : 'hide';
    });
  });
}

/* ── Utility: escape HTML ─────────────────────────────────── */

function escapeHtml(str) {
  return String(str)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#039;');
}

/* ── Utility: format date ─────────────────────────────────── */

function formatDate(dateStr) {
  const d = new Date(dateStr);
  return d.toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' });
}

/* ── Spinner HTML helper ──────────────────────────────────── */

function spinnerHtml(sizeClass = 'h-4 w-4') {
  return `<svg class="animate-spin ${sizeClass}" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" aria-hidden="true">
    <circle opacity=".25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
    <path opacity=".75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
  </svg>`;
}

/* ── Init on DOM ready ────────────────────────────────────── */

document.addEventListener('DOMContentLoaded', () => {
  initSidebar();
  initConfirmHandlers();
  initAutoHideAlerts();
  initModalBackdrops();
  initPasswordToggles();
});

/* ── Exports (for modules or inline script access) ────────── */
window.PalliCare = {
  showToast,
  apiFetch,
  confirmAction,
  openModal,
  closeModal,
  validateForm,
  showFieldError,
  clearFormErrors,
  setButtonLoading,
  initTableSearch,
  spinnerHtml,
  formatDate,
  escapeHtml,
};
