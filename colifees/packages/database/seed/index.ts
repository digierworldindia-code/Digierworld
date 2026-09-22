/**
 * Database seed.
 *
 * Idempotent: safe to run repeatedly against the same database. It upserts
 * reference data (roles, permissions, catalogue, website content, settings) and
 * creates exactly one administrator if none exists.
 *
 *   pnpm db:seed                      reference data + one super admin
 *   SEED_DEMO=1 pnpm db:seed          also creates a demo warehouse, two
 *                                     dealers and a batch of mattresses so the
 *                                     lifecycle can be walked end to end
 *
 * The administrator password is never written in this file. Supply it as
 * SEED_ADMIN_PASSWORD, or let the script generate one and print it once.
 */
import { randomBytes } from 'node:crypto';
import { PrismaClient, Prisma } from '@prisma/client';
import {
  PERMISSIONS,
  ROLE_PERMISSIONS,
  ROLE_METADATA,
  ROLE_KEYS,
  hashPassword,
  checkPasswordPolicy,
  randomToken,
  blindIndex,
  encryptField,
  normalisePhone,
} from '@colifees/auth';
import { PRODUCTS, PRODUCT_BASE_PRICE, variantsFor } from './data/catalogue.js';
import { GLOBAL_SEO, PAGES, GENERAL_FAQS, SYSTEM_SETTINGS } from './data/content.js';

const prisma = new PrismaClient();

/** Reference data is staff-scope: '' means "no dealer restriction". */
async function asStaff<T>(fn: (tx: Prisma.TransactionClient) => Promise<T>): Promise<T> {
  return prisma.$transaction(
    async (tx) => {
      await tx.$executeRaw`SELECT set_config('app.dealer_id', 'ALL', true)`;
      await tx.$executeRaw`SELECT set_config('app.user_id', '', true)`;
      return fn(tx);
    },
    { timeout: 120_000, maxWait: 10_000 },
  );
}

function log(step: string, detail = ''): void {
  process.stdout.write(`  ${step.padEnd(34)} ${detail}\n`);
}

// ---------------------------------------------------------------------------
// Roles and permissions
// ---------------------------------------------------------------------------
async function seedRbac(tx: Prisma.TransactionClient): Promise<void> {
  for (const permission of PERMISSIONS) {
    await tx.permission.upsert({
      where: { key: permission.key },
      create: { key: permission.key, group: permission.group, description: permission.description },
      update: { group: permission.group, description: permission.description },
    });
  }
  log('permissions', `${PERMISSIONS.length} upserted`);

  for (const key of ROLE_KEYS) {
    const meta = ROLE_METADATA[key];
    const role = await tx.role.upsert({
      where: { key },
      create: { key, name: meta.name, description: meta.description, rank: meta.rank, isSystem: true },
      update: { name: meta.name, description: meta.description, rank: meta.rank },
    });

    const wanted = ROLE_PERMISSIONS[key];
    const permissionRows = await tx.permission.findMany({ where: { key: { in: [...wanted] } } });

    // Replace the grant set so removing a permission from the catalogue
    // actually revokes it rather than leaving a stale grant behind.
    await tx.rolePermission.deleteMany({
      where: { roleId: role.id, permissionId: { notIn: permissionRows.map((p) => p.id) } },
    });
    await tx.rolePermission.createMany({
      data: permissionRows.map((p) => ({ roleId: role.id, permissionId: p.id })),
      skipDuplicates: true,
    });
  }
  log('roles', `${ROLE_KEYS.length} upserted with permission grants`);
}

