/**
 * Schemas for input that arrives from the open internet.
 *
 * Everything here is anonymous traffic, so each schema is paired with rate
 * limiting and a honeypot field at the route level. Values are bounded tightly:
 * an unbounded text field on a public endpoint is a storage and abuse problem.
 */
import { z } from 'zod';
import { email, phone, pincode, qrToken, safeText, serialNumber, trimmed } from './primitives.js';

export const verifyMattressSchema = z
  .object({
    serialNumber: serialNumber.optional(),
    qrToken: qrToken.optional(),
  })
  .refine((v) => Boolean(v.serialNumber) !== Boolean(v.qrToken), {
    message: 'Enter a serial number, or scan the QR code on the label.',
  });

export const contactSubmissionSchema = z.object({
  name: trimmed(160),
  phone,
  email: z.string().trim().toLowerCase().max(255).optional().or(z.literal('')),
  city: trimmed(80).optional(),
  requirement: z.enum(['PRODUCT_ENQUIRY', 'WARRANTY_SUPPORT', 'DEALERSHIP', 'BULK_ORDER', 'OTHER']),
  message: safeText(2000, { min: 10, minMessage: 'Tell us a little more so we can help.' }),
  /**
   * Honeypot. A real person never sees this field, so any value in it marks the
   * submission as automated. The response is still a success so a bot cannot
   * learn that it was caught.
   */
  website: z.string().max(0).optional(),
});

export const dealerApplicationSchema = z.object({
  businessName: trimmed(180),
  ownerName: trimmed(160),
  mobile: phone,
  email,
  city: trimmed(80),
  state: trimmed(80),
  address: safeText(400, { min: 10 }),
  gstNumber: z.string().trim().toUpperCase().max(20).optional().or(z.literal('')),
  hasExistingBusiness: z.boolean().default(false),
  existingBusinessDetails: safeText(1000).optional(),
  message: safeText(2000).optional(),
  website: z.string().max(0).optional(),
});

export const dealerLocatorQuery = z.object({
  state: trimmed(80).optional(),
  city: trimmed(80).optional(),
  pincode: pincode.optional(),
  limit: z.coerce.number().int().min(1).max(100).default(50),
});

export type VerifyMattressInput = z.infer<typeof verifyMattressSchema>;
export type ContactSubmissionInput = z.infer<typeof contactSubmissionSchema>;
export type DealerApplicationInput = z.infer<typeof dealerApplicationSchema>;
