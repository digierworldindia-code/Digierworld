'use server';

/**
 * Form handling.
 *
 * Implemented as server actions for one specific reason: they submit through a
 * real form POST when JavaScript has not loaded or has failed. A visitor on a
 * poor connection can still verify a mattress or send an enquiry.
 *
 * The browser posts to this application, and this application forwards to the
 * private API over the internal network. The public origin never learns the
 * API's address, and the API's own validation and rate limiting still apply —
 * the checks below are for a helpful error message, not for safety.
 */
import { apiPost, type VerificationResult } from '@/lib/api';

export interface FormState {
  status: 'idle' | 'success' | 'error';
  message?: string;
  fieldErrors?: Record<string, string[]>;
  result?: VerificationResult;
}

export const IDLE: FormState = { status: 'idle' };

function readErrors(data: unknown): { message: string; fieldErrors?: Record<string, string[]> } {
  const error = (data as { error?: { message?: string; details?: Record<string, string[]> } })?.error;
  return {
    message: error?.message ?? 'Something went wrong. Please try again, or call us instead.',
    ...(error?.details ? { fieldErrors: error.details } : {}),
  };
}

function value(formData: FormData, key: string): string {
  const raw = formData.get(key);
  return typeof raw === 'string' ? raw.trim() : '';
}

// ---------------------------------------------------------------------------
export async function verifyMattressAction(_previous: FormState, formData: FormData): Promise<FormState> {
  const serial = value(formData, 'serialNumber');
  const qrToken = value(formData, 'qrToken');

  if (!serial && !qrToken) {
    return { status: 'error', fieldErrors: { serialNumber: ['Enter the serial number printed on the label.'] } };
  }

  const response = await apiPost<VerificationResult>('/verify', qrToken ? { qrToken } : { serialNumber: serial });

  if (!response.ok || !response.data) {
    const { message, fieldErrors } = readErrors(response.data);
    return { status: 'error', message, ...(fieldErrors ? { fieldErrors } : {}) };
  }

  return { status: 'success', result: response.data };
}

// ---------------------------------------------------------------------------
export async function submitContactAction(_previous: FormState, formData: FormData): Promise<FormState> {
  const payload = {
    name: value(formData, 'name'),
    phone: value(formData, 'phone'),
    email: value(formData, 'email'),
    city: value(formData, 'city'),
    requirement: value(formData, 'requirement') || 'PRODUCT_ENQUIRY',
    message: value(formData, 'message'),
    // Honeypot. Forwarded as-is so the API can score it; a real visitor never
    // sees or fills this field.
    website: value(formData, 'website'),
  };

  const response = await apiPost<{ received: boolean; message: string }>('/leads', payload);

  if (!response.ok) {
    const { message, fieldErrors } = readErrors(response.data);
    return { status: 'error', message, ...(fieldErrors ? { fieldErrors } : {}) };
  }

  return {
    status: 'success',
    message: response.data?.message ?? 'Thank you. Our team will be in touch shortly.',
  };
}

// ---------------------------------------------------------------------------
export async function submitDealerApplicationAction(_previous: FormState, formData: FormData): Promise<FormState> {
  const payload = {
    businessName: value(formData, 'businessName'),
    ownerName: value(formData, 'ownerName'),
    mobile: value(formData, 'mobile'),
    email: value(formData, 'email'),
    city: value(formData, 'city'),
    state: value(formData, 'state'),
    address: value(formData, 'address'),
    gstNumber: value(formData, 'gstNumber'),
    hasExistingBusiness: formData.get('hasExistingBusiness') === 'on',
    existingBusinessDetails: value(formData, 'existingBusinessDetails'),
    message: value(formData, 'message'),
    website: value(formData, 'website'),
  };

  const response = await apiPost<{ received: boolean; message: string }>('/dealer-applications', payload);

  if (!response.ok) {
    const { message, fieldErrors } = readErrors(response.data);
    return { status: 'error', message, ...(fieldErrors ? { fieldErrors } : {}) };
  }

  return {
    status: 'success',
    message:
      response.data?.message ??
      'Thank you for your interest. Our dealer development team will review your application and contact you.',
  };
}