// ---------------------------------------------------------------------------
// First administrator
// ---------------------------------------------------------------------------
async function seedSuperAdmin(tx: Prisma.TransactionClient): Promise<void> {
  const email = (process.env.SEED_ADMIN_EMAIL ?? 'admin@colifees.com').toLowerCase().trim();
  const existing = await tx.user.findUnique({ where: { email } });
  if (existing) {
    log('super admin', `${email} already exists, left unchanged`);
    return;
  }

  const supplied = process.env.SEED_ADMIN_PASSWORD;
  const password = supplied ?? generateStrongPassword();

  if (supplied) {
    const check = checkPasswordPolicy(supplied, [email, 'colifees']);
    if (!check.ok) {
      throw new Error(`SEED_ADMIN_PASSWORD does not meet policy: ${check.problems.join('; ')}`);
    }
  }

  const role = await tx.role.findUniqueOrThrow({ where: { key: 'SUPER_ADMIN' } });
  const user = await tx.user.create({
    data: {
      email,
      fullName: process.env.SEED_ADMIN_NAME ?? 'COLIFEES Super Admin',
      passwordHash: await hashPassword(password),
      status: 'ACTIVE',
      // Whether the password was supplied or generated, the first sign-in
      // forces a change so the seeding value never remains in use.
      mustChangePassword: true,
      passwordChangedAt: new Date(),
    },
  });
  await tx.userRole.create({ data: { userId: user.id, roleId: role.id } });

  if (supplied) {
    log('super admin', `${email} created with the supplied password`);
  } else {
    process.stdout.write(
      [
        '',
        '  ====================================================================',
        '   FIRST ADMINISTRATOR CREATED',
        `   email    : ${email}`,
        `   password : ${password}`,
        '',
        '   This password is shown once and is not stored anywhere in plain',
        '   text. Sign in at the console; you will be required to change it',
        '   immediately. Enable two-factor authentication straight after.',
        '  ====================================================================',
        '',
      ].join('\n'),
    );
  }
}

function generateStrongPassword(): string {
  const alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
  const symbols = '!@#$%^&*-_=+';
  const bytes = randomBytes(24);
  let out = '';
  for (let i = 0; i < 20; i += 1) out += alphabet[bytes[i]! % alphabet.length];
  out += symbols[bytes[20]! % symbols.length];
  out += String(bytes[21]! % 10);
  return out;
}

// ---------------------------------------------------------------------------
// Catalogue and website content
// ---------------------------------------------------------------------------
async function seedCatalogue(tx: Prisma.TransactionClient): Promise<void> {
  for (const product of PRODUCTS) {
    const record = await tx.product.upsert({
      where: { slug: product.slug },
      create: {
        slug: product.slug,
        name: product.name,
        skuPrefix: product.skuPrefix,
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
        status: 'PUBLISHED',
        isFeatured: product.isFeatured,
        sortOrder: product.sortOrder,
        publishedAt: new Date(),
      },
      update: {
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
        isFeatured: product.isFeatured,
        sortOrder: product.sortOrder,
      },
    });

    const basePrice = PRODUCT_BASE_PRICE[product.slug] ?? 12000;
    for (const variant of variantsFor(basePrice)) {
      const sku = `${product.skuPrefix}-${variant.widthIn}X${variant.lengthIn}X${variant.heightIn}`;
      await tx.productVariant.upsert({
        where: { productId_sizeLabel: { productId: record.id, sizeLabel: variant.sizeLabel } },
        create: {
          productId: record.id,
          sku,
          sizeLabel: variant.sizeLabel,
          widthIn: variant.widthIn,
          lengthIn: variant.lengthIn,
          heightIn: variant.heightIn,
          mrp: new Prisma.Decimal(variant.mrp),
          status: 'PUBLISHED',
          sortOrder: variant.sortOrder,
        },
        update: { mrp: new Prisma.Decimal(variant.mrp), sku, status: 'PUBLISHED' },
      });
    }

    await tx.websiteProduct.upsert({
      where: { productId: record.id },
      create: {
        productId: record.id,
        headline: product.website.headline,
        subheadline: product.website.subheadline,
        gallery: product.website.gallery,
        highlights: product.website.highlights,
        isPublished: true,
        sortOrder: product.sortOrder,
      },
      update: {
        headline: product.website.headline,
        subheadline: product.website.subheadline,
        gallery: product.website.gallery,
        highlights: product.website.highlights,
        isPublished: true,
      },
    });

    await tx.seoMetadata.upsert({
      where: { scope_entityKey: { scope: 'PRODUCT', entityKey: product.slug } },
      create: {
        scope: 'PRODUCT',
        entityKey: product.slug,
        title: product.seo.title,
        description: product.seo.description,
        canonicalPath: `/mattresses/${product.slug}`,
        keywords: product.seo.keywords,
        sitemapPriority: new Prisma.Decimal(0.8),
        sitemapChangefreq: 'monthly',
      },
      update: {
        title: product.seo.title,
        description: product.seo.description,
        canonicalPath: `/mattresses/${product.slug}`,
        keywords: product.seo.keywords,
      },
    });

    let order = 10;
    for (const faq of product.faqs) {
      const existing = await tx.faq.findFirst({ where: { productId: record.id, question: faq.question } });
      if (existing) {
        await tx.faq.update({ where: { id: existing.id }, data: { answer: faq.answer } });
      } else {
        await tx.faq.create({
          data: {
            question: faq.question,
            answer: faq.answer,
            category: 'product',
            productId: record.id,
            sortOrder: order,
            isPublished: true,
          },
        });
      }
      order += 10;
    }
  }
  log('products', `${PRODUCTS.length} products with variants, copy, SEO and FAQs`);
}

