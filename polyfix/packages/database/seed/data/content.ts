import { BRAND } from '@polyfix/brand';

/**
 * Seed website content.
 *
 * Everything here is editable from the admin CMS after install. No claim in
 * this file asserts market position, ranking or an unverifiable superlative —
 * if the business wants to say "India's largest", that belongs in the CMS once
 * there is evidence for it, not baked into source code.
 */

export interface SeedPage {
  slug: string;
  title: string;
  hero: Record<string, unknown>;
  sections: unknown[];
  seo: { title: string; description: string; keywords: string[]; priority: number; changefreq: string };
}

export const GLOBAL_SEO = {
  title: `${BRAND.name} — Premium Mattresses & Sleep Solutions`,
  description:
    `${BRAND.name} manufactures premium mattresses in hybrid, memory foam, latex and orthopaedic ranges. Every mattress carries a serial number you can verify online.`,
  keywords: [
    'premium mattresses',
    'mattress manufacturer',
    'mattress company',
    'mattress warranty',
    'mattress dealer',
    'sleep comfort',
  ],
  ogImageUrl: '/images/og/polyfix-default.svg',
};

export const PAGES: SeedPage[] = [
  {
    slug: 'home',
    title: `${BRAND.name} — Premium Mattresses & Sleep Solutions`,
    hero: {
      eyebrow: `${BRAND.shortName}`,
      heading: 'Mattresses built to be slept on for years',
      body: 'Hybrid, memory foam, latex and orthopaedic ranges, manufactured to a documented specification and traceable by serial number from the production line to your bedroom.',
      primaryCta: { label: 'Explore mattresses', href: '/mattresses' },
      secondaryCta: { label: 'Find a dealer', href: '/dealers' },
      tertiaryCta: { label: 'Check warranty', href: '/warranty' },
      image: { src: '/images/hero/polyfix-hero.svg', alt: `A ${BRAND.shortName} mattress in a calm, softly lit bedroom`, width: 1600, height: 1100 },
    },
    sections: [
      {
        type: 'benefits',
        heading: `Why ${BRAND.shortName}`,
        items: [
          { title: 'Premium comfort', body: 'Comfort layers specified by material and density, not by marketing adjective.', icon: 'comfort' },
          { title: 'Quality materials', body: 'High-density foam, tempered pocketed springs and natural latex, documented on every product page.', icon: 'materials' },
          { title: 'Long-lasting support', body: 'Core densities chosen to resist the body impressions that shorten a mattress life.', icon: 'support' },
          { title: 'Reliable warranty', body: 'Every unit carries a serial number. Verify the product and its warranty status in seconds.', icon: 'warranty' },
        ],
      },
      { type: 'featured-products', heading: `The ${BRAND.shortName} range`, body: 'Five constructions covering firm to medium-soft.' },
      {
        type: 'craftsmanship',
        heading: `How a ${BRAND.shortName} mattress is made`,
        body: 'Foam is cut to specification, comfort layers are bonded, covers are quilted and stitched, and each finished unit is inspected before it is assigned a serial number. That serial is the mattress identity for the rest of its life: batch, dispatch, dealer, sale and any warranty claim all attach to it.',
        steps: [
          { title: 'Material intake', body: 'Foam, spring units and fabric checked against specification on arrival.' },
          { title: 'Build', body: 'Layers cut, bonded and assembled to the documented construction for that model.' },
          { title: 'Quilting and finishing', body: 'Covers quilted, panels stitched and edges tape-bound.' },
          { title: 'Inspection and serialisation', body: `Each unit inspected, then assigned its permanent ${BRAND.shortName} serial number and QR label.` },
        ],
      },
      {
        type: 'verification',
        heading: 'Every mattress can be checked',
        body: 'Scan the QR label or type the serial number. You will see whether the product is genuine, what it is, when it was made and whether the warranty is still running. Nothing about the customer or the dealer is shown.',
        cta: { label: 'Verify your mattress', href: '/warranty' },
      },
      {
        type: 'dealers',
        heading: `Find a ${BRAND.shortName} dealer`,
        body: 'Our mattresses are sold through a network of appointed dealers. Find one near you, or apply to become one.',
        cta: { label: 'Dealer network', href: '/dealers' },
      },
      {
        type: 'closing-cta',
        heading: 'Not sure which mattress suits you?',
        body: 'Firmness is personal. Compare the range by construction and firmness rating, or visit a dealer and try them.',
        primaryCta: { label: 'Compare mattresses', href: '/mattresses' },
        secondaryCta: { label: 'Talk to us', href: '/contact' },
      },
    ],
    seo: {
      title: `${BRAND.name} — Premium Mattresses & Sleep Solutions`,
      description:
        `${BRAND.name} manufactures premium hybrid, memory foam, latex and orthopaedic mattresses. Serial-number traceability and online warranty verification on every unit.`,
      keywords: ['premium mattresses', 'mattress manufacturer', 'buy mattress', 'mattress warranty'],
      priority: 1.0,
      changefreq: 'weekly',
    },
  },
  {
    slug: 'about',
    title: `About ${BRAND.shortName}`,
    hero: {
      eyebrow: 'About',
      heading: 'A mattress company that can account for every unit it makes',
      body: `${BRAND.name} manufactures mattresses and sells them through appointed dealers. Every mattress is serialised at the end of the line, and that serial stays with it through dispatch, sale, warranty and any claim.`,
      image: { src: '/images/about/polyfix-factory.svg', alt: `${BRAND.shortName} production floor with mattress assembly in progress`, width: 1600, height: 1000 },
    },
    sections: [
      {
        type: 'text',
        heading: 'What we make',
        body: 'Five mattress constructions: pocketed-spring hybrid, memory foam, natural latex, high-density orthopaedic and a flippable dual-firmness model. Each is specified by material, density and firmness rating, and each is offered in five standard sizes.',
      },
      {
        type: 'text',
        heading: 'How we sell',
        body: 'Through appointed dealers rather than direct. A dealer holds stock, records the sale against the mattress serial number, and is the first point of contact if a warranty question comes up. That keeps a named business accountable for the mattress after it leaves us.',
      },
      {
        type: 'values',
        heading: 'What we hold ourselves to',
        items: [
          { title: 'Specify it, then publish it', body: 'Material and density appear on the product page. If it is not specified, we do not claim it.' },
          { title: 'Traceability by default', body: 'Serial number, manufacturing batch, dispatch, dealer and sale are all linked on one record.' },
          { title: 'Warranty you can check yourself', body: 'Status is verifiable online without contacting anyone.' },
        ],
      },
    ],
    seo: {
      title: `About ${BRAND.shortName} — Mattress Manufacturer`,
      description:
        `${BRAND.name} manufactures premium mattresses and sells through appointed dealers. Every unit is serialised and traceable from production to sale.`,
      keywords: ['mattress manufacturer', 'mattress company', 'about polyfix'],
      priority: 0.7,
      changefreq: 'monthly',
    },
  },
  {
    slug: 'why-polyfix',
    title: `Why ${BRAND.shortName}`,
    hero: {
      eyebrow: `Why ${BRAND.shortName}`,
      heading: 'Four things we do differently',
      body: 'Not slogans — things you can check.',
    },
    sections: [
      {
        type: 'reasons',
        items: [
          {
            title: 'Published specifications',
            body: 'Foam density in kg/m³, spring count, layer thickness and cover fabric are listed on every product page. You can compare them against any other mattress.',
          },
          {
            title: 'Serial-number traceability',
            body: `Each mattress is assigned a permanent ${BRAND.shortName} serial at the end of the line. Manufacturing batch, dispatch, dealer, sale date and warranty all attach to it.`,
          },
          {
            title: 'Warranty you can verify without asking',
            body: 'Scan the QR label or enter the serial. The result shows product, manufacturing date and warranty status. No login, no phone call.',
          },
          {
            title: 'An accountable dealer',
            body: 'Every sale is recorded against a named dealer, so there is always a business that can answer for the mattress.',
          },
        ],
      },
    ],
    seo: {
      title: `Why Choose ${BRAND.name} Mattresses`,
      description:
        `Published material specifications, serial-number traceability, online warranty verification and an accountable dealer behind every ${BRAND.shortName} mattress.`,
      keywords: ['why polyfix', 'mattress quality', 'mattress specifications'],
      priority: 0.6,
      changefreq: 'monthly',
    },
  },
  {
    slug: 'warranty',
    title: 'Warranty & Product Verification',
    hero: {
      eyebrow: 'Warranty',
      heading: `Verify your ${BRAND.shortName} mattress`,
      body: 'Scan the QR code on the label, or type the serial number printed beside it.',
    },
    sections: [
      {
        type: 'verify-form',
        heading: 'Check a serial number',
        body: 'The serial starts with CLF and is printed on the law label sewn to the side of the mattress.',
      },
      {
        type: 'text',
        heading: 'What the warranty covers',
        body: 'Manufacturing defects in materials and workmanship: foam that loses height beyond the stated tolerance under normal use, spring failure, stitching or seam separation not caused by misuse, and covers that split at the seam. The term runs from the date of sale recorded by the dealer.',
      },
      {
        type: 'text',
        heading: 'What it does not cover',
        body: `Normal softening and settling, comfort preference, stains, burns, tears, damage from an unsuitable or unsupportive base, damage in transit after delivery, removal of the law label, and any mattress bought from someone other than an appointed ${BRAND.shortName} dealer.`,
      },
      {
        type: 'steps',
        heading: 'How to raise a claim',
        items: [
          { title: 'Contact your dealer', body: 'Your selling dealer raises the claim against your mattress serial number.' },
          { title: 'Photographs', body: 'The dealer uploads photographs showing the issue and the law label.' },
          { title: 'Review', body: `The ${BRAND.shortName} warranty team reviews the claim, the mattress record and the photographs.` },
          { title: 'Outcome', body: 'You are told the decision and, if the claim is approved, a replacement is issued and linked to the original mattress record.' },
        ],
      },
    ],
    seo: {
      title: `Mattress Warranty & Serial Number Verification | ${BRAND.shortName}`,
      description:
        `Verify a ${BRAND.shortName} mattress by QR code or serial number. See product, manufacturing date and warranty status, and learn how to raise a warranty claim.`,
      keywords: ['mattress warranty', 'verify mattress', 'mattress serial number', 'warranty claim'],
      priority: 0.9,
      changefreq: 'monthly',
    },
  },
  {
    slug: 'dealers',
    title: 'Dealer Network',
    hero: {
      eyebrow: 'Dealer network',
      heading: `Find a ${BRAND.shortName} dealer`,
      body: 'Our mattresses are sold through appointed dealers who hold stock and handle warranty support.',
    },
    sections: [
      { type: 'dealer-locator', heading: 'Dealers near you' },
      {
        type: 'become-dealer',
        heading: `Become a ${BRAND.shortName} dealer`,
        body: `If you run a furniture or bedding retail business and want to carry ${BRAND.shortName}, send us your details and our team will get in touch.`,
      },
    ],
    seo: {
      title: `${BRAND.name} Dealers — Find a Mattress Showroom Near You`,
      description:
        `Find an appointed ${BRAND.shortName} mattress dealer or showroom near you, or apply to become a ${BRAND.shortName} dealer.`,
      keywords: ['mattress dealer', 'mattress showroom', 'become a mattress dealer', 'mattress dealership'],
      priority: 0.8,
      changefreq: 'weekly',
    },
  },
  {
    slug: 'contact',
    title: `Contact ${BRAND.name}`,
    hero: {
      eyebrow: 'Contact',
      heading: `Talk to ${BRAND.shortName}`,
      body: 'Product questions, dealership enquiries and warranty support.',
    },
    sections: [{ type: 'contact-form' }, { type: 'contact-details' }],
    seo: {
      title: `Contact ${BRAND.name} — Mattress Enquiries & Support`,
      description:
        `Contact ${BRAND.name} for product questions, dealership enquiries or warranty support.`,
      keywords: ['contact polyfix', 'mattress enquiry', 'mattress support'],
      priority: 0.6,
      changefreq: 'yearly',
    },
  },
];

