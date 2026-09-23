import { BRAND, brandedProductName } from '@polyfix/brand';

/**
 * Seed catalogue.
 *
 * Product copy here is deliberately factual — material, construction, firmness,
 * warranty term. No superlatives, no market-position claims. The business can edit
 * every word of it from the admin CMS without touching code; this file only
 * establishes a sensible starting point.
 */

export interface SeedVariant {
  sizeLabel: string;
  widthIn: number;
  lengthIn: number;
  heightIn: number;
  mrp: number;
  sortOrder: number;
}

export interface SeedProduct {
  slug: string;
  name: string;
  skuPrefix: string;
  category: string;
  tagline: string;
  shortDescription: string;
  description: string;
  comfortLevel: string;
  firmnessScore: number;
  materials: string[];
  features: string[];
  specifications: Record<string, string>;
  careInstructions: string;
  warrantyYears: number;
  trialNights: number | null;
  isFeatured: boolean;
  sortOrder: number;
  website: {
    headline: string;
    subheadline: string;
    highlights: { title: string; body: string }[];
    gallery: { src: string; alt: string; width: number; height: number }[];
  };
  seo: {
    title: string;
    description: string;
    keywords: string[];
  };
  faqs: { question: string; answer: string }[];
}

const SIZES: SeedVariant[] = [
  { sizeLabel: 'Single 72 x 36', widthIn: 36, lengthIn: 72, heightIn: 6, mrp: 0, sortOrder: 10 },
  { sizeLabel: 'Single 75 x 36', widthIn: 36, lengthIn: 75, heightIn: 6, mrp: 0, sortOrder: 20 },
  { sizeLabel: 'Double 72 x 48', widthIn: 48, lengthIn: 72, heightIn: 6, mrp: 0, sortOrder: 30 },
  { sizeLabel: 'Queen 78 x 60', widthIn: 60, lengthIn: 78, heightIn: 8, mrp: 0, sortOrder: 40 },
  { sizeLabel: 'King 78 x 72', widthIn: 72, lengthIn: 78, heightIn: 8, mrp: 0, sortOrder: 50 },
];

/** Price ladder applied to the base price of each product. */
const SIZE_MULTIPLIER = [1, 1.06, 1.34, 1.72, 2.02];

export function variantsFor(basePrice: number): SeedVariant[] {
  return SIZES.map((size, index) => ({
    ...size,
    mrp: Math.round((basePrice * (SIZE_MULTIPLIER[index] ?? 1)) / 50) * 50,
  }));
}