async function seedWebsite(tx: Prisma.TransactionClient): Promise<void> {
  await tx.seoMetadata.upsert({
    where: { scope_entityKey: { scope: 'GLOBAL', entityKey: 'site' } },
    create: {
      scope: 'GLOBAL',
      entityKey: 'site',
      title: GLOBAL_SEO.title,
      description: GLOBAL_SEO.description,
      keywords: GLOBAL_SEO.keywords,
      ogImageUrl: GLOBAL_SEO.ogImageUrl,
      sitemapPriority: new Prisma.Decimal(1.0),
      sitemapChangefreq: 'weekly',
    },
    update: { title: GLOBAL_SEO.title, description: GLOBAL_SEO.description, keywords: GLOBAL_SEO.keywords },
  });

  for (const page of PAGES) {
    const record = await tx.websitePage.upsert({
      where: { slug: page.slug },
      create: {
        slug: page.slug,
        title: page.title,
        status: 'PUBLISHED',
        hero: page.hero as Prisma.InputJsonValue,
        sections: page.sections as Prisma.InputJsonValue,
        publishedAt: new Date(),
      },
      update: {
        title: page.title,
        hero: page.hero as Prisma.InputJsonValue,
        sections: page.sections as Prisma.InputJsonValue,
        status: 'PUBLISHED',
      },
    });

    await tx.seoMetadata.upsert({
      where: { scope_entityKey: { scope: 'PAGE', entityKey: page.slug } },
      create: {
        scope: 'PAGE',
        entityKey: page.slug,
        title: page.seo.title,
        description: page.seo.description,
        canonicalPath: page.slug === 'home' ? '/' : `/${page.slug}`,
        keywords: page.seo.keywords,
        sitemapPriority: new Prisma.Decimal(page.seo.priority),
        sitemapChangefreq: page.seo.changefreq,
      },
      update: {
        title: page.seo.title,
        description: page.seo.description,
        keywords: page.seo.keywords,
        sitemapPriority: new Prisma.Decimal(page.seo.priority),
      },
    });

    void record;
  }
  log('website pages', `${PAGES.length} pages with SEO records`);

  for (const faq of GENERAL_FAQS) {
    const existing = await tx.faq.findFirst({ where: { question: faq.question, productId: null } });
    if (existing) {
      await tx.faq.update({ where: { id: existing.id }, data: { answer: faq.answer, category: faq.category } });
    } else {
      await tx.faq.create({ data: { ...faq, isPublished: true } });
    }
  }
  log('faqs', `${GENERAL_FAQS.length} general FAQs`);

  for (const setting of SYSTEM_SETTINGS) {
    await tx.systemSetting.upsert({
      where: { key: setting.key },
      create: {
        key: setting.key,
        value: setting.value as Prisma.InputJsonValue,
        category: setting.category,
        description: setting.description,
      },
      // Existing values are left alone: re-seeding must not overwrite settings
      // an administrator has already tuned.
      update: { description: setting.description, category: setting.category },
    });
  }
  log('system settings', `${SYSTEM_SETTINGS.length} keys`);
}

