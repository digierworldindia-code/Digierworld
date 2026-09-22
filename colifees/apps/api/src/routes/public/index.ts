/**
 * Public endpoints.
 *
 * Everything under /public is reachable by anonymous internet traffic, so each
 * route follows the same three rules:
 *
 *   1. input is parsed against a schema before anything else happens
 *   2. output is assembled from an explicit allow-list of fields
 *   3. writes are rate limited and carry a honeypot check
 *
 * The public website's *server* calls these endpoints during rendering. The
 * visitor's browser never does, and no route here can reach a private record.
 */
import type { FastifyInstance } from 'fastify';
import { createHash } from 'node:crypto';
import { getConfig } from '@colifees/config';
import {
  verifyMattressSchema,
  contactSubmissionSchema,
  dealerApplicationSchema,
  dealerLocatorQuery,
  slug as slugSchema,
} from '@colifees/validation';
import { parse, publicCache, noStore } from '../../lib/http.js';
import { errors } from '../../lib/errors.js';
import { securityLog } from '../../lib/logger.js';
import { verifyMattress } from '../../services/verification.service.js';
import { recordAudit } from '../../services/audit.js';

export async function registerPublicRoutes(app: FastifyInstance): Promise<void> {
  const config = getConfig();

  const hashIp = (ip: string): string =>
    createHash('sha256').update(`${ip}:${config.env.SIGNING_SECRET}`).digest('hex');

  // =========================================================================
  // Warranty verification
  // =========================================================================
  app.post(
    '/verify',
    {
      config: { rateLimit: { max: config.env.RATE_LIMIT_VERIFY_PER_MINUTE, timeWindow: '1 minute' } },
    },
    async (request, reply) => {
      const input = parse(verifyMattressSchema, request.body);
      const result = await request.publicDb((tx) => verifyMattress(tx, input));
      // Not cached: a verification answer changes the moment a dealer records
      // the sale, and caching it at a CDN would show a stale warranty status.
      noStore(reply);
      return result;
    },
  );

  // =========================================================================
  // Published website content
  // =========================================================================
  app.get('/content/site', async (_request, reply) => {
    const data = await app.prisma.$transaction(async (tx) => {
      const [seo, settings, faqs] = await Promise.all([
        tx.seoMetadata.findFirst({ where: { scope: 'GLOBAL', entityKey: 'site' } }),
        tx.systemSetting.findMany({
          where: { isSecret: false, category: { in: ['company', 'social', 'website', 'seo'] } },
          select: { key: true, value: true },
        }),
        tx.faq.findMany({
          where: { isPublished: true, productId: null },
          orderBy: [{ category: 'asc' }, { sortOrder: 'asc' }],
          select: { id: true, question: true, answer: true, category: true },
        }),
      ]);
      return { seo, settings, faqs };
    });

    publicCache(reply, 300);
    return {
      seo: data.seo
        ? {
            title: data.seo.title,
            description: data.seo.description,
            keywords: data.seo.keywords,
            ogImageUrl: data.seo.ogImageUrl,
            robotsIndex: data.seo.robotsIndex,
            robotsFollow: data.seo.robotsFollow,
          }
        : null,
      // Only non-secret settings in publishable categories are exposed, and
      // each is returned as a flat key/value with no metadata attached.
      settings: Object.fromEntries(data.settings.map((s) => [s.key, s.value])),
      faqs: data.faqs,
    };
  });

  app.get<{ Params: { slug: string } }>('/content/pages/:slug', async (request, reply) => {
    const slug = parse(slugSchema, request.params.slug);
    const page = await app.prisma.websitePage.findFirst({
      where: { slug, status: 'PUBLISHED' },
      select: { slug: true, title: true, hero: true, sections: true, publishedAt: true },
    });
    if (!page) throw errors.notFound('page');

    const seo = await app.prisma.seoMetadata.findFirst({ where: { scope: 'PAGE', entityKey: slug } });

    publicCache(reply, 300);
    return { page, seo: seo ? publicSeo(seo) : null };
  });

  app.get('/content/products', async (_request, reply) => {
    const products = await app.prisma.product.findMany({
      where: { status: 'PUBLISHED', deletedAt: null, websiteProduct: { isPublished: true } },
      orderBy: [{ sortOrder: 'asc' }, { name: 'asc' }],
      select: {
        slug: true,
        name: true,
        category: true,
        tagline: true,
        shortDescription: true,
        comfortLevel: true,
        firmnessScore: true,
        warrantyYears: true,
        isFeatured: true,
        websiteProduct: { select: { headline: true, subheadline: true, gallery: true } },
        variants: {
          where: { status: 'PUBLISHED', deletedAt: null },
          orderBy: { sortOrder: 'asc' },
          select: { sizeLabel: true, widthIn: true, lengthIn: true, heightIn: true, mrp: true },
        },
      },
    });

    publicCache(reply, 300);
    return {
      products: products.map((p) => ({
        slug: p.slug,
        name: p.name,
        category: p.category,
        tagline: p.tagline,
        shortDescription: p.shortDescription,
        comfortLevel: p.comfortLevel,
        firmnessScore: p.firmnessScore,
        warrantyYears: p.warrantyYears,
        isFeatured: p.isFeatured,
        headline: p.websiteProduct?.headline ?? null,
        subheadline: p.websiteProduct?.subheadline ?? null,
        gallery: p.websiteProduct?.gallery ?? [],
        fromPrice: p.variants.length > 0 ? Number(p.variants[0]!.mrp) : null,
        sizes: p.variants.map((v) => ({
          label: v.sizeLabel,
          widthIn: v.widthIn,
          lengthIn: v.lengthIn,
          heightIn: v.heightIn,
          mrp: Number(v.mrp),
        })),
      })),
    };
  });

  app.get<{ Params: { slug: string } }>('/content/products/:slug', async (request, reply) => {
    const slug = parse(slugSchema, request.params.slug);
    const product = await app.prisma.product.findFirst({
      where: { slug, status: 'PUBLISHED', deletedAt: null },
      select: {
        slug: true,
        name: true,
        category: true,
        tagline: true,
        shortDescription: true,
        description: true,
        comfortLevel: true,
        firmnessScore: true,
        materials: true,
        features: true,
        specifications: true,
        careInstructions: true,
        warrantyYears: true,
        trialNights: true,
        websiteProduct: { select: { headline: true, subheadline: true, bodyHtml: true, gallery: true, highlights: true, isPublished: true } },
        variants: {
          where: { status: 'PUBLISHED', deletedAt: null },
          orderBy: { sortOrder: 'asc' },
          select: { sku: true, sizeLabel: true, widthIn: true, lengthIn: true, heightIn: true, mrp: true },
        },
        faqs: {
          where: { isPublished: true },
          orderBy: { sortOrder: 'asc' },
          select: { question: true, answer: true },
        },
      },
    });

    if (!product || !product.websiteProduct?.isPublished) throw errors.notFound('product');

    const seo = await app.prisma.seoMetadata.findFirst({ where: { scope: 'PRODUCT', entityKey: slug } });

    publicCache(reply, 300);
    return {
      product: {
        slug: product.slug,
        name: product.name,
        category: product.category,
        tagline: product.tagline,
        shortDescription: product.shortDescription,
        description: product.description,
        comfortLevel: product.comfortLevel,
        firmnessScore: product.firmnessScore,
        materials: product.materials,
        features: product.features,
        specifications: product.specifications,
        careInstructions: product.careInstructions,
        warrantyYears: product.warrantyYears,
        trialNights: product.trialNights,
        headline: product.websiteProduct.headline,
        subheadline: product.websiteProduct.subheadline,
        bodyHtml: product.websiteProduct.bodyHtml,
        gallery: product.websiteProduct.gallery,
        highlights: product.websiteProduct.highlights,
        sizes: product.variants.map((v) => ({
          sku: v.sku,
          label: v.sizeLabel,
          widthIn: v.widthIn,
          lengthIn: v.lengthIn,
          heightIn: v.heightIn,
          mrp: Number(v.mrp),
        })),
        faqs: product.faqs,
      },
      seo: seo ? publicSeo(seo) : null,
    };
  });

  // =========================================================================
  // Dealer locator — only dealers who have opted in are listed, and only with
  // the fields a customer needs to visit them.
  // =========================================================================
  app.get('/dealers', async (request, reply) => {
    const query = parse(dealerLocatorQuery, request.query);
    const dealers = await app.prisma.$transaction(async (tx) => {
      await tx.$executeRaw`SELECT set_config('app.dealer_id', 'ALL', true)`;
      return tx.dealer.findMany({
        where: {
          status: 'ACTIVE',
          publicListed: true,
          deletedAt: null,
          ...(query.state ? { state: { equals: query.state, mode: 'insensitive' } } : {}),
          ...(query.city ? { city: { equals: query.city, mode: 'insensitive' } } : {}),
          ...(query.pincode ? { pincode: query.pincode } : {}),
        },
        orderBy: [{ state: 'asc' }, { city: 'asc' }, { businessName: 'asc' }],
        take: query.limit,
        select: {
          businessName: true,
          addressLine1: true,
          addressLine2: true,
          city: true,
          state: true,
          pincode: true,
          phone: true,
          isShowroom: true,
          latitude: true,
          longitude: true,
        },
      });
    });

    publicCache(reply, 600);
    return {
      dealers: dealers.map((d) => ({
        businessName: d.businessName,
        address: [d.addressLine1, d.addressLine2].filter(Boolean).join(', '),
        city: d.city,
        state: d.state,
        pincode: d.pincode,
        phone: d.phone,
        isShowroom: d.isShowroom,
        latitude: d.latitude ? Number(d.latitude) : null,
        longitude: d.longitude ? Number(d.longitude) : null,
      })),
    };
  });

  app.get('/dealers/regions', async (_request, reply) => {
    const rows = await app.prisma.$transaction(async (tx) => {
      await tx.$executeRaw`SELECT set_config('app.dealer_id', 'ALL', true)`;
      return tx.dealer.groupBy({
        by: ['state', 'city'],
        where: { status: 'ACTIVE', publicListed: true, deletedAt: null },
        _count: { _all: true },
        orderBy: [{ state: 'asc' }, { city: 'asc' }],
      });
    });
    publicCache(reply, 900);
    return { regions: rows.map((r) => ({ state: r.state, city: r.city, dealers: r._count._all })) };
  });

  // =========================================================================
  // Sitemap source — only pages an administrator has marked indexable
  // =========================================================================
  app.get('/sitemap', async (_request, reply) => {
    const [pages, products] = await Promise.all([
      app.prisma.websitePage.findMany({
        where: { status: 'PUBLISHED' },
        select: { slug: true, updatedAt: true },
      }),
      app.prisma.product.findMany({
        where: { status: 'PUBLISHED', deletedAt: null, websiteProduct: { isPublished: true } },
        select: { slug: true, updatedAt: true },
      }),
    ]);

    const seo = await app.prisma.seoMetadata.findMany({
      where: { sitemapInclude: true, robotsIndex: true },
      select: { scope: true, entityKey: true, canonicalPath: true, sitemapPriority: true, sitemapChangefreq: true },
    });
    const seoByKey = new Map(seo.map((s) => [`${s.scope}:${s.entityKey}`, s]));

    const entries = [
      ...pages.map((p) => {
        const meta = seoByKey.get(`PAGE:${p.slug}`);
        return meta
          ? {
              path: meta.canonicalPath ?? (p.slug === 'home' ? '/' : `/${p.slug}`),
              lastModified: p.updatedAt.toISOString(),
              priority: Number(meta.sitemapPriority),
              changefreq: meta.sitemapChangefreq,
            }
          : null;
      }),
      ...products.map((p) => {
        const meta = seoByKey.get(`PRODUCT:${p.slug}`);
        return meta
          ? {
              path: meta.canonicalPath ?? `/mattresses/${p.slug}`,
              lastModified: p.updatedAt.toISOString(),
              priority: Number(meta.sitemapPriority),
              changefreq: meta.sitemapChangefreq,
            }
          : null;
      }),
    ].filter((e): e is NonNullable<typeof e> => e !== null);

    publicCache(reply, 3600);
    return { entries };
  });

  // =========================================================================
  // Website forms
  // =========================================================================
  app.post(
    '/leads',
    {
      config: { rateLimit: { max: config.env.RATE_LIMIT_PUBLIC_FORM_PER_HOUR, timeWindow: '1 hour' } },
    },
    async (request, reply) => {
      const input = parse(contactSubmissionSchema, request.body);
      const enabled = await settingEnabled(app, 'website.contact_form_enabled');
      if (!enabled) throw errors.businessRule('The contact form is currently closed. Please call us instead.');

      // Honeypot: a filled hidden field means a bot. Answer as if it worked so
      // the bot learns nothing, and record it with a spam score for review.
      const spamScore = scoreSpam(input.message, input.name, Boolean(input.website));

      await app.prisma.$transaction(async (tx) => {
        await tx.$executeRaw`SELECT set_config('app.dealer_id', 'ALL', true)`;
        const lead = await tx.contactSubmission.create({
          data: {
            name: input.name,
            phone: input.phone,
            email: input.email || null,
            city: input.city ?? null,
            requirement: input.requirement,
            message: input.message,
            ipHash: hashIp(request.ip),
            userAgent: typeof request.headers['user-agent'] === 'string' ? request.headers['user-agent'].slice(0, 400) : null,
            spamScore,
          },
          select: { id: true },
        });
        await recordAudit(
          tx,
          { ip: request.ip, requestId: request.id },
          { action: 'LEAD_SUBMITTED', entity: 'contact_submission', entityId: lead.id, newValue: { requirement: input.requirement, spamScore } },
        );
      });

      if (spamScore >= 50) securityLog('LEAD_FLAGGED_SPAM', { spamScore, requirement: input.requirement }, 'info');

      noStore(reply);
      return { received: true, message: 'Thank you. Our team will be in touch shortly.' };
    },
  );

  app.post(
    '/dealer-applications',
    {
      config: { rateLimit: { max: config.env.RATE_LIMIT_PUBLIC_FORM_PER_HOUR, timeWindow: '1 hour' } },
    },
    async (request, reply) => {
      const input = parse(dealerApplicationSchema, request.body);
      const enabled = await settingEnabled(app, 'website.dealer_application_enabled');
      if (!enabled) throw errors.businessRule('Dealership applications are closed at the moment.');

      const spamScore = scoreSpam(input.message ?? '', input.ownerName, Boolean(input.website));

      await app.prisma.$transaction(async (tx) => {
        await tx.$executeRaw`SELECT set_config('app.dealer_id', 'ALL', true)`;
        const application = await tx.dealerApplication.create({
          data: {
            businessName: input.businessName,
            ownerName: input.ownerName,
            mobile: input.mobile,
            email: input.email,
            city: input.city,
            state: input.state,
            address: input.address,
            gstNumber: input.gstNumber || null,
            hasExistingBusiness: input.hasExistingBusiness,
            existingBusinessDetails: input.existingBusinessDetails ?? null,
            message: input.message ?? null,
            ipHash: hashIp(request.ip),
            userAgent: typeof request.headers['user-agent'] === 'string' ? request.headers['user-agent'].slice(0, 400) : null,
            spamScore,
          },
          select: { id: true },
        });
        await recordAudit(
          tx,
          { ip: request.ip, requestId: request.id },
          { action: 'DEALER_APPLICATION_SUBMITTED', entity: 'dealer_application', entityId: application.id, newValue: { city: input.city, state: input.state } },
        );
      });

      noStore(reply);
      return {
        received: true,
        message: 'Thank you for your interest. Our dealer development team will review your application and contact you.',
      };
    },
  );
}

