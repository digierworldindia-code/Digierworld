import { z } from 'zod';
import { email, safeText, trimmed, uuid } from './primitives.js';

export const loginSchema = z.object({
  email,
  // Not length-validated beyond an upper bound: telling an attacker that a
  // password was "too short" leaks the policy of the stored credential.
  password: z.string().min(1).max(200),
  totpCode: z.string().trim().regex(/^\d{6}$/).optional(),
  recoveryCode: z.string().trim().max(32).optional(),
});

export const refreshSchema = z.object({}).strict();

export const changePasswordSchema = z.object({
  currentPassword: z.string().min(1).max(200),
  newPassword: z.string().min(12).max(128),
});

export const forgotPasswordSchema = z.object({ email });

export const resetPasswordSchema = z.object({
  token: z.string().min(20).max(200),
  newPassword: z.string().min(12).max(128),
});

export const mfaEnrolStartSchema = z.object({ password: z.string().min(1).max(200) });

export const mfaEnrolConfirmSchema = z.object({
  totpCode: z.string().trim().regex(/^\d{6}$/),
});

export const mfaDisableSchema = z.object({
  password: z.string().min(1).max(200),
  totpCode: z.string().trim().regex(/^\d{6}$/),
});

export const createUserSchema = z.object({
  email,
  fullName: trimmed(160),
  phone: z.string().trim().max(20).optional(),
  roleKeys: z.array(z.enum(['SUPER_ADMIN', 'ADMIN', 'WAREHOUSE', 'WARRANTY_MANAGER', 'SALES_MANAGER', 'DEALER', 'REPORTING'])).min(1),
  dealerId: uuid.optional(),
});

export const updateUserSchema = z.object({
  fullName: trimmed(160).optional(),
  phone: z.string().trim().max(20).optional(),
  status: z.enum(['ACTIVE', 'SUSPENDED', 'DISABLED', 'PENDING_ACTIVATION']).optional(),
});

export const assignRolesSchema = z.object({
  roleKeys: z.array(z.enum(['SUPER_ADMIN', 'ADMIN', 'WAREHOUSE', 'WARRANTY_MANAGER', 'SALES_MANAGER', 'DEALER', 'REPORTING'])).min(1),
  reason: safeText(500).optional(),
});

export type LoginInput = z.infer<typeof loginSchema>;
export type CreateUserInput = z.infer<typeof createUserSchema>;
