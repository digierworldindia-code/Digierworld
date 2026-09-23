'use client';

import { useId } from 'react';
import { BRAND } from '@polyfix/brand';

/**
 * Shared console building blocks.
 *
 * Small and unopinionated on purpose: the console has a lot of screens, and
 * they should look like one product without each one re-deciding what a badge
 * or an empty state is.
 */

// ---------------------------------------------------------------------------
/**
 * The console wordmark.
 *
 * One component rather than the same two-span markup repeated on the sidebar
 * and on each of the four authentication screens. The split between the two
 * halves comes from the brand module, so a future rename changes one file.
 */
export function Wordmark({ suffix }: { suffix?: string }) {
  return (
    <>
      {BRAND.wordmark.lead}
      <span>{BRAND.wordmark.trail}</span>
      {suffix ? ` ${suffix}` : null}
    </>
  );
}

/** Name of the private console, overridable per deployment. */
export const CONSOLE_SUFFIX = 'Control';

// ---------------------------------------------------------------------------
export function Badge({ tone = 'neutral', children }: { tone?: 'neutral' | 'positive' | 'caution' | 'critical' | 'info'; children: React.ReactNode }) {
  return <span className={`badge badge-${tone}`}>{children}</span>;
}

/** Maps a domain status onto a colour, in one place, so screens stay consistent. */
export function StatusBadge({ status }: { status: string }) {
  const tone = STATUS_TONES[status] ?? 'neutral';
  return <Badge tone={tone}>{status.replace(/_/g, ' ').toLowerCase()}</Badge>;
}

const STATUS_TONES: Record<string, 'neutral' | 'positive' | 'caution' | 'critical' | 'info'> = {
  // mattress
  MANUFACTURED: 'neutral',
  IN_DISPATCH: 'info',
  DISPATCHED: 'info',
  DEALER_RECEIVED: 'positive',
  SOLD: 'positive',
  CLAIM_OPEN: 'caution',
  REPLACED: 'neutral',
  RETURNED: 'caution',
  SCRAPPED: 'critical',
  // dispatch
  DRAFT: 'neutral',
  PARTIALLY_RECEIVED: 'caution',
  RECEIVED: 'positive',
  CANCELLED: 'critical',
  // claim
  SUBMITTED: 'info',
  UNDER_REVIEW: 'info',
  INFO_REQUESTED: 'caution',
  APPROVED: 'positive',
  REJECTED: 'critical',
  CLOSED: 'neutral',
  WITHDRAWN: 'neutral',
  // warranty
  ACTIVE: 'positive',
  EXPIRED: 'neutral',
  VOID: 'critical',
  SUPERSEDED: 'caution',
  // risk
  LOW: 'positive',
  MEDIUM: 'caution',
  HIGH: 'critical',
  // dealer / user
  PENDING: 'caution',
  SUSPENDED: 'critical',
  TERMINATED: 'critical',
  DISABLED: 'critical',
  PENDING_ACTIVATION: 'caution',
  // leads
  NEW: 'info',
  CONTACTED: 'info',
  FOLLOW_UP: 'caution',
  CONVERTED: 'positive',
};

// ---------------------------------------------------------------------------
export function Kpi({
  label,
  value,
  hint,
  alert = false,
}: {
  label: string;
  value: React.ReactNode;
  hint?: string;
  alert?: boolean;
}) {
  return (
    <div className={`kpi${alert ? ' kpi--alert' : ''}`}>
      <p className="kpi__label">{label}</p>
      <p className="kpi__value">{value}</p>
      {hint ? <p className="kpi__hint">{hint}</p> : null}
    </div>
  );
}