function publicSeo(seo: {
  title: string;
  description: string;
  canonicalPath: string | null;
  ogTitle: string | null;
  ogDescription: string | null;
  ogImageUrl: string | null;
  twitterCard: string;
  robotsIndex: boolean;
  robotsFollow: boolean;
  keywords: string[];
}) {
  return {
    title: seo.title,
    description: seo.description,
    canonicalPath: seo.canonicalPath,
    ogTitle: seo.ogTitle ?? seo.title,
    ogDescription: seo.ogDescription ?? seo.description,
    ogImageUrl: seo.ogImageUrl,
    twitterCard: seo.twitterCard,
    robotsIndex: seo.robotsIndex,
    robotsFollow: seo.robotsFollow,
    keywords: seo.keywords,
  };
}

async function settingEnabled(app: FastifyInstance, key: string): Promise<boolean> {
  const row = await app.prisma.systemSetting.findUnique({ where: { key }, select: { value: true } });
  return row ? row.value !== false : true;
}

/**
 * Cheap, explainable spam heuristics. Nothing is rejected on this basis — the
 * score routes a submission to the top or bottom of the review queue.
 */
function scoreSpam(message: string, name: string, honeypotFilled: boolean): number {
  let score = honeypotFilled ? 80 : 0;
  const linkCount = (message.match(/https?:\/\//gi) ?? []).length;
  score += Math.min(30, linkCount * 15);
  if (/\b(casino|crypto|loan|seo services|backlink|viagra)\b/i.test(message)) score += 30;
  if (message.length < 15) score += 10;
  if (/(.)\1{6,}/.test(message) || /(.)\1{6,}/.test(name)) score += 15;
  if (!/\s/.test(message.trim())) score += 10;
  return Math.min(100, score);
}
