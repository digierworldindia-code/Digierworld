'use client';

import { useId } from 'react';

/**
 * A labelled form control.
 *
 * Accessibility is built in rather than bolted on: the label is tied to the
 * control by id, the hint and the error are referenced through
 * `aria-describedby`, and an invalid control carries `aria-invalid` so a screen
 * reader announces the problem rather than leaving the user to guess.
 */
interface FieldProps {
  label: string;
  name: string;
  type?: string;
  required?: boolean;
  hint?: string;
  errors?: string[];
  placeholder?: string;
  autoComplete?: string;
  inputMode?: 'text' | 'tel' | 'email' | 'numeric';
  pattern?: string;
  maxLength?: number;
  defaultValue?: string;
  as?: 'input' | 'textarea' | 'select';
  options?: { value: string; label: string }[];
  rows?: number;
}

export function FormField({
  label,
  name,
  type = 'text',
  required = false,
  hint,
  errors,
  placeholder,
  autoComplete,
  inputMode,
  pattern,
  maxLength,
  defaultValue,
  as = 'input',
  options = [],
  rows,
}: FieldProps) {
  const id = useId();
  const hintId = `${id}-hint`;
  const errorId = `${id}-error`;
  const hasError = Boolean(errors && errors.length > 0);
  const describedBy = [hint ? hintId : null, hasError ? errorId : null].filter(Boolean).join(' ') || undefined;

  const shared = {
    id,
    name,
    required,
    placeholder,
    autoComplete,
    inputMode,
    pattern,
    maxLength,
    defaultValue,
    'aria-invalid': hasError,
    'aria-describedby': describedBy,
  } as const;

  return (
    <div className="field">
      <label htmlFor={id}>
        {label}
        {required ? <span aria-hidden="true" style={{ color: 'var(--brass)' }}> *</span> : null}
        {!required ? <span className="muted" style={{ fontWeight: 400 }}> (optional)</span> : null}
      </label>

      {as === 'textarea' ? (
        <textarea className="textarea" rows={rows ?? 5} {...shared} />
      ) : as === 'select' ? (
        <select className="select" {...shared}>
          {options.map((option) => (
            <option key={option.value} value={option.value}>{option.label}</option>
          ))}
        </select>
      ) : (
        <input className="input" type={type} {...shared} />
      )}

      {hint ? <span className="hint" id={hintId}>{hint}</span> : null}
      {hasError ? (
        <span className="field-error" id={errorId} role="alert">{errors!.join(' ')}</span>
      ) : null}
    </div>
  );
}

/** The hidden field a bot fills in and a person never sees. */
export function Honeypot() {
  return (
    <div className="honeypot" aria-hidden="true">
      <label htmlFor="website-field">Leave this field empty</label>
      <input id="website-field" name="website" type="text" tabIndex={-1} autoComplete="off" />
    </div>
  );
}

export function SubmitButton({ children, pending }: { children: React.ReactNode; pending: boolean }) {
  return (
    <button type="submit" className="btn btn-primary" disabled={pending}>
      {pending ? 'Sending…' : children}
    </button>
  );
}