export const GENERAL_FAQS = [
  {
    question: `How do I know a mattress is a genuine ${BRAND.shortName} product?`,
    answer:
      `Every ${BRAND.shortName} mattress carries a law label with a serial number beginning CLF and a QR code. Enter the serial or scan the code on the warranty page. If the serial is not in our records, the product did not come from our production line.`,
    category: 'authenticity',
    sortOrder: 10,
  },
  {
    question: 'Where is the serial number printed?',
    answer:
      'On the law label stitched to the side panel of the mattress, near the foot end. Do not remove this label — removing it makes the warranty unverifiable.',
    category: 'authenticity',
    sortOrder: 20,
  },
  {
    question: 'When does my warranty start?',
    answer:
      'On the date of sale recorded by your dealer, not the manufacturing date. You can see both on the verification page.',
    category: 'warranty',
    sortOrder: 30,
  },
  {
    question: 'Which firmness should I choose?',
    answer:
      'As a rough guide: side sleepers usually prefer medium-soft to medium, back sleepers medium-firm, and front sleepers firm. Firmness is personal, so try the range at a dealer before deciding.',
    category: 'buying',
    sortOrder: 40,
  },
  {
    question: 'How long does a mattress last?',
    answer:
      'That depends on construction, use and the base it sits on. Rotating head to foot every three months and using a supportive base both extend usable life. The warranty term for each model is listed on its product page.',
    category: 'buying',
    sortOrder: 50,
  },
  {
    question: 'Do you sell directly to customers?',
    answer:
      `No. ${BRAND.shortName} sells through appointed dealers, who hold stock, record your sale against the mattress serial number and handle warranty support.`,
    category: 'buying',
    sortOrder: 60,
  },
  {
    question: 'What support does a mattress need underneath it?',
    answer:
      'A flat, rigid base or slats no more than 75mm apart. An unsupportive or sagging base will make any mattress feel wrong and is not covered by the warranty.',
    category: 'care',
    sortOrder: 70,
  },
  {
    question: `Can I become a ${BRAND.shortName} dealer?`,
    answer:
      'Yes. Submit the dealership form on the dealer network page with your business details. Our team reviews every application and will contact you.',
    category: 'dealership',
    sortOrder: 80,
  },
];