// ---------------------------------------------------------------------------
// Optional demo operating data
// ---------------------------------------------------------------------------
async function seedDemo(tx: Prisma.TransactionClient): Promise<void> {
  const encryptionKey = Buffer.from(process.env.ENCRYPTION_KEY ?? '', 'base64');
  if (encryptionKey.length !== 32) {
    throw new Error('SEED_DEMO requires a valid 32-byte base64 ENCRYPTION_KEY');
  }
  const indexKey = process.env.SIGNING_SECRET ?? '';
  if (!indexKey) throw new Error('SEED_DEMO requires SIGNING_SECRET for blind indexes');

  const warehouse = await tx.warehouse.upsert({
    where: { code: 'WH-JAIPUR' },
    create: {
      code: 'WH-JAIPUR',
      name: 'COLIFEES Jaipur Plant',
      address: 'Plot 12, Industrial Area, Sitapura',
      city: 'Jaipur',
      state: 'Rajasthan',
      pincode: '302022',
    },
    update: {},
  });

  const dealerSpecs = [
    {
      businessName: 'Sunrise Furniture House',
      ownerName: 'R. Menon',
      email: 'dealer.sunrise@example.com',
      phone: '9876500001',
      city: 'Jaipur',
      state: 'Rajasthan',
      pincode: '302001',
      userEmail: 'sunrise.dealer@example.com',
    },
    {
      businessName: 'Comfort Zone Beds',
      ownerName: 'S. Iyer',
      email: 'dealer.comfortzone@example.com',
      phone: '9876500002',
      city: 'Kota',
      state: 'Rajasthan',
      pincode: '324001',
      userEmail: 'comfortzone.dealer@example.com',
    },
  ];

  const dealerRole = await tx.role.findUniqueOrThrow({ where: { key: 'DEALER' } });
  const demoPassword = process.env.SEED_DEMO_PASSWORD ?? generateStrongPassword();
  const demoHash = await hashPassword(demoPassword);
  const createdDealers: { id: string; code: string }[] = [];

  for (const spec of dealerSpecs) {
    // Matched on business name so a re-run updates rather than duplicates, and
    // the code itself always comes from the database sequence.
    const found = await tx.dealer.findFirst({
      where: { businessName: spec.businessName },
      select: { id: true, code: true },
    });

    const dealer = found
      ? await tx.dealer.update({
          where: { id: found.id },
          data: { status: 'ACTIVE', publicListed: true },
          select: { id: true, code: true },
        })
      : await tx.dealer.create({
          data: {
            code: (await tx.$queryRaw<{ code: string }[]>`SELECT colifees_next_dealer_code() AS code`)[0]!.code,
            businessName: spec.businessName,
            ownerName: spec.ownerName,
            email: spec.email,
            phone: spec.phone,
            addressLine1: 'Shop 4, Main Market Road',
            city: spec.city,
            state: spec.state,
            pincode: spec.pincode,
            status: 'ACTIVE',
            publicListed: true,
            onboardedAt: new Date(),
          },
          select: { id: true, code: true },
        });
    createdDealers.push({ id: dealer.id, code: dealer.code });

    const existingUser = await tx.user.findUnique({ where: { email: spec.userEmail } });
    if (!existingUser) {
      const user = await tx.user.create({
        data: {
          email: spec.userEmail,
          fullName: `${spec.ownerName} (${spec.businessName})`,
          passwordHash: demoHash,
          status: 'ACTIVE',
          mustChangePassword: true,
          passwordChangedAt: new Date(),
          phone: spec.phone,
        },
      });
      await tx.userRole.create({ data: { userId: user.id, roleId: dealerRole.id } });
      await tx.dealerUser.create({ data: { userId: user.id, dealerId: dealer.id, isPrimary: true } });
    }
  }
  log('demo dealers', `${createdDealers.map((d) => d.code).join(', ')}`);

  // A production batch of mattresses, serials issued by the database.
  const existingBatch = await tx.manufacturingBatch.findFirst({ where: { batchCode: { startsWith: 'BAT' } } });
  if (existingBatch) {
    log('demo batch', `${existingBatch.batchCode} already present, skipping`);
  } else {
    const [{ code: batchCode }] = await tx.$queryRaw<{ code: string }[]>`
      SELECT colifees_next_batch_code() AS code
    `;
    const batch = await tx.manufacturingBatch.create({
      data: {
        batchCode,
        warehouseId: warehouse.id,
        manufacturedOn: new Date(),
        plannedQuantity: 40,
        producedQuantity: 40,
        lineSupervisor: 'Demo Supervisor',
        qualityCheckedBy: 'Demo QC',
      },
    });

    const variants = await tx.productVariant.findMany({ take: 5, orderBy: { sku: 'asc' } });
    let created = 0;
    for (let i = 0; i < 40; i += 1) {
      const variant = variants[i % variants.length]!;
      const [{ serial }] = await tx.$queryRaw<{ serial: string }[]>`
        SELECT colifees_next_serial() AS serial
      `;
      await tx.mattress.create({
        data: {
          serialNumber: serial,
          qrToken: randomToken(24),
          productVariantId: variant.id,
          batchId: batch.id,
          currentStatus: 'MANUFACTURED',
          currentWarehouseId: warehouse.id,
          manufacturedAt: new Date(),
        },
      });
      created += 1;
    }
    log('demo mattresses', `${created} units in batch ${batchCode}`);
  }

  // A sample customer proves the encrypted-column + blind-index pattern works.
  const firstDealer = createdDealers[0]!;
  const samplePhone = '9811100011';
  const phoneHash = blindIndex(normalisePhone(samplePhone), indexKey);
  await tx.customer.upsert({
    where: { dealerId_phoneHash: { dealerId: firstDealer.id, phoneHash } },
    create: {
      dealerId: firstDealer.id,
      fullName: 'Demo Customer',
      phoneEncrypted: encryptField(samplePhone, encryptionKey),
      phoneHash,
      city: 'Jaipur',
      state: 'Rajasthan',
      pincode: '302001',
    },
    update: {},
  });

  if (!process.env.SEED_DEMO_PASSWORD) {
    process.stdout.write(
      [
        '',
        '  --------------------------------------------------------------------',
        '   DEMO DEALER LOGINS (development data only)',
        `   ${dealerSpecs.map((d) => d.userEmail).join('\n   ')}`,
        `   password : ${demoPassword}`,
        '   Each account must change this password at first sign-in.',
        '  --------------------------------------------------------------------',
        '',
      ].join('\n'),
    );
  }
}

// ---------------------------------------------------------------------------
async function main(): Promise<void> {
  process.stdout.write('\nCOLIFEES database seed\n----------------------\n');

  await asStaff(async (tx) => {
    await seedRbac(tx);
    await seedSuperAdmin(tx);
    await seedCatalogue(tx);
    await seedWebsite(tx);
  });

  if (process.env.SEED_DEMO === '1' || process.env.SEED_DEMO === 'true') {
    await asStaff(async (tx) => {
      await seedDemo(tx);
    });
  } else {
    log('demo data', 'skipped (set SEED_DEMO=1 to include)');
  }

  log(
    'next step',
    'run scripts/sql/04_sync_sequences.sql as the schema owner after any data import',
  );

  process.stdout.write('\nSeed complete.\n\n');
}

main()
  .catch((error: unknown) => {
    process.stderr.write(`\nSeed failed: ${error instanceof Error ? error.message : String(error)}\n\n`);
    process.exitCode = 1;
  })
  .finally(() => {
    void prisma.$disconnect();
  });