export const PRODUCTS: SeedProduct[] = [
  {
    slug: 'aurea-hybrid',
    name: brandedProductName('Aurea Hybrid'),
    skuPrefix: 'AUR',
    category: 'Hybrid',
    tagline: 'Pocketed springs under high-density memory foam',
    shortDescription:
      'A pocketed spring core paired with a memory foam comfort layer, for support that moves with you and a surface that relieves pressure.',
    description:
      'Aurea Hybrid places individually pocketed steel springs beneath a 40mm memory foam comfort layer. Each spring compresses on its own, so weight is carried where it falls and movement on one side of the bed is far less noticeable on the other. The knitted cotton-blend cover is quilted over a soft foam layer to keep the sleeping surface breathable.',
    comfortLevel: 'Medium-firm',
    firmnessScore: 6,
    materials: [
      'Individually pocketed tempered steel springs',
      '40mm high-density memory foam',
      '25mm quilted soft foam layer',
      'Knitted cotton-blend cover',
    ],
    features: [
      'Motion isolation from pocketed spring construction',
      'Zoned support across shoulder, lumbar and hip',
      'Reinforced perimeter for consistent edge support',
      'Breathable knitted cover',
    ],
    specifications: {
      Construction: 'Pocketed spring with memory foam comfort layer',
      'Spring count (Queen)': '~720 pockets',
      'Foam density': '50 kg/m³ memory foam',
      'Cover fabric': 'Knitted cotton blend, quilted',
      'Available heights': '6 inch and 8 inch',
    },
    careInstructions:
      'Rotate head to foot every three months. Use a breathable mattress protector. Do not fold, roll or dry clean. Vacuum the surface with an upholstery attachment as needed.',
    warrantyYears: 10,
    trialNights: null,
    isFeatured: true,
    sortOrder: 10,
    website: {
      headline: 'Support that adapts, night after night',
      subheadline: 'Pocketed springs carry weight independently. Memory foam softens the contact.',
      highlights: [
        { title: 'Independent springs', body: 'Each pocket compresses alone, so a partner turning over is far less disruptive.' },
        { title: 'Pressure relief', body: 'A 40mm memory foam layer spreads load across the shoulder and hip.' },
        { title: 'Edge to edge', body: 'A reinforced perimeter keeps the usable surface the full width of the bed.' },
      ],
      gallery: [
        { src: '/images/products/aurea-hybrid-1.svg', alt: `${BRAND.shortName} Aurea Hybrid mattress shown on a wooden bed frame`, width: 1200, height: 900 },
        { src: '/images/products/aurea-hybrid-2.svg', alt: 'Cutaway view of the Aurea Hybrid pocketed spring and memory foam layers', width: 1200, height: 900 },
      ],
    },
    seo: {
      title: `Aurea Hybrid Mattress — Pocket Spring & Memory Foam | ${BRAND.name}`,
      description:
        `${BRAND.shortName} Aurea Hybrid combines pocketed springs with a memory foam comfort layer for medium-firm support and motion isolation. 10 year warranty. Five sizes.`,
      keywords: ['hybrid mattress', 'pocket spring mattress', 'memory foam mattress', 'medium firm mattress'],
    },
    faqs: [
      {
        question: 'Is the Aurea Hybrid suitable for back sleepers?',
        answer:
          'Its medium-firm rating suits back and combination sleepers, who generally want the lumbar supported without the hips sinking. Side sleepers who prefer a softer surface often find the Serenity Memory Foam a better match.',
      },
      {
        question: 'Will I feel my partner moving?',
        answer:
          'Pocketed springs compress individually rather than as a connected unit, so movement transfers far less than in a Bonnell spring mattress. Some transfer is still normal.',
      },
    ],
  },
  {
    slug: 'serenity-memory-foam',
    name: brandedProductName('Serenity Memory Foam'),
    skuPrefix: 'SER',
    category: 'Memory Foam',
    tagline: 'A contouring surface over a high-resilience base',
    shortDescription:
      'Memory foam over a high-resilience support base — a softer, closely contouring surface for side sleepers and anyone who wants pressure relief at the shoulder and hip.',
    description:
      'Serenity layers 50mm of memory foam over a high-resilience polyurethane support core. The memory foam responds to body heat and weight, so the surface follows the shoulder and hip rather than pushing back against them, while the base layer keeps the spine from dipping. The removable cover is knitted for airflow.',
    comfortLevel: 'Medium-soft',
    firmnessScore: 4,
    materials: [
      '50mm open-cell memory foam',
      '125mm high-resilience support foam',
      'Knitted breathable cover, removable',
    ],
    features: [
      'Close contouring at shoulder and hip',
      'Open-cell foam for improved airflow',
      'Removable, washable cover',
      'Near-silent in use',
    ],
    specifications: {
      Construction: 'Memory foam over high-resilience base',
      'Comfort layer': '50mm memory foam, 50 kg/m³',
      'Support core': '125mm high-resilience foam, 32 kg/m³',
      'Cover fabric': 'Knitted polyester blend, removable',
      'Available heights': '6 inch and 8 inch',
    },
    careInstructions:
      'Rotate head to foot every three months. Do not flip — the comfort layer is on one side only. Cover may be removed and machine washed cold.',
    warrantyYears: 10,
    trialNights: null,
    isFeatured: true,
    sortOrder: 20,
    website: {
      headline: 'Softer where it should be, supported where it matters',
      subheadline: 'Memory foam follows your shape. The base layer keeps your spine level.',
      highlights: [
        { title: 'Pressure relief', body: 'The comfort layer spreads load away from the shoulder and hip.' },
        { title: 'Quiet nights', body: 'An all-foam build has no springs, so it makes no noise as you move.' },
        { title: 'Washable cover', body: 'The knitted cover unzips and goes in the machine.' },
      ],
      gallery: [
        { src: '/images/products/serenity-1.svg', alt: `${BRAND.shortName} Serenity Memory Foam mattress in a bedroom setting`, width: 1200, height: 900 },
        { src: '/images/products/serenity-2.svg', alt: 'Layer diagram of the Serenity memory foam and support core', width: 1200, height: 900 },
      ],
    },
    seo: {
      title: `Serenity Memory Foam Mattress — Pressure Relief | ${BRAND.name}`,
      description:
        `${BRAND.shortName} Serenity layers open-cell memory foam over a high-resilience core for medium-soft pressure relief. Removable washable cover. 10 year warranty.`,
      keywords: ['memory foam mattress', 'soft mattress', 'pressure relief mattress', 'mattress for side sleepers'],
    },
    faqs: [
      {
        question: 'Does memory foam sleep hot?',
        answer:
          'Serenity uses open-cell memory foam and a knitted cover to move air more freely than closed-cell foam. Anyone who sleeps particularly warm may still prefer the Aurea Hybrid, where the spring layer leaves more open space for airflow.',
      },
      {
        question: 'Can I flip it over?',
        answer:
          'No. The comfort layer is on one face only. Rotate it head to foot every three months instead.',
      },
    ],
  },
  {
    slug: 'orthocore-support',
    name: brandedProductName('Orthocore Support'),
    skuPrefix: 'ORT',
    category: 'Orthopaedic',
    tagline: 'A firm, high-density foam core',
    shortDescription:
      'A firm high-density foam mattress for people who want a flat, well-supported surface with minimal sink.',
    description:
      'Orthocore is a single high-density foam core with a thin quilted comfort layer. The result is a firm, level surface with very little contouring — the choice of people who find softer mattresses leave their back unsupported. The quilted cover adds enough cushioning to keep the surface comfortable without changing the firmness underneath.',
    comfortLevel: 'Firm',
    firmnessScore: 8,
    materials: ['150mm high-density support foam, 40 kg/m³', '20mm quilted comfort layer', 'Woven damask cover'],
    features: [
      'Firm, level sleeping surface',
      'High-density core resists body impressions',
      'Suitable for use on a slatted or solid base',
      'Low-profile 6 inch build',
    ],
    specifications: {
      Construction: 'Single high-density foam core',
      'Core density': '40 kg/m³',
      'Comfort layer': '20mm quilted foam',
      'Cover fabric': 'Woven damask',
      'Available heights': '6 inch',
    },
    careInstructions:
      'Rotate head to foot every three months. Use on a flat, well-supported base. Do not fold or roll.',
    warrantyYears: 8,
    trialNights: null,
    isFeatured: true,
    sortOrder: 30,
    website: {
      headline: 'Firm support, no sink',
      subheadline: 'A high-density core that stays flat under load.',
      highlights: [
        { title: 'Level surface', body: 'Minimal contouring keeps the spine in a straight line.' },
        { title: 'Density that lasts', body: 'A 40 kg/m³ core resists the impressions that lower-density foam develops.' },
        { title: 'Works on any base', body: 'Stable on slatted frames, divans and solid platforms alike.' },
      ],
      gallery: [
        { src: '/images/products/orthocore-1.svg', alt: `${BRAND.shortName} Orthocore Support firm mattress`, width: 1200, height: 900 },
      ],
    },
    seo: {
      title: `Orthocore Support Mattress — Firm High-Density Foam | ${BRAND.name}`,
      description:
        `${BRAND.shortName} Orthocore is a firm high-density foam mattress with a level sleeping surface and minimal sink. 8 year warranty. Five sizes.`,
      keywords: ['firm mattress', 'orthopaedic mattress', 'high density foam mattress', 'back support mattress'],
    },
    faqs: [
      {
        question: 'Is a firm mattress better for back pain?',
        answer:
          `It depends on the person and the cause. Firmer surfaces suit many back sleepers, while side sleepers often need more give at the shoulder. Speak to your doctor about your own situation, and try the mattress at a ${BRAND.shortName} dealer before deciding.`,
      },
    ],
  },
  {
    slug: 'latex-natura',
    name: brandedProductName('Latex Natura'),
    skuPrefix: 'LTX',
    category: 'Latex',
    tagline: 'Natural latex with an open, breathable structure',
    shortDescription:
      'A natural latex comfort layer over a high-resilience core: responsive rather than slow-sinking, and naturally breathable.',
    description:
      'Latex Natura uses a 50mm natural latex comfort layer above a high-resilience support core. Latex springs back quickly instead of holding an impression, so turning over takes less effort than on memory foam. Its pin-core structure leaves open channels through the layer, which helps the mattress run cooler.',
    comfortLevel: 'Medium',
    firmnessScore: 5,
    materials: ['50mm natural latex, pin-core', '125mm high-resilience support foam', 'Organic cotton-blend knitted cover'],
    features: [
      'Responsive surface that recovers quickly',
      'Pin-core structure for airflow',
      'Naturally resistant to dust mites',
      'Longest warranty term in the range',
    ],
    specifications: {
      Construction: 'Natural latex over high-resilience core',
      'Latex layer': '50mm, pin-core natural latex',
      'Support core': '125mm high-resilience foam',
      'Cover fabric': 'Organic cotton blend, knitted',
      'Available heights': '6 inch and 8 inch',
    },
    careInstructions:
      'Rotate head to foot every three months. Keep out of prolonged direct sunlight, which degrades latex. Use a breathable protector.',
    warrantyYears: 12,
    trialNights: null,
    isFeatured: false,
    sortOrder: 40,
    website: {
      headline: 'Responsive, breathable, built to last',
      subheadline: 'Natural latex recovers as soon as you move.',
      highlights: [
        { title: 'Quick recovery', body: 'Latex springs back rather than holding your outline.' },
        { title: 'Open structure', body: 'Pin-cores leave air channels through the comfort layer.' },
        { title: '12 year warranty', body: `The longest term ${BRAND.shortName} offers.` },
      ],
      gallery: [
        { src: '/images/products/latex-natura-1.svg', alt: `${BRAND.shortName} Latex Natura mattress with natural latex comfort layer`, width: 1200, height: 900 },
      ],
    },
    seo: {
      title: `Latex Natura Mattress — Natural Latex Comfort | ${BRAND.name}`,
      description:
        `${BRAND.shortName} Latex Natura pairs a natural pin-core latex comfort layer with a high-resilience core. Breathable, responsive, 12 year warranty.`,
      keywords: ['latex mattress', 'natural latex mattress', 'breathable mattress', 'medium firm mattress'],
    },
    faqs: [
      {
        question: 'How is latex different from memory foam?',
        answer:
          'Latex recovers its shape almost immediately, so it feels springy and is easier to move on. Memory foam recovers slowly and holds your outline for a few seconds, which some people find more cradling.',
      },
    ],
  },
  {
    slug: 'dual-comfort-flip',
    name: brandedProductName('Dual Comfort'),
    skuPrefix: 'DUL',
    category: 'Dual Firmness',
    tagline: 'Two firmness levels, one mattress',
    shortDescription:
      'Firm on one face, medium-soft on the other. Flip it over when your preference changes.',
    description:
      'Dual Comfort is built symmetrically: a firm high-density face on one side, a softer cushioned face on the other, sharing a single support core. It suits households still deciding what they prefer, guest rooms used by different people, and anyone whose comfort needs change over time.',
    comfortLevel: 'Firm / Medium-soft (flippable)',
    firmnessScore: 6,
    materials: ['High-density firm face, 40 kg/m³', 'Cushioned soft face with quilted layer', 'Shared support core', 'Woven cover'],
    features: [
      'Two usable sleeping surfaces',
      'One mattress suits two preferences',
      'Even wear when flipped regularly',
      'Practical for guest rooms',
    ],
    specifications: {
      Construction: 'Dual-face, flippable',
      'Firm face': 'High-density foam, 40 kg/m³',
      'Soft face': 'Quilted cushioned foam',
      'Cover fabric': 'Woven polyester blend',
      'Available heights': '6 inch',
    },
    careInstructions:
      'Flip and rotate every three months to spread wear across both faces. Do not fold or roll.',
    warrantyYears: 7,
    trialNights: null,
    isFeatured: false,
    sortOrder: 50,
    website: {
      headline: 'Change your mind without changing your mattress',
      subheadline: 'Firm on one side. Medium-soft on the other.',
      highlights: [
        { title: 'Two surfaces', body: 'Flip between a firm and a cushioned face whenever you like.' },
        { title: 'Even wear', body: 'Using both faces spreads compression across the whole mattress.' },
        { title: 'Guest-room ready', body: 'One mattress that suits a range of visitors.' },
      ],
      gallery: [
        { src: '/images/products/dual-comfort-1.svg', alt: `${BRAND.shortName} Dual Comfort flippable mattress showing both faces`, width: 1200, height: 900 },
      ],
    },
    seo: {
      title: `Dual Comfort Flippable Mattress — Firm and Soft | ${BRAND.name}`,
      description:
        `${BRAND.shortName} Dual Comfort has a firm face and a medium-soft face on one mattress. Flip to change firmness. 7 year warranty. Five sizes.`,
      keywords: ['dual comfort mattress', 'flippable mattress', 'reversible mattress', 'firm and soft mattress'],
    },
    faqs: [
      {
        question: 'How often should I flip it?',
        answer: 'Every three months, and rotate head to foot at the same time. That keeps wear even across both faces.',
      },
    ],
  },
];

export const PRODUCT_BASE_PRICE: Record<string, number> = {
  'aurea-hybrid': 18900,
  'serenity-memory-foam': 15400,
  'orthocore-support': 11200,
  'latex-natura': 24500,
  'dual-comfort-flip': 9800,
};