export const SYSTEM_SETTINGS = [
  { key: 'company.legal_name', value: BRAND.legalName, category: 'company', description: 'Registered business name shown on documents' },
  { key: 'company.support_email', value: '', category: 'company', description: 'Public support email address. Blank hides it from the website footer.' },
  { key: 'company.support_phone', value: '', category: 'company', description: 'Public support phone number. Blank hides it from the website footer.' },
  { key: 'company.address', value: '', category: 'company', description: 'Registered address shown in the website footer. Blank hides it.' },
  { key: 'company.gst_number', value: '', category: 'company', description: 'Company GST number, shown on invoices when set' },
  { key: 'warranty.terms_version', value: 'v1.0', category: 'warranty', description: 'Version of the warranty terms applied to new sales' },
  { key: 'warranty.claim_window_days', value: 0, category: 'warranty', description: 'Minimum days after sale before a claim may be raised. 0 disables the restriction.' },
  { key: 'risk.early_claim_days', value: 30, category: 'risk', description: 'Claims raised within this many days of sale are flagged for review' },
  { key: 'risk.dealer_claim_ratio_threshold', value: 0.15, category: 'risk', description: 'Dealer claim-to-sale ratio above which claims are flagged' },
  { key: 'risk.repeat_customer_claim_threshold', value: 2, category: 'risk', description: 'Number of prior claims from the same customer that raises a flag' },
  { key: 'seo.sitemap_enabled', value: true, category: 'seo', description: 'Generate and serve /sitemap.xml' },
  { key: 'seo.robots_allow_indexing', value: true, category: 'seo', description: 'Allow search engines to index the public website' },
  { key: 'website.dealer_locator_enabled', value: true, category: 'website', description: 'Show the dealer locator on the public website' },
  { key: 'website.contact_form_enabled', value: true, category: 'website', description: 'Accept public contact form submissions' },
  { key: 'website.dealer_application_enabled', value: true, category: 'website', description: 'Accept public dealership applications' },
  { key: 'social.instagram', value: '', category: 'social', description: 'Instagram profile URL, blank to hide' },
  { key: 'social.facebook', value: '', category: 'social', description: 'Facebook page URL, blank to hide' },
  { key: 'social.linkedin', value: '', category: 'social', description: 'LinkedIn page URL, blank to hide' },
  { key: 'social.youtube', value: '', category: 'social', description: 'YouTube channel URL, blank to hide' },
];