// ---------------------------------------------------------------------------
export function Field({
  label,
  name,
  errors,
  hint,
  as = 'input',
  options = [],
  large = false,
  ...rest
}: {
  label: string;
  name: string;
  errors?: string[];
  hint?: string;
  as?: 'input' | 'textarea' | 'select';
  options?: { value: string; label: string }[];
  large?: boolean;
} & React.InputHTMLAttributes<HTMLInputElement> &
  React.TextareaHTMLAttributes<HTMLTextAreaElement> &
  React.SelectHTMLAttributes<HTMLSelectElement>) {
  const id = useId();
  const hasError = Boolean(errors?.length);
  const describedBy = [hint ? `${id}-hint` : null, hasError ? `${id}-error` : null].filter(Boolean).join(' ') || undefined;

  // Typed loosely on purpose: this one component renders an input, a textarea
  // or a select, and the three element types have incompatible prop sets that
  // are not worth three near-identical components to satisfy.
  const shared: Record<string, unknown> = {
    id,
    name,
    'aria-invalid': hasError,
    'aria-describedby': describedBy,
    ...rest,
  };

  return (
    <div className="field">
      <label htmlFor={id}>{label}</label>
      {as === 'textarea' ? (
        <textarea className="textarea" {...(shared as React.TextareaHTMLAttributes<HTMLTextAreaElement>)} />
      ) : as === 'select' ? (
        <select className="select" {...(shared as React.SelectHTMLAttributes<HTMLSelectElement>)}>
          {options.map((option) => (
            <option key={option.value} value={option.value}>{option.label}</option>
          ))}
        </select>
      ) : (
        <input className={`input${large ? ' input-lg' : ''}`} {...(shared as React.InputHTMLAttributes<HTMLInputElement>)} />
      )}
      {hint ? <span className="hint" id={`${id}-hint`}>{hint}</span> : null}
      {hasError ? <span className="error" id={`${id}-error`} role="alert">{errors!.join(' ')}</span> : null}
    </div>
  );
}

// ---------------------------------------------------------------------------
export function EmptyState({ title, body, action }: { title: string; body?: string; action?: React.ReactNode }) {
  return (
    <div className="empty">
      <h3>{title}</h3>
      {body ? <p className="small">{body}</p> : null}
      {action ? <div style={{ marginTop: '1rem' }}>{action}</div> : null}
    </div>
  );
}

export function Loading({ rows = 3 }: { rows?: number }) {
  return (
    <div className="stack-sm" aria-busy="true" aria-live="polite">
      <span className="visually-hidden">Loading</span>
      {Array.from({ length: rows }, (_, index) => (
        <div key={index} className="skeleton" style={{ height: '2.5rem' }} />
      ))}
    </div>
  );
}

export function ErrorNotice({ message, requestId }: { message: string; requestId?: string }) {
  return (
    <div className="notice notice-critical" role="alert">
      <p>{message}</p>
      {requestId ? <p className="small" style={{ marginTop: '0.35rem', opacity: 0.8 }}>Reference: {requestId}</p> : null}
    </div>
  );
}

// ---------------------------------------------------------------------------
/** A horizontal bar chart. Twelve lines of CSS instead of a charting library. */
export function BarChart({ data, unit = '' }: { data: { label: string; value: number }[]; unit?: string }) {
  const max = Math.max(1, ...data.map((entry) => entry.value));
  return (
    <div className="bars">
      {data.map((entry) => (
        <div className="bars__row" key={entry.label}>
          <span className="muted nowrap" style={{ overflow: 'hidden', textOverflow: 'ellipsis' }}>{entry.label}</span>
          <span className="bars__track">
            <span className="bars__fill" style={{ width: `${Math.round((entry.value / max) * 100)}%` }} />
          </span>
          <span className="strong nowrap">{entry.value}{unit}</span>
        </div>
      ))}
    </div>
  );
}

export function Pagination({
  page,
  totalPages,
  total,
  onChange,
}: {
  page: number;
  totalPages: number;
  total: number;
  onChange: (page: number) => void;
}) {
  if (totalPages <= 1) return <p className="small faint" style={{ paddingTop: '0.75rem' }}>{total} record{total === 1 ? '' : 's'}</p>;

  return (
    <div className="pagination">
      <span className="small muted">Page {page} of {totalPages} · {total} records</span>
      <span className="row">
        <button type="button" className="btn btn-default btn-sm" disabled={page <= 1} onClick={() => onChange(page - 1)}>
          Previous
        </button>
        <button type="button" className="btn btn-default btn-sm" disabled={page >= totalPages} onClick={() => onChange(page + 1)}>
          Next
        </button>
      </span>
    </div>
  );
}

export function formatDate(value: string | null | undefined, withTime = false): string {
  if (!value) return '—';
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return '—';
  return new Intl.DateTimeFormat('en-IN', {
    day: '2-digit',
    month: 'short',
    year: 'numeric',
    ...(withTime ? { hour: '2-digit', minute: '2-digit' } : {}),
  }).format(date);
}

export function formatMoney(value: number | null | undefined): string {
  if (value === null || value === undefined) return '—';
  return new Intl.NumberFormat('en-IN', { style: 'currency', currency: 'INR', maximumFractionDigits: 0 }).format(value);
}
