import { z } from 'zod';
import { pagination, safeText, slug, trimmed, uuid } from './primitives.js';

export const seoMetadataSchema = z.object({
  title: trimmed(200),
  description: trimmed(320),
  canonicalPath: z.string().trim().max(300).regex(/^\/[A-Za-z0-9\-_/]*$/, 'must be a site-relative path').optional(),
  ogTitle: trimmed(200).optional(),
  ogDescription: trimmed(320).optional(),
  ogImageUrl: z.string().trim().max(400).url().optional().or(z.literal('')),
  twitterCard: z.enum(['summary', 'summary_large_image']).default('summary_large_image'),
  robotsIndex: z.boolean().default(true),
  robotsFollow: z.boolean().default(true),
  keywords: z.array(trimmed(60)).max(20).default([]),
  sitemapInclude: z.boolean().default(true),
  sitemapPriority: z.number().min(0).max(1).default(0.5),
  sitemapChangefreq: z.enum(['always', 'hourly', 'daily', 'weekly', 'monthly', 'yearly', 'never']).default('monthly'),
});

export const upsertProductSchema = z.object({
  slug,
  name: trimmed(160),
  skuPrefix: z.string().trim().toUpperCase().min(2).max(12).regex(/^[A-Z0-9]+$/),
  category: trimmed(60),
  tagline: trimmed(200).optional(),
  shortDescription: safeText(400, { min: 40 }),
  description: safeText(8000, { min: 80 }),
  comfortLevel: trimmed(40),
  firmnessScore: z.number().int().min(1).max(10),
  materials: z.array(trimmed(200)).max(20),
  features: z.array(trimmed(200)).max(20),
  specifications: z.record(z.string().max(60), z.string().max(300)),
  careInstructions: safeText(2000).optional(),
  warrantyYears: z.number().int().min(1).max(30),
  trialNights: z.number().int().min(0).max(365).nullable().optional(),
  isFeatured: z.boolean().default(false),
  sortOrder: z.number().int().min(0).max(9999).default(100),
});

export const upsertVariantSchema = z.object({
  sku: z.string().trim().toUpperCase().min(3).max(40).regex(/^[A-Z0-9-]+$/),
  sizeLabel: trimmed(60),
  widthIn: z.number().int().min(12).max(120),
  lengthIn: z.number().int().min(24).max(120),
  heightIn: z.number().int().min(2).max(24),
  mrp: z.number().positive().max(10_000_000),
  weightKg: z.number().positive().max(200).optional(),
  sortOrder: z.number().int().min(0).max(9999).default(100),
});

export const publishSchema = z.object({
  status: z.enum(['DRAFT', 'PUBLISHED', 'ARCHIVED']),
});

export const upsertPageSchema = z.object({
  slug,
  title: trimmed(200),
  hero: z.record(z.string(), z.unknown()).default({}),
  sections: z.array(z.record(z.string(), z.unknown())).max(30).default([]),
});

export const upsertFaqSchema = z.object({
  question: trimmed(300),
  answer: safeText(4000, { min: 10 }),
  category: trimmed(60),
  sortOrder: z.number().int().min(0).max(9999).default(100),
  isPublished: z.boolean().default(true),
  productId: uuid.nullable().optional(),
  pageId: uuid.nullable().optional(),
});

export const leadListQuery = pagination.extend({
  status: z.enum(['NEW', 'CONTACTED', 'FOLLOW_UP', 'CONVERTED', 'CLOSED']).optional(),
});

export const updateLeadSchema = z.object({
  status: z.enum(['NEW', 'CONTACTED', 'FOLLOW_UP', 'CONVERTED', 'CLOSED']),
  internalNotes: safeText(2000).optional(),
});

export const updateSettingSchema = z.object({
  value: z.union([z.string().max(2000), z.number(), z.boolean(), z.null()]),
});

export const auditListQuery = pagination.extend({
  action: z.string().trim().max(60).optional(),
  entity: z.string().trim().max(60).optional(),
  entityId: z.string().trim().max(64).optional(),
  userId: uuid.optional(),
  from: z.string().datetime().optional(),
  to: z.string().datetime().optional(),
});

/**
 * Read-only SQL console for technical administrators. The statement is checked
 * here for shape, then again in the service, then executed inside a READ ONLY
 * transaction with a statement timeout. Three independent gates, because a SQL
 * console is the single most dangerous surface in an admin tool.
 */
export const readOnlySqlSchema = z.object({
  sql: z
    .string()
    .trim()
    .min(6)
    .max(4000)
    .refine((v) => /^\s*(select|with)\b/i.test(v), 'only SELECT and WITH statements are permitted')
    .refine((v) => !/;\s*\S/.test(v.replace(/;\s*$/, '')), 'only a single statement may be run')
    .refine(
      (v) =>
        !/\b(insert|update|delete|drop|alter|create|truncate|grant|revoke|copy|vacuum|call|do|set|reset|listen|notify|prepare|execute|refresh|reindex|cluster|lock|comment|security\s+label)\b/i.test(
          v,
        ),
      'the statement contains a keyword that is not allowed in the read-only console',
    )
    .refine((v) => !/\bpg_(read_file|read_binary_file|ls_dir|stat_file|sleep|terminate_backend|reload_conf)\b/i.test(v), 'server-side file, sleep and administration functions are not available')
    .refine((v) => !/\b(pg_authid|pg_shadow|pg_user_mapping)\b/i.test(v), 'credential catalogues are not readable from the console')
    .refine((v) => !/\bdblink|postgres_fdw|lo_import|lo_export\b/i.test(v), 'external data access is not available from the console'),
  maxRows: z.coerce.number().int().min(1).max(500).default(100),
});
