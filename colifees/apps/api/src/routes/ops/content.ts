/**
 * Website CMS, SEO settings and website leads.
 *
 * SEO and content editing are ordinary staff permissions. A dealer holds
 * neither, so nothing in this module is reachable from the dealer portal.
 */
import type { FastifyInstance } from 'fastify';
import { z } from 'zod';
import {
  upsertProductSchema,
  upsertVariantSchema,
  publishSchema,
  upsertPageSchema,
  upsertFaqSchema,
  seoMetadataSchema,
  leadListQuery,
  updateLeadSchema,
  softDeleteSchema,
  slug as slugSchema,
  pagination,
} from '@colifees/validation';
import { Prisma } from '@colifees/database';
import { parse, noStore, paginate, skipTake, idParam } from '../../lib/http.js';
import { errors } from '../../lib/errors.js';
import { actorFrom, recordAudit, recordVersion } from '../../services/audit.js';

export async function registerContentRoutes(app: FastifyInstance): Promise<void> {
  // =========================================================================
  // Catalogue
  // =========================================================================
  app.get('/products', { preHandler: app.requirePermission('product:read') }, async (request, reply) => {
    const products = await request.db((tx) =>
      tx.product.findMany({
        where: { deletedAt: null },
        orderBy: [{ sortOrder: 'asc' }, { name: 'asc' }],
        select: {
          id: true, slug: true, name: true, category: true, status: true, isFeatured: true,
          warrantyYears: true, comfortLevel: true, firmnessScore: true, sortOrder: true, updatedAt: true,
          _count: { select: { variants: true } },
          websiteProduct: { select: { isPublished: true } },
        },
      }),
    );
    noStore(reply);
    return {
      products: products.map((p) => ({
        id: p.id, slug: p.slug, name: p.name, category: p.category, status: p.status,
        isFeatured: p.isFeatured, warrantyYears: p.warrantyYears, comfortLevel: p.comfortLevel,
        firmnessScore: p.firmnessScore, sortOrder: p.sortOrder, updatedAt: p.updatedAt,
        variantCount: p._count.variants,
        liveOnWebsite: p.status === 'PUBLISHED' && (p.websiteProduct?.isPublished ?? false),
      })),
    };
  });

  app.get<{ Params: { id: string } }>(
    '/products/:id',
    { preHandler: app.requirePermission('product:read') },
    async (request, reply) => {
      const { id } = parse(idParam, request.params);
      const product = await request.db((tx) =>
        tx.product.findFirst({
          where: { id, deletedAt: null },
          include: {
            variants: { where: { deletedAt: null }, orderBy: { sortOrder: 'asc' } },
            websiteProduct: true,
            faqs: { orderBy: { sortOrder: 'asc' } },
          },
        }),
      );
      if (!product) throw errors.notFound('product');

      const seo = await request.db((tx) =>
        tx.seoMetadata.findFirst({ where: { scope: 'PRODUCT', entityKey: product.slug } }),
      );

      noStore(reply);
      return { product, seo };
    },
  );

  app.post('/products', { preHandler: app.requirePermission('product:write') }, async (request, reply) => {
    const input = parse(upsertProductSchema, request.body);

    const product = await request.db(async (tx) => {
      const existing = await tx.product.findUnique({ where: { slug: input.slug }, select: { id: true } });
      if (existing) throw errors.conflict('A product with that URL slug already exists.');

      const created = await tx.product.create({
        data: {
          ...input,
          specifications: input.specifications as Prisma.InputJsonValue,
          materials: input.materials as Prisma.InputJsonValue,
          features: input.features as Prisma.InputJsonValue,
          status: 'DRAFT',
          createdById: request.auth?.userId ?? null,
        },
        select: { id: true, slug: true, name: true, status: true },
      });

      await tx.websiteProduct.create({
        data: { productId: created.id, headline: input.name, subheadline: input.tagline ?? null, isPublished: false },
      });

      await recordAudit(tx, actorFrom(request), {
        action: 'PRODUCT_CREATED',
        entity: 'product',
        entityId: created.id,
        newValue: { slug: created.slug, name: created.name },
      });

      return created;
    });

    noStore(reply);
    return reply.status(201).send(product);
  });

  app.patch<{ Params: { id: string } }>(
    '/products/:id',
    { preHandler: app.requirePermission('product:write') },
    async (request, reply) => {
      const { id } = parse(idParam, request.params);
      const input = parse(upsertProductSchema.partial(), request.body);

      const updated = await request.db(async (tx) => {
        const before = await tx.product.findFirst({ where: { id, deletedAt: null } });
        if (!before) throw errors.notFound('product');

        // Product copy is marketing collateral with legal weight (warranty
        // terms, material claims), so every edit keeps the prior version.
        await recordVersion(tx, 'product', id, before, request.auth?.userId ?? null, 'product content edited');

        const record = await tx.product.update({
          where: { id },
          data: {
            ...input,
            ...(input.specifications ? { specifications: input.specifications as Prisma.InputJsonValue } : {}),
            ...(input.materials ? { materials: input.materials as Prisma.InputJsonValue } : {}),
            ...(input.features ? { features: input.features as Prisma.InputJsonValue } : {}),
            updatedById: request.auth?.userId ?? null,
          },
          select: { id: true, slug: true, name: true, status: true },
        });

        await recordAudit(tx, actorFrom(request), {
          action: 'PRODUCT_UPDATED',
          entity: 'product',
          entityId: id,
          previousValue: { name: before.name, warrantyYears: before.warrantyYears },
          newValue: { name: record.name, warrantyYears: input.warrantyYears ?? before.warrantyYears },
        });

        return record;
      });

      noStore(reply);
      return updated;
    },
  );

  app.post<{ Params: { id: string } }>(
    '/products/:id/publish',
    { preHandler: app.requirePermission('product:publish') },
    async (request, reply) => {
      const { id } = parse(idParam, request.params);
      const input = parse(publishSchema, request.body);

      const result = await request.db(async (tx) => {
        const product = await tx.product.findFirst({
          where: { id, deletedAt: null },
          select: { id: true, slug: true, status: true, _count: { select: { variants: true } } },
        });
        if (!product) throw errors.notFound('product');

        if (input.status === 'PUBLISHED') {
          if (product._count.variants === 0) {
            throw errors.businessRule('Add at least one size before publishing this product.');
          }
          const seo = await tx.seoMetadata.findFirst({ where: { scope: 'PRODUCT', entityKey: product.slug } });
          if (!seo) {
            throw errors.businessRule('Set the SEO title and description before publishing this product.');
          }
        }

        await tx.product.update({
          where: { id },
          data: {
            status: input.status,
            publishedAt: input.status === 'PUBLISHED' ? new Date() : null,
            updatedById: request.auth?.userId ?? null,
          },
        });
        await tx.websiteProduct.updateMany({
          where: { productId: id },
          data: { isPublished: input.status === 'PUBLISHED' },
        });

        await recordAudit(tx, actorFrom(request), {
          action: 'PRODUCT_PUBLISH_STATUS_CHANGED',
          entity: 'product',
          entityId: id,
          previousValue: { status: product.status },
          newValue: { status: input.status },
        });

        return { slug: product.slug, status: input.status };
      });

      noStore(reply);
      return result;
    },
  );

  app.post<{ Params: { id: string } }>(
    '/products/:id/variants',
    { preHandler: app.requirePermission('product:write') },
    async (request, reply) => {
      const { id } = parse(idParam, request.params);
      const input = parse(upsertVariantSchema, request.body);

      const variant = await request.db(async (tx) => {
        const product = await tx.product.findFirst({ where: { id, deletedAt: null }, select: { id: true } });
        if (!product) throw errors.notFound('product');

        const created = await tx.productVariant.create({
          data: {
            productId: id,
            sku: input.sku,
            sizeLabel: input.sizeLabel,
            widthIn: input.widthIn,
            lengthIn: input.lengthIn,
            heightIn: input.heightIn,
            mrp: new Prisma.Decimal(input.mrp),
            weightKg: input.weightKg ? new Prisma.Decimal(input.weightKg) : null,
            sortOrder: input.sortOrder,
            status: 'PUBLISHED',
          },
          select: { id: true, sku: true, sizeLabel: true },
        });

        await recordAudit(tx, actorFrom(request), {
          action: 'PRODUCT_VARIANT_CREATED',
          entity: 'product_variant',
          entityId: created.id,
          newValue: { sku: created.sku, sizeLabel: created.sizeLabel, mrp: input.mrp },
        });

        return created;
      });

      noStore(reply);
      return reply.status(201).send(variant);
    },
  );

  app.delete<{ Params: { id: string } }>(
    '/products/:id',
    { preHandler: app.requirePermission('product:delete') },
    async (request, reply) => {
      const { id } = parse(idParam, request.params);
      const input = parse(softDeleteSchema, request.body);

      await request.db(async (tx) => {
        const product = await tx.product.findFirst({ where: { id, deletedAt: null }, select: { id: true, slug: true } });
        if (!product) throw errors.notFound('product');

        // Removing a product must not orphan mattresses that reference it.
        const unitsInField = await tx.mattress.count({
          where: { productVariant: { productId: id }, deletedAt: null },
        });
        if (unitsInField > 0) {
          throw errors.businessRule(
            `${unitsInField} mattress record(s) reference this product. Unpublish it instead so the history stays intact.`,
          );
        }

        await tx.product.update({
          where: { id },
          data: {
            deletedAt: new Date(),
            deletedById: request.auth?.userId ?? null,
            deleteReason: input.reason,
            status: 'ARCHIVED',
          },
        });
        await tx.websiteProduct.updateMany({ where: { productId: id }, data: { isPublished: false } });

        await recordAudit(tx, actorFrom(request), {
          action: 'PRODUCT_DELETED',
          entity: 'product',
          entityId: id,
          previousValue: { slug: product.slug },
          reason: input.reason,
        });
      });

      noStore(reply);
      return { deleted: true, softDelete: true };
    },
  );

  // =========================================================================
  // Pages and FAQs
  // =========================================================================
  app.get('/pages', { preHandler: app.requirePermission('cms:read') }, async (request, reply) => {
    const pages = await request.db((tx) =>
      tx.websitePage.findMany({
        orderBy: { slug: 'asc' },
        select: { id: true, slug: true, title: true, status: true, publishedAt: true, updatedAt: true },
      }),
    );
    noStore(reply);
    return { pages };
  });

  app.get<{ Params: { slug: string } }>(
    '/pages/:slug',
    { preHandler: app.requirePermission('cms:read') },
    async (request, reply) => {
      const slug = parse(slugSchema, request.params.slug);
      const page = await request.db((tx) => tx.websitePage.findUnique({ where: { slug } }));
      if (!page) throw errors.notFound('page');
      const seo = await request.db((tx) => tx.seoMetadata.findFirst({ where: { scope: 'PAGE', entityKey: slug } }));
      noStore(reply);
      return { page, seo };
    },
  );

  app.put<{ Params: { slug: string } }>(
    '/pages/:slug',
    { preHandler: app.requirePermission('cms:write') },
    async (request, reply) => {
      const slug = parse(slugSchema, request.params.slug);
      const input = parse(upsertPageSchema, { ...(request.body as object), slug });

      const page = await request.db(async (tx) => {
        const before = await tx.websitePage.findUnique({ where: { slug } });
        if (before) {
          await recordVersion(tx, 'website_page', before.id, before, request.auth?.userId ?? null, 'page content edited');
        }

        const record = await tx.websitePage.upsert({
          where: { slug },
          create: {
            slug,
            title: input.title,
            hero: input.hero as Prisma.InputJsonValue,
            sections: input.sections as Prisma.InputJsonValue,
            status: 'DRAFT',
            createdById: request.auth?.userId ?? null,
          },
          update: {
            title: input.title,
            hero: input.hero as Prisma.InputJsonValue,
            sections: input.sections as Prisma.InputJsonValue,
            updatedById: request.auth?.userId ?? null,
          },
          select: { id: true, slug: true, title: true, status: true },
        });

        await recordAudit(tx, actorFrom(request), {
          action: before ? 'PAGE_UPDATED' : 'PAGE_CREATED',
          entity: 'website_page',
          entityId: record.id,
          newValue: { slug: record.slug, title: record.title },
        });

        return record;
      });

      noStore(reply);
      return page;
    },
  );

  app.post<{ Params: { slug: string } }>(
    '/pages/:slug/publish',
    { preHandler: app.requirePermission('cms:publish') },
    async (request, reply) => {
      const slug = parse(slugSchema, request.params.slug);
      const input = parse(publishSchema, request.body);

      const result = await request.db(async (tx) => {
        const page = await tx.websitePage.findUnique({ where: { slug }, select: { id: true, status: true } });
        if (!page) throw errors.notFound('page');

        await tx.websitePage.update({
          where: { slug },
          data: {
            status: input.status,
            publishedAt: input.status === 'PUBLISHED' ? new Date() : null,
            updatedById: request.auth?.userId ?? null,
          },
        });

        await recordAudit(tx, actorFrom(request), {
          action: 'PAGE_PUBLISH_STATUS_CHANGED',
          entity: 'website_page',
          entityId: page.id,
          previousValue: { status: page.status },
          newValue: { status: input.status },
        });

        return { slug, status: input.status };
      });

      noStore(reply);
      return result;
    },
  );

  app.get('/faqs', { preHandler: app.requirePermission('cms:read') }, async (request, reply) => {
    const faqs = await request.db((tx) =>
      tx.faq.findMany({
        orderBy: [{ category: 'asc' }, { sortOrder: 'asc' }],
        select: {
          id: true, question: true, answer: true, category: true, sortOrder: true, isPublished: true,
          product: { select: { name: true, slug: true } },
        },
      }),
    );
    noStore(reply);
    return { faqs };
  });

  app.post('/faqs', { preHandler: app.requirePermission('cms:write') }, async (request, reply) => {
    const input = parse(upsertFaqSchema, request.body);
    const faq = await request.db(async (tx) => {
      const created = await tx.faq.create({
        data: {
          question: input.question,
          answer: input.answer,
          category: input.category,
          sortOrder: input.sortOrder,
          isPublished: input.isPublished,
          productId: input.productId ?? null,
          pageId: input.pageId ?? null,
          updatedById: request.auth?.userId ?? null,
        },
        select: { id: true, question: true },
      });
      await recordAudit(tx, actorFrom(request), {
        action: 'FAQ_CREATED',
        entity: 'faq',
        entityId: created.id,
        newValue: { question: created.question, category: input.category },
      });
      return created;
    });
    noStore(reply);
    return reply.status(201).send(faq);
  });

  app.patch<{ Params: { id: string } }>(
    '/faqs/:id',
    { preHandler: app.requirePermission('cms:write') },
    async (request, reply) => {
      const { id } = parse(idParam, request.params);
      const input = parse(upsertFaqSchema.partial(), request.body);
      const faq = await request.db(async (tx) => {
        const before = await tx.faq.findUnique({ where: { id } });
        if (!before) throw errors.notFound('FAQ');
        const record = await tx.faq.update({
          where: { id },
          data: { ...input, updatedById: request.auth?.userId ?? null },
          select: { id: true, question: true, isPublished: true },
        });
        await recordAudit(tx, actorFrom(request), {
          action: 'FAQ_UPDATED',
          entity: 'faq',
          entityId: id,
          previousValue: { question: before.question, isPublished: before.isPublished },
          newValue: { question: record.question, isPublished: record.isPublished },
        });
        return record;
      });
      noStore(reply);
      return faq;
    },
  );

  // =========================================================================
  // SEO
  // =========================================================================
  app.get('/seo', { preHandler: app.requirePermission('seo:read') }, async (request, reply) => {
    const records = await request.db((tx) => tx.seoMetadata.findMany({ orderBy: [{ scope: 'asc' }, { entityKey: 'asc' }] }));
    noStore(reply);
    return { seo: records };
  });

  app.put<{ Params: { scope: string; key: string } }>(
    '/seo/:scope/:key',
    { preHandler: app.requirePermission('seo:write') },
    async (request, reply) => {
      const scope = parse(z.enum(['GLOBAL', 'PAGE', 'PRODUCT']), request.params.scope.toUpperCase());
      const key = parse(z.string().trim().min(1).max(160), request.params.key);
      const input = parse(seoMetadataSchema, request.body);

      const record = await request.db(async (tx) => {
        const before = await tx.seoMetadata.findFirst({ where: { scope, entityKey: key } });

        const saved = await tx.seoMetadata.upsert({
          where: { scope_entityKey: { scope, entityKey: key } },
          create: {
            scope,
            entityKey: key,
            title: input.title,
            description: input.description,
            canonicalPath: input.canonicalPath ?? null,
            ogTitle: input.ogTitle ?? null,
            ogDescription: input.ogDescription ?? null,
            ogImageUrl: input.ogImageUrl || null,
            twitterCard: input.twitterCard,
            robotsIndex: input.robotsIndex,
            robotsFollow: input.robotsFollow,
            keywords: input.keywords,
            sitemapInclude: input.sitemapInclude,
            sitemapPriority: new Prisma.Decimal(input.sitemapPriority),
            sitemapChangefreq: input.sitemapChangefreq,
            updatedById: request.auth?.userId ?? null,
          },
          update: {
            title: input.title,
            description: input.description,
            canonicalPath: input.canonicalPath ?? null,
            ogTitle: input.ogTitle ?? null,
            ogDescription: input.ogDescription ?? null,
            ogImageUrl: input.ogImageUrl || null,
            twitterCard: input.twitterCard,
            robotsIndex: input.robotsIndex,
            robotsFollow: input.robotsFollow,
            keywords: input.keywords,
            sitemapInclude: input.sitemapInclude,
            sitemapPriority: new Prisma.Decimal(input.sitemapPriority),
            sitemapChangefreq: input.sitemapChangefreq,
            updatedById: request.auth?.userId ?? null,
          },
        });

        await recordAudit(tx, actorFrom(request), {
          action: 'SEO_UPDATED',
          entity: 'seo_metadata',
          entityId: saved.id,
          previousValue: before ? { title: before.title, robotsIndex: before.robotsIndex } : undefined,
          // Turning off indexing for a live page is a change worth spotting in
          // the audit trail.
          newValue: { scope, key, title: input.title, robotsIndex: input.robotsIndex },
        });

        return saved;
      });

      noStore(reply);
      return record;
    },
  );

  // =========================================================================
  // Website leads
  // =========================================================================
  app.get('/leads', { preHandler: app.requirePermission('lead:read') }, async (request, reply) => {
    const query = parse(leadListQuery, request.query);
    const { skip, take } = skipTake(query.page, query.pageSize);

    const result = await request.db(async (tx) => {
      const where = query.status ? { status: query.status } : {};
      const [items, total] = await Promise.all([
        tx.contactSubmission.findMany({
          where,
          orderBy: { createdAt: 'desc' },
          skip,
          take,
          select: {
            id: true, name: true, phone: true, email: true, city: true, requirement: true,
            message: true, status: true, spamScore: true, createdAt: true, respondedAt: true, internalNotes: true,
          },
        }),
        tx.contactSubmission.count({ where }),
      ]);
      return { items, total };
    });

    noStore(reply);
    return paginate(result.items, result.total, query.page, query.pageSize);
  });

  app.patch<{ Params: { id: string } }>(
    '/leads/:id',
    { preHandler: app.requirePermission('lead:update') },
    async (request, reply) => {
      const { id } = parse(idParam, request.params);
      const input = parse(updateLeadSchema, request.body);

      const lead = await request.db(async (tx) => {
        const before = await tx.contactSubmission.findUnique({ where: { id }, select: { id: true, status: true } });
        if (!before) throw errors.notFound('lead');

        const record = await tx.contactSubmission.update({
          where: { id },
          data: {
            status: input.status,
            internalNotes: input.internalNotes ?? undefined,
            assignedToUserId: request.auth?.userId ?? null,
            ...(input.status === 'CONTACTED' ? { respondedAt: new Date() } : {}),
          },
          select: { id: true, status: true },
        });

        await recordAudit(tx, actorFrom(request), {
          action: 'LEAD_UPDATED',
          entity: 'contact_submission',
          entityId: id,
          previousValue: { status: before.status },
          newValue: { status: record.status },
        });

        return record;
      });

      noStore(reply);
      return lead;
    },
  );
}
